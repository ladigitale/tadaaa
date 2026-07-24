import {DataProviderKey} from "@supersoniks/concorde/dataProviderKey";
import type {SortDirection, Tag, TodoPriority, TodoSortBy} from "./api/types";

export type TodosFilter = {
  q: string;
  status: "active" | "done" | "archived" | "all";
  /** Multi-sélection d’étiquettes (vide = toutes). */
  tags: string[];
  /** Clé du sélecteur de tri (`createdAt:desc`, …). */
  sort: `${TodoSortBy}:${SortDirection}`;
  sortBy: TodoSortBy;
  sortDir: SortDirection;
  /** Scope liste : "" = racine */
  parentId: string;
  /** true = toute l’arborescence (ex. filtre depuis page étiquettes). */
  recursive: boolean;
  _rev: number;
};

export type TodoCreateForm = {
  text: string;
  description: string;
  priority: TodoPriority;
  tagIds: string[];
  startAt: string;
  endAt: string;
};

export type TodoEditForm = {
  text: string;
  description: string;
  priority: TodoPriority;
  tagIds: string[];
  startAt: string;
  endAt: string;
};

export type TodoTagsEdit = {
  tagIds: string[];
};

/**
 * Formulaire partagé des checks « done » (multi FormCheckable) :
 * même `formDataProvider` + `name="ids"`, `value` = id de la todo.
 * `ids` = tableau des todos cochées (terminées).
 */
export type TodosDoneForm = {
  ids: string[];
};

export type TagCreateForm = {
  name: string;
  color: Tag["color"];
};

export type TagEditForm = {
  name: string;
  color: Tag["color"];
};

export type TagsFilter = {
  q: string;
};

export type TodoMoveForm = {
  q: string;
};

export type TodoSearchForm = {
  q: string;
  status: TodosFilter["status"];
  tags: string[];
  sort: TodosFilter["sort"];
  sortBy: TodoSortBy;
  sortDir: SortDirection;
};

export type AppConfigForm = {
  newDatasetName: string;
  p2pReceiveCode: string;
  accountEmail: string;
  accountPassword: string;
  accountApiBaseUrl: string;
  newCloudDatasetName: string;
  newAccessTokenName: string;
  shareInviteEmail: string;
};

export const todosFilterKey = new DataProviderKey<TodosFilter>("todosFilter");
export const todoCreateKey = new DataProviderKey<TodoCreateForm>("todoCreate");
export const todoEditKey = new DataProviderKey<TodoEditForm>("todoEdit");
export const tagCreateKey = new DataProviderKey<TagCreateForm>("tagCreate");
export const tagEditKey = new DataProviderKey<TagEditForm>("tagEdit");
export const tagsFilterKey = new DataProviderKey<TagsFilter>("tagsFilter");
export const todoMoveKey = new DataProviderKey<TodoMoveForm>("todoMove");
export const todoSearchKey = new DataProviderKey<TodoSearchForm>("todoSearch");
export const appConfigKey = new DataProviderKey<AppConfigForm>("appConfig");
export const tagsListKey = new DataProviderKey<Tag[]>("tagsList");
export const todoTagsEditKey = new DataProviderKey<
  TodoTagsEdit,
  {todoId: string}
>("todoTagsEdit.${todoId}");
export const todosDoneKey = new DataProviderKey<TodosDoneForm>("todosDone");
