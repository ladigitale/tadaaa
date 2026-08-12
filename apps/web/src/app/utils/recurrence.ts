import type {Todo, TodoRecurrence, UpdateTodoPatch} from "../api/types";
import {
  addDays,
  daysBetween,
  localInputFromWire,
  monthRangeContaining,
  parseTodoDate,
  shiftAnchor,
  todayDateOnly,
  weekRangeContaining,
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

function shiftWire(wire: string, recurrence: TodoRecurrence): string {
  const {date, time} = localInputFromWire(wire);
  if (!date) return wire;
  return wireFromLocalInput(shiftDate(date, recurrence), time) ?? wire;
}

/** Shift start/end independently; omit keys when the source had no date. */
export function nextOccurrenceDates(
  todo: {startAt?: string | null; endAt?: string | null},
  recurrence: TodoRecurrence,
): Pick<UpdateTodoPatch, "startAt" | "endAt"> {
  if (recurrence === "none") return {};
  const start = parseTodoDate(todo.startAt ?? null);
  const end = parseTodoDate(todo.endAt ?? null);
  if (!start && !end) return {};
  return {
    ...(start ? {startAt: shiftWire(start, recurrence)} : {}),
    ...(end ? {endAt: shiftWire(end, recurrence)} : {}),
  };
}

function localMidnight(dateOnly: string): Date {
  const [y, m, d] = dateOnly.split("-").map(Number);
  return new Date(y, m - 1, d, 0, 0, 0, 0);
}

/** Instant when the todo was last marked done (fieldVersions.done). */
export function completedAtOf(todo: Todo): Date | null {
  const raw = todo.fieldVersions?.done ?? todo.createdAt;
  if (typeof raw !== "string" || !raw.trim()) return null;
  const ms = Date.parse(raw);
  if (Number.isNaN(ms)) return null;
  return new Date(ms);
}

/**
 * Start of the next recurrence unit after completion (local calendar):
 * daily → next local midnight, weekly → next Monday 00:00, monthly → 1st next month.
 */
export function startOfNextRecurrenceUnit(
  completedAt: Date,
  recurrence: TodoRecurrence,
): Date | null {
  if (recurrence === "none") return null;
  const day = todayDateOnly(completedAt);
  if (recurrence === "daily") {
    return localMidnight(addDays(day, 1));
  }
  if (recurrence === "weekly") {
    const {start} = weekRangeContaining(day);
    return localMidnight(addDays(start, 7));
  }
  const {start} = monthRangeContaining(day);
  return localMidnight(shiftAnchor(start, "month", 1));
}

/** How many recurrence units elapsed from completion day to `now` day (local). */
export function recurrenceUnitsElapsed(
  completedAt: Date,
  now: Date,
  recurrence: TodoRecurrence,
): number {
  if (recurrence === "none") return 0;
  const fromDay = todayDateOnly(completedAt);
  const toDay = todayDateOnly(now);
  if (toDay <= fromDay) return 0;
  if (recurrence === "daily") {
    return Math.max(0, daysBetween(fromDay, toDay));
  }
  if (recurrence === "weekly") {
    const fromWeek = weekRangeContaining(fromDay).start;
    const toWeek = weekRangeContaining(toDay).start;
    return Math.max(0, Math.floor(daysBetween(fromWeek, toWeek) / 7));
  }
  const [fy, fm] = fromDay.split("-").map(Number);
  const [ty, tm] = toDay.split("-").map(Number);
  return Math.max(0, (ty - fy) * 12 + (tm - fm));
}

export function shouldResetRecurringDone(
  todo: Todo,
  now: Date = new Date(),
): boolean {
  const recurrence = parseRecurrence(todo.recurrence);
  if (!todo.done || recurrence === "none") return false;
  const completedAt = completedAtOf(todo);
  if (!completedAt) return false;
  const resetAt = startOfNextRecurrenceUnit(completedAt, recurrence);
  return resetAt !== null && now.getTime() >= resetAt.getTime();
}

/**
 * Patch to uncheck a recurring todo once the next unit has begun.
 * Shifts dates by the number of units elapsed (catch-up after offline).
 * Returns null when no reset is due.
 */
export function buildRecurrenceResetPatch(
  todo: Todo,
  now: Date = new Date(),
): UpdateTodoPatch | null {
  if (!shouldResetRecurringDone(todo, now)) return null;
  const recurrence = parseRecurrence(todo.recurrence);
  const completedAt = completedAtOf(todo);
  if (!completedAt) return null;

  const units = Math.max(
    1,
    recurrenceUnitsElapsed(completedAt, now, recurrence),
  );
  const patch: UpdateTodoPatch = {done: false};

  let start = todo.startAt ?? null;
  let end = todo.endAt ?? null;
  const hadStart = Boolean(parseTodoDate(start));
  const hadEnd = Boolean(parseTodoDate(end));
  if (hadStart || hadEnd) {
    for (let i = 0; i < units; i++) {
      const next = nextOccurrenceDates(
        {startAt: start, endAt: end},
        recurrence,
      );
      if (next.startAt !== undefined) start = next.startAt ?? null;
      if (next.endAt !== undefined) end = next.endAt ?? null;
    }
    if (hadStart) patch.startAt = start;
    if (hadEnd) patch.endAt = end;
  }

  return patch;
}
