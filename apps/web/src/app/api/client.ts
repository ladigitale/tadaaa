import {getMockApiServiceUrl} from "./config";
import {bumpTodosRev} from "../init";
import {ensureCloudSynced} from "../sync/engine";
import {assertCanEditActiveDataset} from "../sync/cloud-access";
import type {CreateDatasetInput, DatasetInfo} from "./store";
import type {TadaDataPackage} from "./data-package";
import type {
  CreateTagInput,
  CreateTodoInput,
  ListTodosParams,
  Tag,
  Todo,
  TodosListResponse,
  UpdateTagPatch,
  UpdateTodoPatch,
} from "./types";

async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${getMockApiServiceUrl()}${path}`, {
    headers: {"Content-Type": "application/json"},
    ...init,
  });

  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as {error?: string};
    throw new Error(body.error ?? `API error ${response.status}`);
  }

  return response.json() as Promise<T>;
}

/** GET locaux : pull cloud stale avant lecture (léger, cooldown dans le moteur). */
async function apiGet<T>(path: string): Promise<T> {
  await ensureCloudSynced();
  return apiFetch<T>(path);
}

async function apiWrite<T>(path: string, init?: RequestInit): Promise<T> {
  await assertCanEditActiveDataset();
  return apiFetch<T>(path, init);
}

export async function fetchTags(): Promise<Tag[]> {
  const result = await apiGet<{data: Tag[]}>("/tags");
  return result.data;
}

export async function fetchTag(id: string): Promise<Tag> {
  const result = await apiGet<{data: Tag}>(`/tags/${id}`);
  return result.data;
}

export async function createTag(input: CreateTagInput): Promise<Tag> {
  const result = await apiWrite<{data: Tag}>("/tags", {
    method: "POST",
    body: JSON.stringify(input),
  });
  bumpTodosRev();
  return result.data;
}

export async function patchTag(id: string, patch: UpdateTagPatch): Promise<Tag> {
  const result = await apiWrite<{data: Tag}>(`/tags/${id}`, {
    method: "PATCH",
    body: JSON.stringify(patch),
  });
  bumpTodosRev();
  return result.data;
}

export async function deleteTag(id: string): Promise<void> {
  await apiWrite<{ok: boolean}>(`/tags/${id}`, {
    method: "DELETE",
  });
  bumpTodosRev();
}

export async function fetchTodos(
  params: ListTodosParams = {},
): Promise<TodosListResponse> {
  const query = new URLSearchParams();
  query.set("status", params.status ?? "all");
  query.set("offset", String(params.offset ?? 0));
  query.set("limit", String(params.limit ?? 50));
  if (params.q?.trim()) query.set("q", params.q.trim());
  if (params.tagId?.trim()) query.set("tag", params.tagId.trim());
  if (params.tagIds && params.tagIds.length > 0) {
    query.set("tags", params.tagIds.join(","));
  }
  if (params.sortBy) query.set("sortBy", params.sortBy);
  if (params.sortDir) query.set("sortDir", params.sortDir);
  if (params.parentId) query.set("parentId", params.parentId);
  query.set(
    "recursive",
    params.recursive === true || String(params.recursive) === "true"
      ? "true"
      : "false",
  );
  return apiGet<TodosListResponse>(`/todos?${query.toString()}`);
}

export async function fetchTodo(id: string): Promise<Todo> {
  const result = await apiGet<{data: Todo}>(`/todos/${id}`);
  return result.data;
}

export async function createTodo(input: CreateTodoInput): Promise<Todo> {
  const result = await apiWrite<{data: Todo}>("/todos", {
    method: "POST",
    body: JSON.stringify(input),
  });
  bumpTodosRev();
  return result.data;
}

export async function patchTodo(
  id: string,
  patch: UpdateTodoPatch,
  options?: {refreshList?: boolean},
): Promise<Todo> {
  const result = await apiWrite<{data: Todo}>(`/todos/${id}`, {
    method: "PATCH",
    body: JSON.stringify(patch),
  });
  if (options?.refreshList !== false) {
    bumpTodosRev();
  }
  return result.data;
}

export async function moveTodo(
  todoId: string,
  parentId: string | null,
): Promise<Todo> {
  const result = await apiWrite<{data: Todo}>(`/todos/${todoId}/move`, {
    method: "POST",
    body: JSON.stringify({parentId}),
  });
  bumpTodosRev();
  return result.data;
}

export async function bulkUpdateTodos(
  filter: ListTodosParams,
  patch: UpdateTodoPatch,
): Promise<{updatedCount: number}> {
  const result = await apiWrite<{data: {updatedCount: number}}>(
    "/todos/bulk",
    {
      method: "POST",
      body: JSON.stringify({filter, patch}),
    },
  );
  bumpTodosRev();
  return result.data;
}

export async function purgeArchivedTodos(): Promise<{purgedCount: number}> {
  const result = await apiWrite<{data: {purgedCount: number}}>(
    "/todos/purge-archived",
    {method: "POST"},
  );
  bumpTodosRev();
  return result.data;
}

export async function exportTodosSnapshot(): Promise<TadaDataPackage> {
  const result = await apiFetch<{data: TadaDataPackage}>("/export");
  return result.data;
}

export async function importTodosSnapshot(
  raw: unknown,
): Promise<TadaDataPackage> {
  await assertCanEditActiveDataset();
  const result = await apiFetch<{data: TadaDataPackage}>("/import", {
    method: "PUT",
    body: JSON.stringify(raw),
  });
  bumpTodosRev();
  return result.data;
}

export async function fetchDatasets(): Promise<DatasetInfo[]> {
  const result = await apiGet<{data: DatasetInfo[]}>("/datasets");
  return result.data;
}

export async function createDataset(
  input: CreateDatasetInput,
): Promise<DatasetInfo> {
  const result = await apiFetch<{data: DatasetInfo}>("/datasets", {
    method: "POST",
    body: JSON.stringify(input),
  });
  return result.data;
}

export async function activateDataset(id: string): Promise<DatasetInfo> {
  const result = await apiFetch<{data: DatasetInfo}>(
    `/datasets/${encodeURIComponent(id)}/activate`,
    {method: "POST"},
  );
  bumpTodosRev();
  return result.data;
}

export async function renameDataset(
  id: string,
  name: string,
): Promise<DatasetInfo> {
  const result = await apiFetch<{data: DatasetInfo}>(
    `/datasets/${encodeURIComponent(id)}`,
    {
      method: "PATCH",
      body: JSON.stringify({name}),
    },
  );
  return result.data;
}

export async function deleteDataset(id: string): Promise<void> {
  await apiFetch<{ok: boolean}>(`/datasets/${encodeURIComponent(id)}`, {
    method: "DELETE",
  });
  bumpTodosRev();
}
