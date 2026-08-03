import type {TodoStore, CreateDatasetInput} from "./store";
import type {
  CreateTagInput,
  CreateTodoInput,
  ListTodosParams,
  MoveTodoInput,
  SortDirection,
  TodoSortBy,
  TodoStatusFilter,
  UpdateTagPatch,
  UpdateTodoPatch,
} from "./types";
import {getAppLocale, normalizeAppLocale, resolveWordings} from "../i18n";

export const MOCK_API_PATH_PREFIX = "/mock-api";

function json(body: unknown, init: ResponseInit = {}): Response {
  const headers = new Headers(init.headers);
  headers.set("Content-Type", "application/json; charset=utf-8");
  headers.set("X-Tada-Api", "mock");

  return new Response(JSON.stringify(body), {
    ...init,
    headers,
  });
}

async function readJsonBody<T>(request: Request): Promise<T> {
  const text = await request.text();
  if (!text.trim()) return {} as T;
  return JSON.parse(text) as T;
}

function parseBooleanParam(value: string | null): boolean | null {
  if (value === null || value === "") return null;
  if (value === "true" || value === "1") return true;
  if (value === "false" || value === "0") return false;
  return null;
}

function parseListParams(url: URL) {
  const limit = Math.max(
    1,
    parseInt(url.searchParams.get("limit") || "20", 10) || 20,
  );
  const offset = Math.max(
    0,
    parseInt(url.searchParams.get("offset") || "0", 10) || 0,
  );
  const status = (url.searchParams.get("status") ||
    "all") as TodoStatusFilter;
  const tagId = url.searchParams.get("tag") || url.searchParams.get("tagId");
  const normalizedTagId =
    !tagId || tagId === "*" || tagId === "all" || tagId === "__all__"
      ? null
      : tagId;
  const tagsParam = url.searchParams.get("tags") || "";
  const tagIds = tagsParam
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean)
    .filter((value) => value !== "*" && value !== "all");
  const q = url.searchParams.get("q");
  const sortBy = (url.searchParams.get("sortBy") as TodoSortBy | null) ?? null;
  const sortDir =
    (url.searchParams.get("sortDir") as SortDirection | null) ?? null;
  const parentIdRaw = url.searchParams.get("parentId");
  const parentId =
    parentIdRaw === null || parentIdRaw === "" || parentIdRaw === "__root__"
      ? null
      : parentIdRaw;
  const recursive = parseBooleanParam(url.searchParams.get("recursive"));

  return {
    limit,
    offset,
    status,
    tagId: normalizedTagId,
    tagIds,
    q,
    sortBy,
    sortDir,
    parentId,
    recursive: recursive === true,
  };
}

export function createMockApiHandler(store: TodoStore) {
  return async function handleMockApiRequest(
    request: Request,
  ): Promise<Response | null> {
    const url = new URL(request.url);
    if (!url.pathname.startsWith(MOCK_API_PATH_PREFIX)) return null;

    const subPath = url.pathname.slice(MOCK_API_PATH_PREFIX.length) || "/";
    const method = request.method.toUpperCase();

    try {
      if (method === "GET" && subPath === "/health") {
        return json({ok: true, service: "tada-mock-api"});
      }

      // Concorde wording: GET /wordings?labels[]=key1&labels[]=key2
      if (method === "GET" && subPath === "/wordings") {
        const labels = [
          ...url.searchParams.getAll("labels[]"),
          ...url.searchParams.getAll("labels"),
        ].filter(Boolean);
        const fromHeader = request.headers.get("Accept-Language");
        const locale = normalizeAppLocale(fromHeader || getAppLocale());
        return json(resolveWordings(labels, locale));
      }

      if (method === "GET" && subPath === "/export") {
        const data = await store.exportSnapshot();
        return json({data});
      }

      if (method === "PUT" && subPath === "/import") {
        const body = await readJsonBody<unknown>(request);
        const data = await store.importSnapshot(body);
        return json({data});
      }

      if (method === "GET" && subPath === "/datasets") {
        const data = await store.listDatasets();
        return json({data});
      }

      if (method === "POST" && subPath === "/datasets") {
        const body = await readJsonBody<CreateDatasetInput>(request);
        const data = await store.createDataset(body);
        return json({data}, {status: 201});
      }

      const datasetActivateMatch = subPath.match(
        /^\/datasets\/([^/]+)\/activate$/,
      );
      if (datasetActivateMatch && method === "POST") {
        const data = await store.activateDataset(datasetActivateMatch[1]);
        return json({data});
      }

      const datasetMatch = subPath.match(/^\/datasets\/([^/]+)$/);
      if (datasetMatch && method === "PATCH") {
        const body = await readJsonBody<{name?: string}>(request);
        const name = typeof body.name === "string" ? body.name : "";
        const data = await store.renameDataset(datasetMatch[1], name);
        return json({data});
      }
      if (datasetMatch && method === "DELETE") {
        await store.deleteDataset(datasetMatch[1]);
        return json({ok: true});
      }

      if (method === "GET" && subPath === "/tags") {
        const tags = await store.listTags();
        const needle = url.searchParams.get("q")?.trim().toLowerCase() ?? "";
        const limit = Math.max(
          1,
          parseInt(url.searchParams.get("limit") || "20", 10) || 20,
        );
        const filtered = needle
          ? tags.filter((tag) => tag.name.toLowerCase().includes(needle))
          : tags;
        return json({data: filtered.slice(0, limit)});
      }

      if (method === "POST" && subPath === "/tags") {
        const body = await readJsonBody<CreateTagInput>(request);
        const tag = await store.createTag(body);
        return json({data: tag}, {status: 201});
      }

      const tagMatch = subPath.match(/^\/tags\/([^/]+)$/);
      if (tagMatch && method === "GET") {
        const tags = await store.listTags();
        const tag = tags.find((item) => item.id === tagMatch[1]);
        if (!tag) throw new Error("Tag not found");
        return json({data: tag});
      }
      if (tagMatch && method === "PATCH") {
        const body = await readJsonBody<UpdateTagPatch>(request);
        const tag = await store.updateTag(tagMatch[1], body);
        return json({data: tag});
      }
      if (tagMatch && method === "DELETE") {
        await store.deleteTag(tagMatch[1]);
        return json({ok: true});
      }

      if (method === "POST" && subPath === "/todos/purge-archived") {
        const data = await store.purgeArchived();
        return json({data});
      }

      if (method === "POST" && subPath === "/todos/bulk") {
        const body = await readJsonBody<{
          filter?: ListTodosParams;
          patch?: UpdateTodoPatch;
        }>(request);
        const data = await store.bulkUpdate(body.filter ?? {}, body.patch ?? {});
        return json({data});
      }

      if (method === "GET" && subPath === "/todos") {
        const params = parseListParams(url);
        const result = await store.listTodos(params);
        return json(result);
      }

      if (method === "POST" && subPath === "/todos") {
        const body = await readJsonBody<CreateTodoInput>(request);
        const todo = await store.createTodo(body);
        return json({data: todo}, {status: 201});
      }

      const moveMatch = subPath.match(/^\/todos\/([^/]+)\/move$/);
      if (moveMatch && method === "POST") {
        const body = await readJsonBody<MoveTodoInput>(request);
        const todo = await store.moveTodo(moveMatch[1], {
          parentId: body.parentId ?? null,
        });
        return json({data: todo});
      }

      const todoMatch = subPath.match(/^\/todos\/([^/]+)$/);
      if (todoMatch && method === "GET") {
        const todo = await store.getTodo(todoMatch[1]);
        return json({data: todo});
      }
      if (todoMatch && method === "PATCH") {
        const body = await readJsonBody<UpdateTodoPatch>(request);
        const todo = await store.updateTodo(todoMatch[1], body);
        return json({data: todo});
      }

      return json({error: "Not found", path: subPath}, {status: 404});
    } catch (error) {
      const message =
        error instanceof Error ? error.message : "Unexpected error";
      return json({error: message}, {status: 400});
    }
  };
}
