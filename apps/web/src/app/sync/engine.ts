import {isAccountConnected, loadAccountSettings} from "../account-settings";
import {formatBaseId} from "../api/data-package";
import {getIdbTodoStore} from "../api/store-idb";
import {tagsListKey, todosFilterKey, type TodosFilter} from "../dp";
import {read, set} from "../../utils/dataprovider";
import type {DatasetInfo} from "../api/store";
import {
  bootstrapDatasetSync,
  createCloudDatasetWithBaseId,
  pullDatasetSync,
  pushDatasetSync,
} from "./cloud-client";
import {
  applyRemoteTag,
  applyRemoteTodo,
  normalizeSnapshotWithVersions,
} from "./merge";
import {
  countPendingMutations,
  getSyncState,
  listPendingMutations,
  markMutationsFailed,
  markMutationsInflight,
  removeMutations,
  resetFailedMutations,
  saveSyncState,
  type SyncState,
} from "./outbox";

export type SyncRunResult = {
  pushed: number;
  pulledTodos: number;
  pulledTags: number;
  bootstrapped: boolean;
  error?: string;
};

/** Debounce des syncs déclenchées par mutations locales. */
const AUTO_SYNC_DEBOUNCE_MS = 300;
/** Ne pas re-pull cloud plus souvent que ça (lecture UI / focus). */
const DEFAULT_SYNC_MAX_AGE_MS = 4_000;

let inflightSync: Promise<SyncRunResult> | null = null;
let autoSyncTimer: ReturnType<typeof setTimeout> | null = null;
let lastSyncStartedAt = 0;

async function readActiveLocalDataset(): Promise<DatasetInfo | null> {
  const datasets = await getIdbTodoStore().listDatasets();
  return datasets.find((dataset) => dataset.active) ?? null;
}

async function applyPullToLocal(
  baseId: string,
  since: string | null,
): Promise<{todos: number; tags: number; serverTime: string}> {
  const settings = loadAccountSettings();
  const pull = await pullDatasetSync(baseId, since, settings);

  const store = getIdbTodoStore();
  // Snapshot complet (pas listTodos) : sans recursive, listTodos ne renvoie
  // que les racines — le ré-import effaçait alors toute la hiérarchie.
  const local = await store.exportSnapshot();
  const snapshot = normalizeSnapshotWithVersions({
    todos: local.todos,
    tags: local.tags,
  });

  let next = snapshot;
  for (const tag of pull.tags) {
    next = applyRemoteTag(next, tag);
  }
  for (const todo of pull.todos) {
    next = applyRemoteTodo(next, todo);
  }

  await store.importSnapshot({
    format: "tada",
    version: 1,
    id: baseId,
    name: (await readActiveLocalDataset())?.name ?? local.name ?? "Sync",
    exportedAt: pull.serverTime,
    todos: next.todos,
    tags: next.tags,
  });

  return {
    todos: pull.todos.length,
    tags: pull.tags.length,
    serverTime: pull.serverTime,
  };
}

async function runDatasetSyncUnlocked(
  options: {baseId?: string; fullPull?: boolean} = {},
): Promise<SyncRunResult> {
  if (!isAccountConnected()) {
    return {pushed: 0, pulledTodos: 0, pulledTags: 0, bootstrapped: false, error: "Compte non connecté"};
  }

  const local = await readActiveLocalDataset();
  const datasetBaseId = options.baseId ?? local?.baseId;
  if (!datasetBaseId) {
    return {pushed: 0, pulledTodos: 0, pulledTags: 0, bootstrapped: false, error: "Aucun jeu local actif"};
  }

  let state = await getSyncState(datasetBaseId);
  const settings = loadAccountSettings();

  try {
    if (!state.bootstrapped) {
      const meta = await getIdbTodoStore().exportSnapshot();
      try {
        await bootstrapDatasetSync(
          datasetBaseId,
          {
            name: meta.name,
            todos: meta.todos,
            tags: meta.tags,
          },
          settings,
        );
      } catch (error) {
        const message = error instanceof Error ? error.message : "Bootstrap impossible";
        if (!message.includes("introuvable")) {
          await createCloudDatasetWithBaseId(meta.name, datasetBaseId, settings).catch(() => {});
          await bootstrapDatasetSync(
            datasetBaseId,
            {name: meta.name, todos: meta.todos, tags: meta.tags},
            settings,
          );
        } else {
          throw error;
        }
      }
      state = {
        ...state,
        bootstrapped: true,
        lastError: null,
      };
    }

    await resetFailedMutations(datasetBaseId);
    const pending = await listPendingMutations(datasetBaseId);
    let pushed = 0;

    if (pending.length > 0) {
      const ids = pending.map((mutation) => mutation.id);
      await markMutationsInflight(ids);
      const result = await pushDatasetSync(datasetBaseId, pending, settings);
      const accepted = new Set(result.accepted);
      const acceptedIds = pending
        .filter((mutation) => accepted.has(`${mutation.entity}:${mutation.entityId}`))
        .map((mutation) => mutation.id);
      await removeMutations(acceptedIds);

      const rejectedIds = pending
        .filter((mutation) => !acceptedIds.includes(mutation.id))
        .map((mutation) => mutation.id);
      if (rejectedIds.length > 0) {
        await markMutationsFailed(rejectedIds, "Mutations rejetées par le serveur");
      }
      pushed = acceptedIds.length;
    }

    const since = options.fullPull ? null : state.lastPulledAt;
    const pull = await applyPullToLocal(datasetBaseId, since);
    state = {
      ...state,
      lastPulledAt: pull.serverTime,
      lastPushedAt: pushed > 0 ? pull.serverTime : state.lastPushedAt,
      lastSyncAt: pull.serverTime,
      lastError: null,
      bootstrapped: true,
    };
    await saveSyncState(state);

    const changed = pushed > 0 || pull.todos > 0 || pull.tags > 0;
    if (changed) {
      const filter = read(todosFilterKey.path) as TodosFilter;
      set(todosFilterKey.path, {...filter, _rev: (filter._rev ?? 0) + 1});
      if (pull.tags > 0) {
        set(tagsListKey.path, await getIdbTodoStore().listTags());
      }
    }

    return {
      pushed,
      pulledTodos: pull.todos,
      pulledTags: pull.tags,
      bootstrapped: state.bootstrapped,
    };
  } catch (error) {
    const message = error instanceof Error ? error.message : "Erreur de synchronisation";
    await saveSyncState({...state, lastError: message});
    return {
      pushed: 0,
      pulledTodos: 0,
      pulledTags: 0,
      bootstrapped: state.bootstrapped,
      error: message,
    };
  }
}

export async function runDatasetSync(
  baseIdOrOptions?: string | {baseId?: string; fullPull?: boolean},
): Promise<SyncRunResult> {
  const options =
    typeof baseIdOrOptions === "string"
      ? {baseId: baseIdOrOptions}
      : (baseIdOrOptions ?? {});
  if (inflightSync) return inflightSync;
  lastSyncStartedAt = Date.now();
  inflightSync = runDatasetSyncUnlocked(options).finally(() => {
    inflightSync = null;
  });
  return inflightSync;
}

export async function getActiveDatasetSyncState(): Promise<SyncState | null> {
  const local = await readActiveLocalDataset();
  if (!local) return null;
  return getSyncState(local.baseId);
}

export async function getActivePendingCount(): Promise<number> {
  const local = await readActiveLocalDataset();
  if (!local) return 0;
  return countPendingMutations(local.baseId);
}

/**
 * Bascule l’édition locale sur un jeu cloud (par baseId) puis pull complet.
 * N’active pas le jeu MCP côté serveur.
 */
export async function openCloudDatasetForEditing(cloud: {
  baseId: string;
  name: string;
}): Promise<SyncRunResult> {
  const store = getIdbTodoStore();
  const targetBaseId = formatBaseId(cloud.baseId);
  const locals = await store.listDatasets();
  let local =
    locals.find((dataset) => formatBaseId(dataset.baseId) === targetBaseId) ??
    null;

  if (!local) {
    local = await store.createDataset({
      name: cloud.name.trim() || "Jeu cloud",
      source: "empty",
      baseId: targetBaseId,
    });
  }

  if (!local.active) {
    await store.activateDataset(local.id);
  }

  const result = await runDatasetSync({
    baseId: targetBaseId,
    fullPull: true,
  });

  // Toujours rafraîchir l’UI après un switch (même si le pull n’a rien changé).
  const filter = read(todosFilterKey.path) as TodosFilter;
  set(todosFilterKey.path, {...filter, _rev: (filter._rev ?? 0) + 1});
  try {
    set(tagsListKey.path, await store.listTags());
  } catch {
    set(tagsListKey.path, []);
  }

  return result;
}

/**
 * Sync différée (mutations locales, online, focus).
 * Debounce pour regrouper les rafales d’écritures.
 */
export function scheduleAutoSync(): void {
  if (!navigator.onLine || !isAccountConnected()) return;
  if (autoSyncTimer) clearTimeout(autoSyncTimer);
  autoSyncTimer = setTimeout(() => {
    autoSyncTimer = null;
    void runDatasetSync();
  }, AUTO_SYNC_DEBOUNCE_MS);
}

/**
 * Pull cloud avant une lecture UI si la dernière sync est trop vieille.
 * Légère : skip si hors-ligne / non connecté / sync récente / déjà en cours.
 */
export async function ensureCloudSynced(
  options?: {maxAgeMs?: number},
): Promise<void> {
  if (!navigator.onLine || !isAccountConnected()) return;

  const maxAgeMs = options?.maxAgeMs ?? DEFAULT_SYNC_MAX_AGE_MS;

  if (inflightSync) {
    await inflightSync;
    return;
  }

  if (Date.now() - lastSyncStartedAt < maxAgeMs) return;

  const state = await getActiveDatasetSyncState();
  if (state?.lastSyncAt) {
    const ageMs = Date.now() - Date.parse(state.lastSyncAt);
    if (!Number.isNaN(ageMs) && ageMs >= 0 && ageMs < maxAgeMs) return;
  }

  await runDatasetSync();
}
