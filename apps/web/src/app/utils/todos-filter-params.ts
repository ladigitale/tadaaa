import type {ListTodosParams} from "../api/types";
import type {TodosFilter} from "../dp";

/** Filtre liste UI → params API (sans pagination : toutes les tâches matchées). */
export function todosFilterToListParams(filter: TodosFilter): ListTodosParams {
  const tags = Array.isArray(filter.tags)
    ? filter.tags.map(String).filter(Boolean)
    : [];

  return {
    q: filter.q?.trim() || null,
    status: filter.status ?? "all",
    tagIds: tags.length > 0 ? tags : null,
    sortBy: filter.sortBy ?? "createdAt",
    sortDir: filter.sortDir ?? "desc",
    parentId: filter.parentId?.trim() || null,
    recursive: filter.recursive === true,
  };
}
