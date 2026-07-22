import type {SortDirection, TodoSortBy} from "../api/types";
import type {PopSelectOption} from "./pop-select";

export const TODO_STATUS_OPTIONS: PopSelectOption[] = [
  {value: "all", label: "Tous", icon: "list"},
  {value: "active", label: "À faire", icon: "circle"},
  {value: "done", label: "Fait", icon: "check-circle"},
  {value: "archived", label: "Supprimés", icon: "trash"},
];

export const TODO_SORT_OPTIONS: PopSelectOption[] = [
  {value: "createdAt:asc", label: "Date croissante", icon: "sort-up"},
  {value: "createdAt:desc", label: "Date décroissante", icon: "sort-down"},
  {value: "priority:asc", label: "Priorité croissante", icon: "sort-up"},
  {value: "priority:desc", label: "Priorité décroissante", icon: "sort-down"},
  {value: "text:asc", label: "Alphabétique croissant", icon: "sort-up"},
  {value: "text:desc", label: "Alphabétique décroissant", icon: "sort-down"},
];

export function parseTodoSortKey(
  sort: string | undefined,
): {sortBy: TodoSortBy; sortDir: SortDirection} | null {
  if (!sort || !sort.includes(":")) return null;
  const [sortBy, sortDir] = sort.split(":") as [TodoSortBy, SortDirection];
  if (
    (sortBy !== "createdAt" &&
      sortBy !== "priority" &&
      sortBy !== "text") ||
    (sortDir !== "asc" && sortDir !== "desc")
  ) {
    return null;
  }
  return {sortBy, sortDir};
}
