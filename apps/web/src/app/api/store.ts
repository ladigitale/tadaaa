import type {
  CreateTagInput,
  CreateTodoInput,
  DbSnapshot,
  ListTodosParams,
  MoveTodoInput,
  Tag,
  Todo,
  TodosListResponse,
  UpdateTagPatch,
  UpdateTodoPatch,
} from "./types";
import {
  createTagRecord,
  createTodoRecord,
  filterTodos,
  getAncestors,
  moveTodoRecord,
  normalizeSnapshot,
  paginateTodos,
  purgeArchivedTodos,
  withChildCount,
} from "./store-logic";
import {
  stampTagCreate,
  stampTagDelete,
  stampTagPatch,
  stampTodoCreate,
  stampTodoDelete,
  stampTodoMove,
  stampTodoPatch,
} from "../sync/merge";
import {
  createDataPackage,
  packageToSnapshot,
  parseDataPackage,
  type TadaDataPackage,
} from "./data-package";

export type DatasetInfo = {
  id: string;
  /** Identifiant unique de base (export / traçabilité). */
  baseId: string;
  name: string;
  updatedAt: string;
  active: boolean;
};

export type CreateDatasetInput = {
  name: string;
  /** empty = vide ; seed = données démo ; current = clone du jeu actif */
  source?: "empty" | "seed" | "current";
  /** Si fourni, réutilise ce baseId (lien cloud) au lieu d’en générer un. */
  baseId?: string;
};

export interface TodoRepository {
  readSnapshot(): Promise<DbSnapshot>;
  writeSnapshot(snapshot: DbSnapshot): Promise<void>;
  listDatasets(): Promise<DatasetInfo[]>;
  createDataset(input: CreateDatasetInput): Promise<DatasetInfo>;
  activateDataset(id: string): Promise<DatasetInfo>;
  deleteDataset(id: string): Promise<void>;
  /** Métadonnées du jeu actif pour l’export versionné. */
  getActiveDatasetMeta(): Promise<{id: string; baseId: string; name: string}>;
  /** Met à jour nom / baseId du jeu actif (après import). */
  updateActiveDatasetMeta(meta: {
    name?: string;
    baseId?: string;
  }): Promise<void>;
}

export interface TodoStore {
  seedIfEmpty(): Promise<void>;
  listTodos(params: ListTodosParams): Promise<TodosListResponse>;
  getTodo(id: string): Promise<Todo>;
  createTodo(input: CreateTodoInput): Promise<Todo>;
  updateTodo(id: string, patch: UpdateTodoPatch): Promise<Todo>;
  moveTodo(id: string, input: MoveTodoInput): Promise<Todo>;
  listTags(): Promise<Tag[]>;
  createTag(input: CreateTagInput): Promise<Tag>;
  updateTag(id: string, patch: UpdateTagPatch): Promise<Tag>;
  deleteTag(id: string): Promise<void>;
  /** Purge définitive des tâches archivées (supprimées). */
  purgeArchived(): Promise<{purgedCount: number}>;
  /** Applique un patch à toutes les tâches correspondant au filtre (sans pagination). */
  bulkUpdate(
    params: ListTodosParams,
    patch: UpdateTodoPatch,
  ): Promise<{updatedCount: number}>;
  exportSnapshot(): Promise<TadaDataPackage>;
  importSnapshot(raw: unknown): Promise<TadaDataPackage>;
  listDatasets(): Promise<DatasetInfo[]>;
  createDataset(input: CreateDatasetInput): Promise<DatasetInfo>;
  activateDataset(id: string): Promise<DatasetInfo>;
  deleteDataset(id: string): Promise<void>;
}

export type SyncMutationEvent = {
  entity: "todo" | "tag";
  op: "upsert" | "delete";
  record?: Todo | Tag;
  entityId: string;
  fieldVersions: Record<string, string>;
};

export type TodoStoreOptions = {
  onMutation?: (event: SyncMutationEvent) => void;
};

export function createTodoStore(
  repo: TodoRepository,
  options: TodoStoreOptions = {},
): TodoStore {
  const notify = options.onMutation;

  async function withSnapshot<T>(
    fn: (snapshot: DbSnapshot) => Promise<{snapshot: DbSnapshot; result: T}>,
  ): Promise<T> {
    const snapshot = normalizeSnapshot(await repo.readSnapshot());
    const {snapshot: next, result} = await fn(snapshot);
    await repo.writeSnapshot(next);
    return result;
  }

  return {
    async seedIfEmpty() {
      const datasets = await repo.listDatasets();
      if (datasets.length > 0) return;
      const created = await repo.createDataset({
        name: "Défaut",
        source: "seed",
      });
      await repo.activateDataset(created.id);
    },

    async listTodos(params) {
      const snapshot = normalizeSnapshot(await repo.readSnapshot());
      const knownTagIds = snapshot.tags.map((tag) => tag.id);
      const filtered = filterTodos(snapshot.todos, params, knownTagIds);
      return paginateTodos(
        filtered,
        params.offset ?? 0,
        params.limit ?? 20,
        snapshot.todos,
      );
    },

    async getTodo(id) {
      const snapshot = normalizeSnapshot(await repo.readSnapshot());
      const todo = snapshot.todos.find((item) => item.id === id);
      if (!todo) throw new Error("Tâche introuvable");
      return {
        ...withChildCount(todo, snapshot.todos),
        ancestors: getAncestors(snapshot.todos, id),
      };
    },

    async createTodo(input) {
      return withSnapshot(async (snapshot) => {
        const validTagIds = new Set(snapshot.tags.map((tag) => tag.id));
        const todo = stampTodoCreate(
          createTodoRecord(
            {
              ...input,
              tagIds: (input.tagIds ?? []).filter((tagId) =>
                validTagIds.has(tagId),
              ),
            },
            snapshot.todos,
          ),
        );
        const todos = [todo, ...snapshot.todos];
        void notify?.({
          entity: "todo",
          op: "upsert",
          record: todo,
          entityId: todo.id,
          fieldVersions: todo.fieldVersions ?? {},
        });
        return {
          snapshot: {...snapshot, todos},
          result: withChildCount(todo, todos),
        };
      });
    },

    async updateTodo(id, patch) {
      return withSnapshot(async (snapshot) => {
        const index = snapshot.todos.findIndex((todo) => todo.id === id);
        if (index < 0) throw new Error("Tâche introuvable");

        const todos = [...snapshot.todos];
        const validTagIds = new Set(snapshot.tags.map((tag) => tag.id));
        const nextPatch: UpdateTodoPatch = {...patch};
        if (patch.tagIds) {
          nextPatch.tagIds = patch.tagIds.filter((tagId) =>
            validTagIds.has(tagId),
          );
        }
        const stamped = stampTodoPatch(todos[index], nextPatch);
        todos[index] = stamped.todo;
        void notify?.({
          entity: "todo",
          op: "upsert",
          record: stamped.todo,
          entityId: stamped.todo.id,
          fieldVersions: stamped.todo.fieldVersions ?? {},
        });

        return {
          snapshot: {...snapshot, todos},
          result: withChildCount(todos[index], todos),
        };
      });
    },

    async moveTodo(id, input) {
      return withSnapshot(async (snapshot) => {
        const parentId =
          input.parentId === undefined || input.parentId === ""
            ? null
            : input.parentId;
        const {todos, todo} = moveTodoRecord(snapshot.todos, id, parentId);
        const moved = stampTodoMove(todo);
        const nextTodos = todos.map((item) =>
          item.id === moved.id ? moved : item,
        );
        void notify?.({
          entity: "todo",
          op: "upsert",
          record: moved,
          entityId: moved.id,
          fieldVersions: moved.fieldVersions ?? {},
        });
        return {
          snapshot: {...snapshot, todos: nextTodos},
          result: withChildCount(moved, nextTodos),
        };
      });
    },

    async listTags() {
      const snapshot = normalizeSnapshot(await repo.readSnapshot());
      return [...snapshot.tags].sort((a, b) => a.name.localeCompare(b.name));
    },

    async createTag(input) {
      return withSnapshot(async (snapshot) => {
        const tag = stampTagCreate(createTagRecord(input, snapshot.tags));
        void notify?.({
          entity: "tag",
          op: "upsert",
          record: tag,
          entityId: tag.id,
          fieldVersions: tag.fieldVersions ?? {},
        });
        return {
          snapshot: {...snapshot, tags: [...snapshot.tags, tag]},
          result: tag,
        };
      });
    },

    async updateTag(id, patch) {
      return withSnapshot(async (snapshot) => {
        const stamped = stampTagPatch(
          snapshot.tags.find((tag) => tag.id === id) ??
            (() => {
              throw new Error("Tag not found");
            })(),
          patch,
          snapshot.tags,
        );
        const tags = snapshot.tags.map((item) =>
          item.id === id ? stamped.tag : item,
        );
        void notify?.({
          entity: "tag",
          op: "upsert",
          record: stamped.tag,
          entityId: stamped.tag.id,
          fieldVersions: stamped.tag.fieldVersions ?? {},
        });
        return {
          snapshot: {...snapshot, tags},
          result: stamped.tag,
        };
      });
    },

    async deleteTag(id) {
      return withSnapshot(async (snapshot) => {
        const tags = snapshot.tags.filter((tag) => tag.id !== id);
        if (tags.length === snapshot.tags.length) {
          throw new Error("Tag not found");
        }
        const versions = stampTagDelete();
        void notify?.({
          entity: "tag",
          op: "delete",
          entityId: id,
          fieldVersions: versions,
        });
        const todos = snapshot.todos.map((todo) => ({
          ...todo,
          tagIds: todo.tagIds.filter((tagId) => tagId !== id),
        }));
        return {
          snapshot: {...snapshot, tags, todos},
          result: undefined,
        };
      });
    },

    async purgeArchived() {
      return withSnapshot(async (snapshot) => {
        const archived = snapshot.todos.filter((todo) => todo.archived);
        const {todos, purgedCount} = purgeArchivedTodos(snapshot.todos);
        for (const todo of archived) {
          void notify?.({
            entity: "todo",
            op: "delete",
            entityId: todo.id,
            fieldVersions: stampTodoDelete(),
          });
        }
        return {
          snapshot: {...snapshot, todos},
          result: {purgedCount},
        };
      });
    },

    async bulkUpdate(params, patch) {
      return withSnapshot(async (snapshot) => {
        const knownTagIds = snapshot.tags.map((tag) => tag.id);
        const matched = filterTodos(snapshot.todos, params, knownTagIds);
        const ids = new Set(matched.map((todo) => todo.id));
        if (ids.size === 0) {
          return {snapshot, result: {updatedCount: 0}};
        }

        const validTagIds = new Set(knownTagIds);
        const nextPatch: UpdateTodoPatch = {...patch};
        if (patch.tagIds) {
          nextPatch.tagIds = patch.tagIds.filter((tagId) =>
            validTagIds.has(tagId),
          );
        }

        const todos = snapshot.todos.map((todo) => {
          if (!ids.has(todo.id)) return todo;
          const stamped = stampTodoPatch(todo, nextPatch);
          void notify?.({
          entity: "todo",
          op: "upsert",
          record: stamped.todo,
          entityId: stamped.todo.id,
          fieldVersions: stamped.todo.fieldVersions ?? {},
        });
          return stamped.todo;
        });
        return {
          snapshot: {...snapshot, todos},
          result: {updatedCount: ids.size},
        };
      });
    },

    async exportSnapshot() {
      const meta = await repo.getActiveDatasetMeta();
      const snapshot = normalizeSnapshot(await repo.readSnapshot());
      return createDataPackage({
        id: meta.baseId,
        name: meta.name,
        snapshot,
      });
    },

    async importSnapshot(raw) {
      const pkg = parseDataPackage(raw);
      await repo.writeSnapshot(packageToSnapshot(pkg));
      await repo.updateActiveDatasetMeta({
        name: pkg.name,
        baseId: pkg.id,
      });
      return pkg;
    },

    listDatasets() {
      return repo.listDatasets();
    },

    createDataset(input) {
      return repo.createDataset(input);
    },

    activateDataset(id) {
      return repo.activateDataset(id);
    },

    deleteDataset(id) {
      return repo.deleteDataset(id);
    },
  };
}
