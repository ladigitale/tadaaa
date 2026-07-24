import type {Todo, TodoPriority} from "../api/types";
import {
  addDays,
  parseDateOnly,
  todoDateSpan,
  todoOverlapsRange,
} from "./dates";

export type CalendarMode = "day" | "week" | "month" | "year";

export type CalendarDatePresence = "start" | "end" | "both" | "either";

export type CalendarStatusFilter = "active" | "done" | "all";

export type CalendarFilters = {
  q: string;
  status: CalendarStatusFilter;
  tags: string[];
  priorities: TodoPriority[];
  datePresence: CalendarDatePresence;
};

export const DEFAULT_CALENDAR_FILTERS: CalendarFilters = {
  q: "",
  status: "all",
  tags: [],
  priorities: [],
  datePresence: "either",
};

export function todoHasCalendarDates(todo: Todo): boolean {
  return Boolean(
    parseDateOnly(todo.startAt ?? null) || parseDateOnly(todo.endAt ?? null),
  );
}

export function matchesDatePresence(
  todo: Todo,
  presence: CalendarDatePresence,
): boolean {
  const hasStart = Boolean(parseDateOnly(todo.startAt ?? null));
  const hasEnd = Boolean(parseDateOnly(todo.endAt ?? null));
  switch (presence) {
    case "start":
      return hasStart;
    case "end":
      return hasEnd;
    case "both":
      return hasStart && hasEnd;
    case "either":
    default:
      return hasStart || hasEnd;
  }
}

export function filterCalendarTodos(
  todos: Todo[],
  filters: CalendarFilters,
): Todo[] {
  const needle = filters.q.trim().toLowerCase();
  const tagSet = new Set(filters.tags.filter(Boolean));
  const prioritySet = new Set(filters.priorities);

  return todos.filter((todo) => {
    if (todo.archived) return false;
    if (!matchesDatePresence(todo, filters.datePresence)) return false;

    if (filters.status === "active" && todo.done) return false;
    if (filters.status === "done" && !todo.done) return false;

    if (tagSet.size > 0 && !todo.tagIds.some((id) => tagSet.has(id))) {
      return false;
    }
    if (prioritySet.size > 0 && !prioritySet.has(todo.priority)) {
      return false;
    }
    if (needle && !todo.text.toLowerCase().includes(needle)) {
      return false;
    }
    return true;
  });
}

export function todosForRange(
  todos: Todo[],
  rangeStart: string,
  rangeEnd: string,
): Todo[] {
  return todos.filter((todo) => todoOverlapsRange(todo, rangeStart, rangeEnd));
}

export function todosForDay(todos: Todo[], day: string): Todo[] {
  return todosForRange(todos, day, day);
}

/** Shift a todo span by `deltaDays`, preserving duration. */
export function shiftTodoDates(
  todo: Todo,
  deltaDays: number,
): {startAt: string; endAt: string} | null {
  const span = todoDateSpan(todo);
  if (!span || deltaDays === 0) return null;
  return {
    startAt: addDays(span.start, deltaDays),
    endAt: addDays(span.end, deltaDays),
  };
}

/** Resize one edge of the span; keeps at least 1 day. */
export function resizeTodoDates(
  todo: Todo,
  edge: "start" | "end",
  newDate: string,
): {startAt: string; endAt: string} | null {
  const span = todoDateSpan(todo);
  const day = parseDateOnly(newDate);
  if (!span || !day) return null;
  if (edge === "start") {
    const end = day <= span.end ? span.end : day;
    return {startAt: day, endAt: end};
  }
  const start = day >= span.start ? span.start : day;
  return {startAt: start, endAt: day};
}
