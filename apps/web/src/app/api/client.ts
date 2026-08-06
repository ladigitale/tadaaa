import {getMockApiServiceUrl} from "./config";
import {bumpTagsList, bumpTodosRev} from "../init";
import type {DatasetInfo} from "./store";
import type {TadaDataPackage} from "./data-package";

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

/** Writes : ACL appliquée dans le router mock (aussi pour `@post`/`@patch`). */
async function apiWrite<T>(path: string, init?: RequestInit): Promise<T> {
  return apiFetch<T>(path, init);
}

/** Pas de `@delete` Concorde. */
export async function deleteTag(id: string): Promise<void> {
  await apiWrite<{ok: boolean}>(`/tags/${id}`, {
    method: "DELETE",
  });
  bumpTodosRev();
  bumpTagsList();
}

export async function exportTodosSnapshot(): Promise<TadaDataPackage> {
  const result = await apiFetch<{data: TadaDataPackage}>("/export");
  return result.data;
}

export async function importTodosSnapshot(
  raw: unknown,
): Promise<TadaDataPackage> {
  const result = await apiFetch<{data: TadaDataPackage}>("/import", {
    method: "PUT",
    body: JSON.stringify(raw),
  });
  bumpTodosRev();
  bumpTagsList();
  return result.data;
}

/** Pas de `@delete` / rename dynamique simple — reste impératif. */
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
