import type {SortDirection, TodoSortBy} from "../api/types";
import {tx} from "../i18n";
import type {PopSelectOption} from "./pop-select";

export function todoStatusOptions(): PopSelectOption[] {
  return [
    {value: "all", label: tx("tasks.status.all"), icon: "list"},
    {value: "active", label: tx("tasks.status.active"), icon: "circle"},
    {value: "done", label: tx("tasks.status.done"), icon: "check-circle"},
    {value: "archived", label: tx("tasks.status.archived"), icon: "trash"},
  ];
}

export function todoSortOptions(): PopSelectOption[] {
  return [
    {
      value: "createdAt:asc",
      label: tx("tasks.sort.created_asc"),
      icon: "sort-up",
    },
    {
      value: "createdAt:desc",
      label: tx("tasks.sort.created_desc"),
      icon: "sort-down",
    },
    {
      value: "priority:asc",
      label: tx("tasks.sort.priority_asc"),
      icon: "sort-up",
    },
    {
      value: "priority:desc",
      label: tx("tasks.sort.priority_desc"),
      icon: "sort-down",
    },
    {value: "text:asc", label: tx("tasks.sort.text_asc"), icon: "sort-up"},
    {value: "text:desc", label: tx("tasks.sort.text_desc"), icon: "sort-down"},
    {
      value: "startAt:asc",
      label: tx("tasks.sort.start_asc"),
      icon: "sort-up",
    },
    {
      value: "startAt:desc",
      label: tx("tasks.sort.start_desc"),
      icon: "sort-down",
    },
  ];
}

export function parseTodoSortKey(
  sort: string | undefined,
): {sortBy: TodoSortBy; sortDir: SortDirection} | null {
  if (!sort || !sort.includes(":")) return null;
  const [sortBy, sortDir] = sort.split(":") as [TodoSortBy, SortDirection];
  if (
    (sortBy !== "createdAt" &&
      sortBy !== "priority" &&
      sortBy !== "text" &&
      sortBy !== "startAt") ||
    (sortDir !== "asc" && sortDir !== "desc")
  ) {
    return null;
  }
  return {sortBy, sortDir};
}
