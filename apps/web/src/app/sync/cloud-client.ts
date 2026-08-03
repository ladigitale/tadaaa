import {
  clearAccountSession,
  getCloudApiRoot,
  loadAccountSettings,
  type AccountSettings,
} from "../account-settings";
import type {Tag, Todo} from "../api/types";
import type {SyncMutation} from "./outbox-types";

export type SyncPullResponse = {
  serverTime: string;
  datasetUpdatedAt: string;
  todos: Array<Todo & {deletedAt?: string | null}>;
  tags: Array<Tag & {deletedAt?: string | null}>;
};

export type SyncPushResponse = {
  accepted: string[];
  rejected: Array<{id: string; reason: string}>;
  datasetUpdatedAt: string;
};

export type SyncBootstrapResponse = {
  datasetId: string;
  baseId: string;
  datasetUpdatedAt: string;
  imported: {todos: number; tags: number};
};

async function syncFetch<T>(
  path: string,
  init: RequestInit = {},
  settings: AccountSettings = loadAccountSettings(),
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (!headers.has("Content-Type") && init.body) {
    headers.set("Content-Type", "application/json");
  }
  if (settings.token) {
    headers.set("Authorization", `Bearer ${settings.token}`);
  }

  const response = await fetch(`${getCloudApiRoot(settings)}${path}`, {
    ...init,
    headers,
  });

  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as {
      error?: string;
      detail?: string;
      message?: string;
    };
    if (response.status === 401) {
      clearAccountSession();
    }
    throw new Error(
      body.message ??
        body.error ??
        body.detail ??
        (response.status === 401
          ? "Session cloud expirée ou invalide — reconnectez-vous."
          : `Sync API ${response.status}`),
    );
  }

  return response.json() as Promise<T>;
}

function encodeBaseId(baseId: string): string {
  return encodeURIComponent(baseId);
}

export async function pullDatasetSync(
  baseId: string,
  since: string | null,
  settings: AccountSettings = loadAccountSettings(),
): Promise<SyncPullResponse> {
  const query = since ? `?since=${encodeURIComponent(since)}` : "";
  return syncFetch<SyncPullResponse>(
    `/datasets/${encodeBaseId(baseId)}/sync${query}`,
    {},
    settings,
  );
}

export async function pushDatasetSync(
  baseId: string,
  mutations: SyncMutation[],
  settings: AccountSettings = loadAccountSettings(),
): Promise<SyncPushResponse> {
  return syncFetch<SyncPushResponse>(
    `/datasets/${encodeBaseId(baseId)}/sync/push`,
    {
      method: "POST",
      body: JSON.stringify({
        mutations: mutations.map((mutation) => ({
          entity: mutation.entity,
          op: mutation.op,
          id: mutation.entityId,
          payload: mutation.payload,
          fieldVersions: mutation.fieldVersions,
        })),
      }),
    },
    settings,
  );
}

export async function bootstrapDatasetSync(
  baseId: string,
  payload: {
    name: string;
    todos: Todo[];
    tags: Tag[];
  },
  settings: AccountSettings = loadAccountSettings(),
): Promise<SyncBootstrapResponse> {
  return syncFetch<SyncBootstrapResponse>(
    `/datasets/${encodeBaseId(baseId)}/sync/bootstrap`,
    {
      method: "POST",
      body: JSON.stringify(payload),
    },
    settings,
  );
}

export async function createCloudDatasetWithBaseId(
  name: string,
  baseId: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<{id: string; baseId: string}> {
  return syncFetch<{id: string; baseId: string}>(
    "/datasets",
    {
      method: "POST",
      body: JSON.stringify({name, baseId}),
    },
    settings,
  );
}
