import {getTadaDb} from "../api/store-idb";
import type {SyncMutation, SyncState} from "./outbox-types";

export type {SyncEntity, SyncMutation, SyncState} from "./outbox-types";

function getSyncDb() {
  return getTadaDb();
}

export async function getSyncState(baseId: string): Promise<SyncState> {
  const db = await getSyncDb();
  const existing = await db.get("syncState", baseId);
  if (existing) return existing;
  return {
    baseId,
    lastPulledAt: null,
    lastPushedAt: null,
    lastSyncAt: null,
    lastError: null,
    bootstrapped: false,
  };
}

export async function saveSyncState(state: SyncState): Promise<void> {
  const db = await getSyncDb();
  await db.put("syncState", state);
}

export async function enqueueSyncMutation(
  mutation: Omit<SyncMutation, "id" | "createdAt" | "status" | "retries">,
): Promise<void> {
  const db = await getSyncDb();
  const existing = await db.getAllFromIndex("syncOutbox", "by-dataset", mutation.datasetBaseId);
  const duplicate = existing.find(
    (item) =>
      item.status === "pending" &&
      item.entity === mutation.entity &&
      item.entityId === mutation.entityId &&
      item.op === mutation.op,
  );

  const record: SyncMutation = duplicate
    ? {
        ...duplicate,
        payload: mutation.payload,
        fieldVersions: {...duplicate.fieldVersions, ...mutation.fieldVersions},
      }
    : {
        ...mutation,
        id: `mut-${crypto.randomUUID()}`,
        createdAt: new Date().toISOString(),
        status: "pending",
        retries: 0,
      };

  await db.put("syncOutbox", record);
}

export async function listPendingMutations(
  baseId: string,
): Promise<SyncMutation[]> {
  const db = await getSyncDb();
  const rows = await db.getAllFromIndex("syncOutbox", "by-dataset", baseId);
  return rows
    .filter((row) => row.status === "pending" || row.status === "failed")
    .sort((a, b) => a.createdAt.localeCompare(b.createdAt));
}

export async function countPendingMutations(baseId: string): Promise<number> {
  const pending = await listPendingMutations(baseId);
  return pending.length;
}

export async function markMutationsInflight(ids: string[]): Promise<void> {
  const db = await getSyncDb();
  for (const id of ids) {
    const row = await db.get("syncOutbox", id);
    if (!row) continue;
    await db.put("syncOutbox", {...row, status: "inflight"});
  }
}

export async function removeMutations(ids: string[]): Promise<void> {
  const db = await getSyncDb();
  for (const id of ids) {
    await db.delete("syncOutbox", id);
  }
}

export async function markMutationsFailed(
  ids: string[],
  error: string,
): Promise<void> {
  const db = await getSyncDb();
  for (const id of ids) {
    const row = await db.get("syncOutbox", id);
    if (!row) continue;
    await db.put("syncOutbox", {
      ...row,
      status: "failed",
      retries: row.retries + 1,
    });
  }
  if (ids.length > 0) {
    const first = await db.get("syncOutbox", ids[0]);
    if (first) {
      const state = await getSyncState(first.datasetBaseId);
      await saveSyncState({...state, lastError: error});
    }
  }
}

export async function resetFailedMutations(baseId: string): Promise<void> {
  const db = await getSyncDb();
  const rows = await db.getAllFromIndex("syncOutbox", "by-dataset", baseId);
  for (const row of rows) {
    if (row.status === "failed") {
      await db.put("syncOutbox", {...row, status: "pending"});
    }
  }
}
