import type {CreateTodoInput, ListTodosParams, Todo} from "./types";

/** Params API → querystring (sans `?`). */
export function buildTodosQuery(params: ListTodosParams = {}): string {
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
  return query.toString();
}

export function todoCopyInput(source: Todo): CreateTodoInput {
  return {
    text: source.text,
    description: source.description ?? null,
    priority: source.priority,
    tagIds: source.tagIds,
    parentId: source.parentId,
    startAt: source.startAt ?? null,
    endAt: source.endAt ?? null,
    recurrence: source.recurrence ?? null,
  };
}
