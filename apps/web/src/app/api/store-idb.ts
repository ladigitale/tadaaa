import {openDB, type DBSchema, type IDBPDatabase} from "idb";
import type {DbSnapshot, Tag, Todo} from "./types";
import {normalizeSnapshot} from "./store-logic";
import {getDemoDatasetName, getSeedData} from "./seed";
import {getAppLocale} from "../i18n/locale";
import {newBaseId, formatBaseId} from "./data-package";
import {
  createTodoStore,
  type CreateDatasetInput,
  type DatasetInfo,
  type TodoStore,
} from "./store";
import {emitSyncMutation} from "../sync/registry";

import type {SyncMutation, SyncState} from "../sync/outbox-types";

interface DatasetRecord {
  id: string;
  /** Identifiant unique de base (export). */
  baseId: string;
  name: string;
  updatedAt: string;
  todos: Todo[];
  tags: Tag[];
}

interface MetaRecord {
  key: "config";
  activeDatasetId: string;
}

interface TadaDb extends DBSchema {
  todos: {
    key: string;
    value: Todo;
  };
  tags: {
    key: string;
    value: Tag;
  };
  meta: {
    key: string;
    value: MetaRecord | {key: string; seeded: boolean};
  };
  datasets: {
    key: string;
    value: DatasetRecord;
  };
  syncOutbox: {
    key: string;
    value: SyncMutation;
    indexes: {"by-status": string; "by-dataset": string};
  };
  syncState: {
    key: string;
    value: SyncState;
  };
}

const DB_NAME = "tada-todos";
const DB_VERSION = 3;
const DEFAULT_DATASET_ID = "default";

let dbPromise: Promise<IDBPDatabase<TadaDb>> | null = null;

export function getTadaDb(): Promise<IDBPDatabase<TadaDb>> {
  return getDb();
}

function getDb(): Promise<IDBPDatabase<TadaDb>> {
  if (!dbPromise) {
    dbPromise = openDB<TadaDb>(DB_NAME, DB_VERSION, {
      upgrade(db, oldVersion) {
        if (!db.objectStoreNames.contains("todos")) {
          db.createObjectStore("todos", {keyPath: "id"});
        }
        if (!db.objectStoreNames.contains("tags")) {
          db.createObjectStore("tags", {keyPath: "id"});
        }
        if (!db.objectStoreNames.contains("meta")) {
          db.createObjectStore("meta", {keyPath: "key"});
        }
        if (!db.objectStoreNames.contains("datasets")) {
          db.createObjectStore("datasets", {keyPath: "id"});
        }
        if (oldVersion < 3) {
          if (!db.objectStoreNames.contains("syncOutbox")) {
            const outbox = db.createObjectStore("syncOutbox", {keyPath: "id"});
            outbox.createIndex("by-status", "status");
            outbox.createIndex("by-dataset", "datasetBaseId");
          }
          if (!db.objectStoreNames.contains("syncState")) {
            db.createObjectStore("syncState", {keyPath: "baseId"});
          }
        }
      },
    }).then(async (db) => {
      await migrateLegacyIfNeeded(db);
      await ensureBaseIds(db);
      return db;
    });
  }
  return dbPromise;
}

function nowIso(): string {
  return new Date().toISOString();
}

function newDatasetId(): string {
  return `ds-${crypto.randomUUID().slice(0, 8)}`;
}

function withBaseId(record: DatasetRecord): DatasetRecord {
  if (record.baseId?.trim()) return record;
  return {...record, baseId: record.id.startsWith("base-") ? record.id : newBaseId()};
}

async function ensureBaseIds(db: IDBPDatabase<TadaDb>): Promise<void> {
  const records = await db.getAll("datasets");
  for (const record of records) {
    if (record.baseId?.trim()) continue;
    await db.put("datasets", withBaseId(record));
  }
}

async function migrateLegacyIfNeeded(db: IDBPDatabase<TadaDb>): Promise<void> {
  const existing = await db.getAll("datasets");
  if (existing.length > 0) return;

  const [todos, tags] = await Promise.all([
    db.getAll("todos"),
    db.getAll("tags"),
  ]);
  const snapshot =
    todos.length > 0 || tags.length > 0
      ? normalizeSnapshot({todos, tags})
      : normalizeSnapshot(getSeedData(getAppLocale()));

  const record: DatasetRecord = {
    id: DEFAULT_DATASET_ID,
    baseId: newBaseId(),
    name: getDemoDatasetName(getAppLocale()),
    updatedAt: nowIso(),
    todos: snapshot.todos,
    tags: snapshot.tags,
  };
  await db.put("datasets", record);
  await db.put("meta", {
    key: "config",
    activeDatasetId: DEFAULT_DATASET_ID,
  });
}

async function getActiveDatasetId(db: IDBPDatabase<TadaDb>): Promise<string> {
  const meta = (await db.get("meta", "config")) as MetaRecord | undefined;
  if (meta?.activeDatasetId) return meta.activeDatasetId;

  const datasets = await db.getAll("datasets");
  if (datasets.length === 0) {
    await migrateLegacyIfNeeded(db);
    return DEFAULT_DATASET_ID;
  }
  const id = datasets[0].id;
  await db.put("meta", {key: "config", activeDatasetId: id});
  return id;
}

async function readSnapshot(): Promise<DbSnapshot> {
  const db = await getDb();
  const activeId = await getActiveDatasetId(db);
  const record = await db.get("datasets", activeId);
  if (!record) {
    return normalizeSnapshot({todos: [], tags: []});
  }
  return normalizeSnapshot({todos: record.todos, tags: record.tags});
}

async function writeSnapshot(snapshot: DbSnapshot): Promise<void> {
  const db = await getDb();
  const activeId = await getActiveDatasetId(db);
  const existing = await db.get("datasets", activeId);
  const next = normalizeSnapshot(snapshot);
  const record: DatasetRecord = withBaseId({
    id: activeId,
    baseId: existing?.baseId ?? newBaseId(),
    name: existing?.name ?? getDemoDatasetName(getAppLocale()),
    updatedAt: nowIso(),
    todos: next.todos,
    tags: next.tags,
  });
  await db.put("datasets", record);
}

async function getActiveDatasetMeta(): Promise<{
  id: string;
  baseId: string;
  name: string;
}> {
  const db = await getDb();
  const activeId = await getActiveDatasetId(db);
  const record = await db.get("datasets", activeId);
  if (!record) {
    return {id: activeId, baseId: newBaseId(), name: getDemoDatasetName(getAppLocale())};
  }
  const next = withBaseId(record);
  if (next.baseId !== record.baseId) {
    await db.put("datasets", next);
  }
  return {id: next.id, baseId: next.baseId, name: next.name};
}

async function updateActiveDatasetMeta(meta: {
  name?: string;
  baseId?: string;
}): Promise<void> {
  const db = await getDb();
  const activeId = await getActiveDatasetId(db);
  const existing = await db.get("datasets", activeId);
  if (!existing) return;
  const next = withBaseId({
    ...existing,
    name: meta.name?.trim() || existing.name,
    baseId: meta.baseId?.trim() || existing.baseId,
    updatedAt: nowIso(),
  });
  await db.put("datasets", next);
}

async function listDatasets(): Promise<DatasetInfo[]> {
  const db = await getDb();
  const activeId = await getActiveDatasetId(db);
  const records = await db.getAll("datasets");
  return records
    .map((record) => {
      const next = withBaseId(record);
      return {
        id: next.id,
        baseId: next.baseId,
        name: next.name,
        updatedAt: next.updatedAt,
        active: next.id === activeId,
      };
    })
    .sort((a, b) => a.name.localeCompare(b.name));
}

async function createDataset(input: CreateDatasetInput): Promise<DatasetInfo> {
  const db = await getDb();
  const name = input.name.trim() || "Nouveau jeu";
  const source = input.source ?? "empty";

  let snapshot: DbSnapshot = {todos: [], tags: []};
  if (source === "seed") {
    snapshot = normalizeSnapshot(getSeedData(getAppLocale()));
  } else if (source === "current") {
    snapshot = await readSnapshot();
  }

  const id = newDatasetId();
  const record: DatasetRecord = {
    id,
    baseId: input.baseId?.trim() ? formatBaseId(input.baseId) : newBaseId(),
    name,
    updatedAt: nowIso(),
    todos: snapshot.todos,
    tags: snapshot.tags,
  };
  await db.put("datasets", record);
  return {
    id: record.id,
    baseId: record.baseId,
    name: record.name,
    updatedAt: record.updatedAt,
    active: false,
  };
}

async function activateDataset(id: string): Promise<DatasetInfo> {
  const db = await getDb();
  const record = await db.get("datasets", id);
  if (!record) throw new Error("Jeu de données introuvable");
  await db.put("meta", {key: "config", activeDatasetId: id});
  const next = withBaseId(record);
  return {
    id: next.id,
    baseId: next.baseId,
    name: next.name,
    updatedAt: next.updatedAt,
    active: true,
  };
}

async function renameDataset(id: string, name: string): Promise<DatasetInfo> {
  const db = await getDb();
  const record = await db.get("datasets", id);
  if (!record) throw new Error("Jeu de données introuvable");
  const trimmed = name.trim();
  if (!trimmed) throw new Error("Le nom du jeu est requis");
  const next = withBaseId({
    ...record,
    name: trimmed,
    updatedAt: nowIso(),
  });
  await db.put("datasets", next);
  const activeId = await getActiveDatasetId(db);
  return {
    id: next.id,
    baseId: next.baseId,
    name: next.name,
    updatedAt: next.updatedAt,
    active: next.id === activeId,
  };
}

async function deleteDataset(id: string): Promise<void> {
  const db = await getDb();
  const records = await db.getAll("datasets");
  if (records.length <= 1) {
    throw new Error("Impossible de supprimer le dernier jeu de données");
  }
  const target = records.find((record) => record.id === id);
  if (!target) throw new Error("Jeu de données introuvable");

  const activeId = await getActiveDatasetId(db);
  await db.delete("datasets", id);
  if (activeId === id) {
    const next = records.find((record) => record.id !== id);
    if (next) {
      await db.put("meta", {key: "config", activeDatasetId: next.id});
    }
  }
}

let storeInstance: TodoStore | null = null;

export function getIdbTodoStore(): TodoStore {
  if (!storeInstance) {
    storeInstance = createTodoStore(
      {
        readSnapshot,
        writeSnapshot,
        listDatasets,
        createDataset,
        activateDataset,
        renameDataset,
        deleteDataset,
        getActiveDatasetMeta,
        updateActiveDatasetMeta,
      },
      {
        onMutation: emitSyncMutation,
      },
    );
  }
  return storeInstance;
}

export async function seedIdbIfEmpty(): Promise<void> {
  await getIdbTodoStore().seedIfEmpty();
}
