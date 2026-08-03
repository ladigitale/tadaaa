export type FieldVersions = Record<string, string>;

export interface Tag {
  id: string;
  name: string;
  color: TagColor;
  fieldVersions?: FieldVersions;
}

export type TodoPriority = "low" | "medium" | "high";

/** Simple recurrence: on complete, spawn next occurrence (dates shifted). */
export type TodoRecurrence = "none" | "daily" | "weekly" | "monthly";

export interface TodoAncestor {
  id: string;
  text: string;
}

export interface Todo {
  id: string;
  /** Nom de la tâche */
  text: string;
  /** Description optionnelle */
  description?: string;
  done: boolean;
  archived: boolean;
  priority: TodoPriority;
  tagIds: string[];
  /** null = tâche racine */
  parentId: string | null;
  /** Début optionnel : YYYY-MM-DD (journée) ou ISO UTC (…Z) */
  startAt?: string | null;
  /** Fin / échéance optionnelle : YYYY-MM-DD (journée) ou ISO UTC (…Z) */
  endAt?: string | null;
  /** Récurrence simple (défaut none) */
  recurrence?: TodoRecurrence;
  createdAt: string;
  fieldVersions?: FieldVersions;
  /** Calculé, non persisté */
  childCount?: number;
  /** Calculé sur getTodo, non persisté */
  ancestors?: TodoAncestor[];
}

export type TagColor =
  | "default"
  | "primary"
  | "neutral"
  | "warning"
  | "info"
  | "success"
  | "danger"
  | "contrast";

export type TodoStatusFilter = "active" | "done" | "archived" | "all";

export type TodoSortBy = "createdAt" | "priority" | "text" | "startAt";

export type SortDirection = "asc" | "desc";

export interface ListTodosParams {
  q?: string | null;
  status?: TodoStatusFilter | null;
  tagId?: string | null;
  tagIds?: string[] | null;
  sortBy?: TodoSortBy | null;
  sortDir?: SortDirection | null;
  /** Scope : null / "" / absent = racines (enfants de null). Id = enfants de cette tâche. */
  parentId?: string | null;
  /** true (défaut) : tout le sous-arbre plat ; false : enfants directs seulement */
  recursive?: boolean | null;
  offset?: number;
  limit?: number;
}

export interface TodosListResponse {
  data: Todo[];
  total: number;
  offset: number;
  limit: number;
}

export interface TagsListResponse {
  data: Tag[];
}

export interface CreateTodoInput {
  text: string;
  description?: string | null;
  priority?: TodoPriority | null;
  tagIds?: string[] | null;
  parentId?: string | null;
  startAt?: string | null;
  endAt?: string | null;
  recurrence?: TodoRecurrence | null;
}

export interface UpdateTodoPatch {
  text?: string;
  description?: string | null;
  done?: boolean;
  archived?: boolean;
  priority?: TodoPriority;
  tagIds?: string[] | null;
  startAt?: string | null;
  endAt?: string | null;
  recurrence?: TodoRecurrence | null;
}

export interface CreateTagInput {
  name: string;
  color: TagColor;
}

export interface UpdateTagPatch {
  name?: string;
  color?: TagColor;
}

/** Déplace une tâche : parentId null = racine. */
export interface MoveTodoInput {
  parentId: string | null;
}

export interface DbSnapshot {
  todos: Todo[];
  tags: Tag[];
}
