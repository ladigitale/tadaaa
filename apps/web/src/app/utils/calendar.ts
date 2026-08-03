import type {Todo, TodoPriority} from "../api/types";
import {
  addDays,
  localInputFromWire,
  minutesToTime,
  parseDateOnly,
  parseTimeToMinutes,
  rewireOnDate,
  snapMinutes,
  toDateOnly,
  todoDateSpan,
  todoOverlapsRange,
  wireFromLocalInput,
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
  return Boolean(toDateOnly(todo.startAt ?? null) || toDateOnly(todo.endAt ?? null));
}

export function matchesDatePresence(
  todo: Todo,
  presence: CalendarDatePresence,
): boolean {
  const hasStart = Boolean(toDateOnly(todo.startAt ?? null));
  const hasEnd = Boolean(toDateOnly(todo.endAt ?? null));
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

export type DayTimedLayout = {
  kind: "timed";
  todo: Todo;
  startMin: number;
  endMin: number;
};

export type DayAllDayLayout = {
  kind: "allDay";
  todo: Todo;
};

export type DayEventLayout = DayTimedLayout | DayAllDayLayout;

const DEFAULT_TIMED_DURATION_MIN = 60;

/** Place a todo in the day view: all-day lane vs timed block. */
export function layoutTodoForDay(todo: Todo, day: string): DayEventLayout | null {
  const span = todoDateSpan(todo);
  if (!span || day < span.start || day > span.end) return null;

  const start = localInputFromWire(todo.startAt);
  const end = localInputFromWire(todo.endAt);
  const multiDay = span.start !== span.end;
  const startMin = start.time ? parseTimeToMinutes(start.time) : null;
  const endMin = end.time ? parseTimeToMinutes(end.time) : null;

  // Multi-day or no clock time → all-day lane.
  if (multiDay || (startMin === null && endMin === null)) {
    return {kind: "allDay", todo};
  }

  let from = startMin ?? endMin ?? 0;
  let to =
    endMin ??
    (startMin !== null ? startMin + DEFAULT_TIMED_DURATION_MIN : from + DEFAULT_TIMED_DURATION_MIN);
  if (to <= from) to = from + DEFAULT_TIMED_DURATION_MIN;
  from = Math.max(0, Math.min(24 * 60 - 15, from));
  to = Math.max(from + 15, Math.min(24 * 60, to));
  return {kind: "timed", todo, startMin: from, endMin: to};
}

export function partitionDayTodos(
  todos: Todo[],
  day: string,
): {allDay: DayAllDayLayout[]; timed: DayTimedLayout[]} {
  const allDay: DayAllDayLayout[] = [];
  const timed: DayTimedLayout[] = [];
  for (const todo of todosForDay(todos, day)) {
    const layout = layoutTodoForDay(todo, day);
    if (!layout) continue;
    if (layout.kind === "allDay") allDay.push(layout);
    else timed.push(layout);
  }
  timed.sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin);
  return {allDay, timed};
}

/**
 * Move a single-day timed todo so its start is `startMin` (snapped),
 * preserving duration. Both edges become timed on `day`.
 */
export function moveTimedTodoToMinutes(
  todo: Todo,
  day: string,
  startMin: number,
): {startAt: string; endAt: string} | null {
  const layout = layoutTodoForDay(todo, day);
  if (!layout || layout.kind !== "timed") return null;
  const duration = Math.max(15, layout.endMin - layout.startMin);
  const from = snapMinutes(startMin);
  const to = Math.min(24 * 60, from + duration);
  const startAt = wireFromLocalInput(day, minutesToTime(from));
  const endAt = wireFromLocalInput(day, minutesToTime(Math.max(from + 15, to)));
  if (!startAt || !endAt) return null;
  return {startAt, endAt};
}

/** Resize start or end of a single-day timed todo (15‑min snap, min 15‑min span). */
export function resizeTimedTodoEdge(
  todo: Todo,
  day: string,
  edge: "start" | "end",
  minutes: number,
): {startAt: string; endAt: string} | null {
  const layout = layoutTodoForDay(todo, day);
  if (!layout || layout.kind !== "timed") return null;
  const snapped = snapMinutes(minutes);
  let from = layout.startMin;
  let to = layout.endMin;
  if (edge === "start") {
    from = Math.max(0, Math.min(snapped, to - 15));
  } else {
    to = Math.min(24 * 60, Math.max(snapped, from + 15));
  }
  const startAt = wireFromLocalInput(day, minutesToTime(from));
  const endAt = wireFromLocalInput(day, minutesToTime(to));
  if (!startAt || !endAt) return null;
  return {startAt, endAt};
}

/** Preview start/end minutes while dragging a timed day event. */
export function previewTimedDragMinutes(
  kind: "move" | "resize-start" | "resize-end",
  originStart: number,
  originEnd: number,
  currentMinutes: number,
): {startMin: number; endMin: number} {
  const duration = Math.max(15, originEnd - originStart);
  if (kind === "resize-start") {
    const startMin = Math.max(0, Math.min(snapMinutes(currentMinutes), originEnd - 15));
    return {startMin, endMin: originEnd};
  }
  if (kind === "resize-end") {
    const endMin = Math.min(
      24 * 60,
      Math.max(snapMinutes(currentMinutes), originStart + 15),
    );
    return {startMin: originStart, endMin};
  }
  const startMin = snapMinutes(currentMinutes);
  return {
    startMin,
    endMin: Math.min(24 * 60, startMin + duration),
  };
}

/** Shift a todo span by `deltaDays`, preserving duration and local times. */
export function shiftTodoDates(
  todo: Todo,
  deltaDays: number,
): {startAt: string; endAt: string} | null {
  const span = todoDateSpan(todo);
  if (!span || deltaDays === 0) return null;
  const nextStart = addDays(span.start, deltaDays);
  const nextEnd = addDays(span.end, deltaDays);
  const startAt =
    rewireOnDate(todo.startAt ?? todo.endAt, nextStart) ?? nextStart;
  const endAt = rewireOnDate(todo.endAt ?? todo.startAt, nextEnd) ?? nextEnd;
  return {startAt, endAt};
}

/** Resize one edge of the span; keeps at least 1 day and local times. */
export function resizeTodoDates(
  todo: Todo,
  edge: "start" | "end",
  newDate: string,
): {startAt: string; endAt: string} | null {
  const span = todoDateSpan(todo);
  const day = parseDateOnly(newDate);
  if (!span || !day) return null;
  if (edge === "start") {
    const endDay = day <= span.end ? span.end : day;
    const startAt = rewireOnDate(todo.startAt ?? todo.endAt, day) ?? day;
    const endAt = rewireOnDate(todo.endAt ?? todo.startAt, endDay) ?? endDay;
    return {startAt, endAt};
  }
  const startDay = day >= span.start ? span.start : day;
  const startAt =
    rewireOnDate(todo.startAt ?? todo.endAt, startDay) ?? startDay;
  const endAt = rewireOnDate(todo.endAt ?? todo.startAt, day) ?? day;
  return {startAt, endAt};
}
