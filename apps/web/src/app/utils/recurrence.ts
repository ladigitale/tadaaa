import type {CreateTodoInput, Todo, TodoRecurrence} from "../api/types";
import {
  addDays,
  localInputFromWire,
  parseTodoDate,
  shiftAnchor,
  wireFromLocalInput,
} from "./dates";

export const TODO_RECURRENCES: TodoRecurrence[] = [
  "none",
  "daily",
  "weekly",
  "monthly",
];

export function parseRecurrence(value: unknown): TodoRecurrence {
  if (value === "daily" || value === "weekly" || value === "monthly") {
    return value;
  }
  return "none";
}

function shiftDate(dateOnly: string, recurrence: TodoRecurrence): string {
  if (recurrence === "daily") return addDays(dateOnly, 1);
  if (recurrence === "weekly") return addDays(dateOnly, 7);
  return shiftAnchor(dateOnly, "month", 1);
}

function shiftWire(
  wire: string,
  recurrence: TodoRecurrence,
): string {
  const {date, time} = localInputFromWire(wire);
  if (!date) return wire;
  return wireFromLocalInput(shiftDate(date, recurrence), time) ?? wire;
}

/** Shift start/end independently; omit keys when the source had no date. */
export function nextOccurrenceDates(
  todo: {startAt?: string | null; endAt?: string | null},
  recurrence: TodoRecurrence,
): Pick<CreateTodoInput, "startAt" | "endAt"> {
  if (recurrence === "none") return {};
  const start = parseTodoDate(todo.startAt ?? null);
  const end = parseTodoDate(todo.endAt ?? null);
  if (!start && !end) return {};
  return {
    ...(start ? {startAt: shiftWire(start, recurrence)} : {}),
    ...(end ? {endAt: shiftWire(end, recurrence)} : {}),
  };
}

/**
 * Build create input for the next occurrence after `completed` was marked done.
 * Returns null when there is no active recurrence.
 */
export function buildNextOccurrenceInput(
  completed: Todo,
): CreateTodoInput | null {
  const recurrence = parseRecurrence(completed.recurrence);
  if (recurrence === "none") return null;
  const dates = nextOccurrenceDates(completed, recurrence);
  return {
    text: completed.text,
    description: completed.description ?? null,
    priority: completed.priority,
    tagIds: [...completed.tagIds],
    parentId: completed.parentId,
    recurrence,
    ...dates,
  };
}
