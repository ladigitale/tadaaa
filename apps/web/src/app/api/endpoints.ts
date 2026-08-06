/**
 * Routes HTTP mock-api + keys refresh/submit (style billetterie Concorde).
 * État UI (forms / filtres) : `../dp.ts`.
 */
import {DataProviderKey} from "@supersoniks/concorde/dataProviderKey";
import {Endpoint} from "@supersoniks/concorde/utils/endpoint";
import type {ApiResult} from "@supersoniks/concorde/decorators";
import type {TadaDataPackage} from "./data-package";
import type {CreateDatasetInput, DatasetInfo} from "./store";
import type {
  CreateTagInput,
  CreateTodoInput,
  ListTodosParams,
  MoveTodoInput,
  Tag,
  Todo,
  TodosListResponse,
  UpdateTagPatch,
  UpdateTodoPatch,
} from "./types";

/** Envelope mock-api `{ data: T }`. */
export type ApiData<T> = {data: T};

export type BulkUpdateBody = {
  filter: ListTodosParams;
  patch: UpdateTodoPatch;
};

const tagsList = new Endpoint<ApiData<Tag[]>>("tags");
const tagsCollection = new Endpoint<ApiData<Tag>>("tags");
const tagById = new Endpoint<ApiData<Tag>, {tagId: string}>("tags/${tagId}");
const tagByScopeId = new Endpoint<ApiData<Tag>, {scopeId: string}>(
  "tags/${scopeId}",
);
const tagPatch = new Endpoint<ApiData<Tag>, {tagId: string}>("tags/${tagId}");

const todosCollection = new Endpoint<ApiData<Todo>>("todos");
const todoById = new Endpoint<ApiData<Todo>, {todoId: string}>(
  "todos/${todoId}",
);
const todoByScopeId = new Endpoint<ApiData<Todo>, {scopeId: string}>(
  "todos/${scopeId}",
);
const todoPatch = new Endpoint<ApiData<Todo>, {todoId: string}>(
  "todos/${todoId}",
);
const todoScopePatch = new Endpoint<ApiData<Todo>, {scopeId: string}>(
  "todos/${scopeId}",
);
const todoMove = new Endpoint<ApiData<Todo>, {todoId: string}>(
  "todos/${todoId}/move",
);

/** Actives + done (non archivées) — palette, search, due-dates, tags counts. */
const todosCatalog = new Endpoint<TodosListResponse>(
  "todos?status=all&limit=5000&recursive=true&sortBy=createdAt&sortDir=desc",
);
const todosArchivedCatalog = new Endpoint<TodosListResponse>(
  "todos?status=archived&limit=500&recursive=true&sortBy=createdAt&sortDir=desc",
);
/** Liste filtrée — host expose `todosQuery` (sans `?`). */
const todosDynamic = new Endpoint<TodosListResponse, {todosQuery: string}>(
  "todos?${todosQuery}",
);
const todosBulk = new Endpoint<ApiData<{updatedCount: number}>>("todos/bulk");
const todosPurgeArchived = new Endpoint<ApiData<{purgedCount: number}>>(
  "todos/purge-archived",
);

const datasetsList = new Endpoint<ApiData<DatasetInfo[]>>("datasets");
const datasetCreate = new Endpoint<ApiData<DatasetInfo>>("datasets");
const datasetActivate = new Endpoint<
  ApiData<DatasetInfo>,
  {datasetId: string}
>("datasets/${datasetId}/activate");

const dataExport = new Endpoint<ApiData<TadaDataPackage>>("export");
const dataImport = new Endpoint<ApiData<TadaDataPackage>>("import");

const refresh = {
  tagsList: new DataProviderKey<void>("tagsListRefresh"),
  todosCatalog: new DataProviderKey<void>("todosCatalogRefresh"),
  bulkCount: new DataProviderKey<void>("bulkCountRefresh"),
  datasets: new DataProviderKey<void>("datasetsRefresh"),
  datasetActivate: new DataProviderKey<void>("datasetActivateTrigger"),
  calendarTodos: new DataProviderKey<void>("calendarTodosRefresh"),
  calendarTodoPatch: new DataProviderKey<void>("calendarTodoPatchTrigger"),
  calendarTodoCopy: new DataProviderKey<void>("calendarTodoCopyTrigger"),
} as const;

const submit = {
  todoCreate: new DataProviderKey<CreateTodoInput | null>("todoCreateSubmit"),
  todoEdit: new DataProviderKey<UpdateTodoPatch | null>("todoEditSubmit"),
  tagCreate: new DataProviderKey<CreateTagInput | null>("tagCreateSubmit"),
  tagEdit: new DataProviderKey<UpdateTagPatch | null>("tagEditSubmit"),
  todoMove: new DataProviderKey<MoveTodoInput | null>("todoMoveSubmit"),
  todoScopePatch: new DataProviderKey<
    UpdateTodoPatch | null,
    {scopeId: string}
  >("todoScopePatch.${scopeId}"),
  todoItemPatch: new DataProviderKey<
    UpdateTodoPatch | null,
    {todoId: string}
  >("todoItemPatch.${todoId}"),
  todoCopy: new DataProviderKey<CreateTodoInput | null, {todoId: string}>(
    "todoCopySubmit.${todoId}",
  ),
  todoScopeCopy: new DataProviderKey<
    CreateTodoInput | null,
    {scopeId: string}
  >("todoScopeCopySubmit.${scopeId}"),
  calendarTodoPatch: new DataProviderKey<UpdateTodoPatch | null>(
    "calendarTodoPatch",
  ),
  calendarTodoCopy: new DataProviderKey<CreateTodoInput | null>(
    "calendarTodoCopy",
  ),
  bulkUpdate: new DataProviderKey<BulkUpdateBody | null>("bulkUpdateSubmit"),
  purgeArchived: new DataProviderKey<object | null>("purgeArchivedSubmit"),
  datasetCreate: new DataProviderKey<CreateDatasetInput | null>(
    "datasetCreateSubmit",
  ),
  datasetActivate: new DataProviderKey<object | null>(
    "datasetActivateSubmit",
  ),
  dataImport: new DataProviderKey<unknown | null>("dataImportSubmit"),
} as const;

export const endpoints = {
  tags: {
    list: tagsList,
    collection: tagsCollection,
    byId: tagById,
    byScopeId: tagByScopeId,
    patch: tagPatch,
  },
  todos: {
    collection: todosCollection,
    byId: todoById,
    byScopeId: todoByScopeId,
    patch: todoPatch,
    scopePatch: todoScopePatch,
    move: todoMove,
    catalog: todosCatalog,
    archivedCatalog: todosArchivedCatalog,
    dynamic: todosDynamic,
    bulk: todosBulk,
    purgeArchived: todosPurgeArchived,
  },
  datasets: {
    list: datasetsList,
    create: datasetCreate,
    activate: datasetActivate,
  },
  data: {
    export: dataExport,
    import: dataImport,
  },
  keys: {
    refresh,
    submit,
    paths: {
      todoItemPatch: (todoId: string) => `todoItemPatch.${todoId}`,
      todoScopePatch: (scopeId: string) => `todoScopePatch.${scopeId}`,
      todoCopy: (todoId: string) => `todoCopySubmit.${todoId}`,
      todoScopeCopy: (scopeId: string) => `todoScopeCopySubmit.${scopeId}`,
    },
  },
} as const;

export function readApiData<T>(
  payload: ApiResult<ApiData<T>> | null | undefined,
): T | null {
  if (!payload?.result || typeof payload.result !== "object") return null;
  if (payload.response && !payload.response.ok) return null;
  const data = (payload.result as ApiData<T>).data;
  return data ?? null;
}

export function apiResultError(
  payload: ApiResult<unknown> | null | undefined,
): Error {
  const err = (payload?.result as {error?: string} | undefined)?.error;
  if (err) return new Error(err);
  const status = payload?.response?.status;
  return new Error(status ? `API error ${status}` : "Unexpected error");
}
