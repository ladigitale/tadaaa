import fs from "fs/promises";
import path from "path";
import {randomUUID} from "crypto";
import type {DbSnapshot} from "./types";
import {normalizeSnapshot} from "./store-logic";
import {SEED_DATA} from "./seed";
import {
  createTodoStore,
  type CreateDatasetInput,
  type TodoRepository,
  type TodoStore,
} from "./store";

const DEFAULT_DATA_PATH = path.resolve(process.cwd(), ".data/todos.json");

type FileDataset = {
  id: string;
  baseId: string;
  name: string;
  updatedAt: string;
  todos: DbSnapshot["todos"];
  tags: DbSnapshot["tags"];
};

type FileDb = {
  activeDatasetId: string;
  datasets: FileDataset[];
};

function newBaseId(): string {
  return `base-${randomUUID()}`;
}

function formatBaseId(raw: string): string {
  const trimmed = raw.trim();
  if (trimmed === "") return trimmed;
  if (trimmed.startsWith("base-")) return trimmed;
  return `base-${trimmed}`;
}

function ensureDataset(item: Partial<FileDataset> & {
  id: string;
  name: string;
  updatedAt: string;
  todos: DbSnapshot["todos"];
  tags: DbSnapshot["tags"];
}): FileDataset {
  return {
    ...item,
    baseId: item.baseId?.trim() || newBaseId(),
  };
}

async function ensureFile(filePath: string): Promise<void> {
  await fs.mkdir(path.dirname(filePath), {recursive: true});
  try {
    await fs.access(filePath);
  } catch {
    const now = new Date().toISOString();
    const seed = normalizeSnapshot(SEED_DATA);
    const initial: FileDb = {
      activeDatasetId: "default",
      datasets: [
        {
          id: "default",
          baseId: newBaseId(),
          name: "Demo",
          updatedAt: now,
          todos: seed.todos,
          tags: seed.tags,
        },
      ],
    };
    await fs.writeFile(filePath, JSON.stringify(initial, null, 2), "utf8");
  }
}

function createFileRepository(filePath: string): TodoRepository {
  let writeChain: Promise<void> = Promise.resolve();

  async function readDb(): Promise<FileDb> {
    await ensureFile(filePath);
    const raw = await fs.readFile(filePath, "utf8");
    const parsed = JSON.parse(raw) as FileDb | DbSnapshot;
    if ("datasets" in parsed && Array.isArray(parsed.datasets)) {
      return {
        activeDatasetId: parsed.activeDatasetId,
        datasets: parsed.datasets.map((item) => ensureDataset(item)),
      };
    }
    const legacy = normalizeSnapshot(parsed as DbSnapshot);
    return {
      activeDatasetId: "default",
      datasets: [
        {
          id: "default",
          baseId: newBaseId(),
          name: "Demo",
          updatedAt: new Date().toISOString(),
          todos: legacy.todos,
          tags: legacy.tags,
        },
      ],
    };
  }

  async function writeDb(db: FileDb): Promise<void> {
    writeChain = writeChain.then(async () => {
      await ensureFile(filePath);
      await fs.writeFile(filePath, JSON.stringify(db, null, 2), "utf8");
    });
    await writeChain;
  }

  return {
    async readSnapshot() {
      const db = await readDb();
      const active =
        db.datasets.find((item) => item.id === db.activeDatasetId) ??
        db.datasets[0];
      return normalizeSnapshot({
        todos: active?.todos ?? [],
        tags: active?.tags ?? [],
      });
    },

    async writeSnapshot(snapshot) {
      const db = await readDb();
      const next = normalizeSnapshot(snapshot);
      const index = db.datasets.findIndex(
        (item) => item.id === db.activeDatasetId,
      );
      if (index < 0) {
        db.datasets.push({
          id: db.activeDatasetId || "default",
          baseId: newBaseId(),
          name: "Demo",
          updatedAt: new Date().toISOString(),
          todos: next.todos,
          tags: next.tags,
        });
        db.activeDatasetId = db.datasets[db.datasets.length - 1].id;
      } else {
        db.datasets[index] = {
          ...db.datasets[index],
          updatedAt: new Date().toISOString(),
          todos: next.todos,
          tags: next.tags,
        };
      }
      await writeDb(db);
    },

    async getActiveDatasetMeta() {
      const db = await readDb();
      const active =
        db.datasets.find((item) => item.id === db.activeDatasetId) ??
        db.datasets[0];
      return {
        id: active?.id ?? "default",
        baseId: active?.baseId ?? newBaseId(),
        name: active?.name ?? "Demo",
      };
    },

    async updateActiveDatasetMeta(meta) {
      const db = await readDb();
      const index = db.datasets.findIndex(
        (item) => item.id === db.activeDatasetId,
      );
      if (index < 0) return;
      db.datasets[index] = {
        ...db.datasets[index],
        name: meta.name?.trim() || db.datasets[index].name,
        baseId: meta.baseId?.trim() || db.datasets[index].baseId,
        updatedAt: new Date().toISOString(),
      };
      await writeDb(db);
    },

    async listDatasets() {
      const db = await readDb();
      return db.datasets
        .map((item) => ({
          id: item.id,
          baseId: item.baseId,
          name: item.name,
          updatedAt: item.updatedAt,
          active: item.id === db.activeDatasetId,
        }))
        .sort((a, b) => a.name.localeCompare(b.name));
    },

    async createDataset(input: CreateDatasetInput) {
      const db = await readDb();
      const name = input.name.trim() || "Nouveau jeu";
      const source = input.source ?? "empty";
      let snapshot: DbSnapshot = {todos: [], tags: []};
      if (source === "seed") snapshot = normalizeSnapshot(SEED_DATA);
      else if (source === "current") {
        const active =
          db.datasets.find((item) => item.id === db.activeDatasetId) ??
          db.datasets[0];
        snapshot = normalizeSnapshot({
          todos: active?.todos ?? [],
          tags: active?.tags ?? [],
        });
      }
      const id = `ds-${Date.now().toString(36)}`;
      const record: FileDataset = {
        id,
        baseId: input.baseId?.trim() ? formatBaseId(input.baseId) : newBaseId(),
        name,
        updatedAt: new Date().toISOString(),
        todos: snapshot.todos,
        tags: snapshot.tags,
      };
      db.datasets.push(record);
      await writeDb(db);
      return {
        id: record.id,
        baseId: record.baseId,
        name: record.name,
        updatedAt: record.updatedAt,
        active: false,
      };
    },

    async activateDataset(id: string) {
      const db = await readDb();
      const record = db.datasets.find((item) => item.id === id);
      if (!record) throw new Error("Jeu de données introuvable");
      db.activeDatasetId = id;
      await writeDb(db);
      return {
        id: record.id,
        baseId: record.baseId,
        name: record.name,
        updatedAt: record.updatedAt,
        active: true,
      };
    },

    async renameDataset(id: string, name: string) {
      const db = await readDb();
      const record = db.datasets.find((item) => item.id === id);
      if (!record) throw new Error("Jeu de données introuvable");
      const trimmed = name.trim();
      if (!trimmed) throw new Error("Le nom du jeu est requis");
      record.name = trimmed;
      record.updatedAt = new Date().toISOString();
      await writeDb(db);
      return {
        id: record.id,
        baseId: record.baseId,
        name: record.name,
        updatedAt: record.updatedAt,
        active: record.id === db.activeDatasetId,
      };
    },

    async deleteDataset(id: string) {
      const db = await readDb();
      if (db.datasets.length <= 1) {
        throw new Error("Impossible de supprimer le dernier jeu de données");
      }
      const next = db.datasets.filter((item) => item.id !== id);
      if (next.length === db.datasets.length) {
        throw new Error("Jeu de données introuvable");
      }
      db.datasets = next;
      if (db.activeDatasetId === id) {
        db.activeDatasetId = next[0].id;
      }
      await writeDb(db);
    },
  };
}

const stores = new Map<string, TodoStore>();

export function getFileTodoStore(filePath = DEFAULT_DATA_PATH): TodoStore {
  const resolved = path.resolve(filePath);
  let store = stores.get(resolved);
  if (!store) {
    store = createTodoStore(createFileRepository(resolved));
    stores.set(resolved, store);
  }
  return store;
}

export async function seedFileIfEmpty(
  filePath = DEFAULT_DATA_PATH,
): Promise<void> {
  await getFileTodoStore(filePath).seedIfEmpty();
}
