import {getAppLocale} from "../i18n/locale";

const DATE_RE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Validate and normalize a YYYY-MM-DD string; returns null if invalid. */
export function parseDateOnly(value: unknown): string | null {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  if (!trimmed) return null;
  const match = DATE_RE.exec(trimmed);
  if (!match) return null;
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(Date.UTC(year, month - 1, day));
  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== month - 1 ||
    date.getUTCDate() !== day
  ) {
    return null;
  }
  return trimmed;
}

/** Local calendar today as YYYY-MM-DD. */
export function todayDateOnly(now: Date = new Date()): string {
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, "0");
  const d = String(now.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export function addDays(dateOnly: string, days: number): string {
  const parsed = parseDateOnly(dateOnly);
  if (!parsed) return dateOnly;
  const [y, m, d] = parsed.split("-").map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  date.setUTCDate(date.getUTCDate() + days);
  return formatUtcDateOnly(date);
}

function formatUtcDateOnly(date: Date): string {
  const y = date.getUTCFullYear();
  const m = String(date.getUTCMonth() + 1).padStart(2, "0");
  const d = String(date.getUTCDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export function daysBetween(start: string, end: string): number {
  const a = parseDateOnly(start);
  const b = parseDateOnly(end);
  if (!a || !b) return 0;
  const [ay, am, ad] = a.split("-").map(Number);
  const [by, bm, bd] = b.split("-").map(Number);
  const t0 = Date.UTC(ay, am - 1, ad);
  const t1 = Date.UTC(by, bm - 1, bd);
  return Math.round((t1 - t0) / 86_400_000);
}

/** Inclusive span for a todo; inverted bounds are normalized. */
export function todoDateSpan(todo: {
  startAt?: string | null;
  endAt?: string | null;
}): {start: string; end: string} | null {
  const start = parseDateOnly(todo.startAt ?? null);
  const end = parseDateOnly(todo.endAt ?? null);
  if (!start && !end) return null;
  if (start && !end) return {start, end: start};
  if (!start && end) return {start: end, end};
  if (start! <= end!) return {start: start!, end: end!};
  return {start: end!, end: start!};
}

export function dateInRange(
  date: string,
  rangeStart: string,
  rangeEnd: string,
): boolean {
  return date >= rangeStart && date <= rangeEnd;
}

export function rangesOverlap(
  aStart: string,
  aEnd: string,
  bStart: string,
  bEnd: string,
): boolean {
  return aStart <= bEnd && aEnd >= bStart;
}

export function todoOverlapsRange(
  todo: {startAt?: string | null; endAt?: string | null},
  rangeStart: string,
  rangeEnd: string,
): boolean {
  const span = todoDateSpan(todo);
  if (!span) return false;
  return rangesOverlap(span.start, span.end, rangeStart, rangeEnd);
}

/** Monday-based week containing `dateOnly` (ISO week style). */
export function weekRangeContaining(dateOnly: string): {start: string; end: string} {
  const parsed = parseDateOnly(dateOnly) ?? todayDateOnly();
  const [y, m, d] = parsed.split("-").map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  const dow = date.getUTCDay(); // 0=Sun … 6=Sat
  const mondayOffset = dow === 0 ? -6 : 1 - dow;
  const start = addDays(parsed, mondayOffset);
  return {start, end: addDays(start, 6)};
}

export function monthRangeContaining(dateOnly: string): {start: string; end: string} {
  const parsed = parseDateOnly(dateOnly) ?? todayDateOnly();
  const [y, m] = parsed.split("-").map(Number);
  const start = `${y}-${String(m).padStart(2, "0")}-01`;
  const lastDay = new Date(Date.UTC(y, m, 0)).getUTCDate();
  const end = `${y}-${String(m).padStart(2, "0")}-${String(lastDay).padStart(2, "0")}`;
  return {start, end};
}

export function yearRangeContaining(dateOnly: string): {start: string; end: string} {
  const parsed = parseDateOnly(dateOnly) ?? todayDateOnly();
  const y = parsed.slice(0, 4);
  return {start: `${y}-01-01`, end: `${y}-12-31`};
}

/** 42 cells (6 weeks) for a month grid starting Monday. */
export function monthGridDays(anchorDate: string): string[] {
  const {start: monthStart} = monthRangeContaining(anchorDate);
  const weekStart = weekRangeContaining(monthStart).start;
  const days: string[] = [];
  for (let i = 0; i < 42; i++) {
    days.push(addDays(weekStart, i));
  }
  return days;
}

export function shiftAnchor(
  anchor: string,
  mode: "day" | "week" | "month" | "year",
  delta: number,
): string {
  const parsed = parseDateOnly(anchor) ?? todayDateOnly();
  if (mode === "day") return addDays(parsed, delta);
  if (mode === "week") return addDays(parsed, delta * 7);
  if (mode === "year") {
    const [y, m, d] = parsed.split("-").map(Number);
    const next = new Date(Date.UTC(y + delta, m - 1, d));
    // Clamp day if month overflows (e.g. Feb 29)
    if (next.getUTCMonth() !== (m - 1 + 12) % 12 && m === 2) {
      next.setUTCDate(0);
    }
    return formatUtcDateOnly(next);
  }
  // month
  const [y, m, d] = parsed.split("-").map(Number);
  const next = new Date(Date.UTC(y, m - 1 + delta, 1));
  const last = new Date(
    Date.UTC(next.getUTCFullYear(), next.getUTCMonth() + 1, 0),
  ).getUTCDate();
  next.setUTCDate(Math.min(d, last));
  return formatUtcDateOnly(next);
}

export function localeTag(): string {
  return getAppLocale() === "fr" ? "fr-FR" : "en-US";
}

export function formatDayTitle(dateOnly: string): string {
  const parsed = parseDateOnly(dateOnly);
  if (!parsed) return dateOnly;
  const [y, m, d] = parsed.split("-").map(Number);
  const date = new Date(y, m - 1, d);
  return date.toLocaleDateString(localeTag(), {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

export function formatMonthTitle(dateOnly: string): string {
  const parsed = parseDateOnly(dateOnly) ?? todayDateOnly();
  const [y, m] = parsed.split("-").map(Number);
  const date = new Date(y, m - 1, 1);
  return date.toLocaleDateString(localeTag(), {month: "long", year: "numeric"});
}

export function formatWeekTitle(start: string, end: string): string {
  const a = parseDateOnly(start);
  const b = parseDateOnly(end);
  if (!a || !b) return `${start} – ${end}`;
  const [ay, am, ad] = a.split("-").map(Number);
  const [by, bm, bd] = b.split("-").map(Number);
  const startDate = new Date(ay, am - 1, ad);
  const endDate = new Date(by, bm - 1, bd);
  const sameMonth = ay === by && am === bm;
  const sameYear = ay === by;
  if (sameMonth) {
    return `${startDate.toLocaleDateString(localeTag(), {day: "numeric"})} – ${endDate.toLocaleDateString(localeTag(), {day: "numeric", month: "long", year: "numeric"})}`;
  }
  if (sameYear) {
    return `${startDate.toLocaleDateString(localeTag(), {day: "numeric", month: "short"})} – ${endDate.toLocaleDateString(localeTag(), {day: "numeric", month: "short", year: "numeric"})}`;
  }
  return `${startDate.toLocaleDateString(localeTag(), {day: "numeric", month: "short", year: "numeric"})} – ${endDate.toLocaleDateString(localeTag(), {day: "numeric", month: "short", year: "numeric"})}`;
}

export function formatYearTitle(dateOnly: string): string {
  const parsed = parseDateOnly(dateOnly) ?? todayDateOnly();
  return parsed.slice(0, 4);
}

export function weekdayLabels(short = true): string[] {
  // Monday-first labels
  const base = new Date(Date.UTC(2024, 0, 1)); // Monday
  const labels: string[] = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(base);
    d.setUTCDate(base.getUTCDate() + i);
    labels.push(
      d.toLocaleDateString(localeTag(), {
        weekday: short ? "short" : "long",
      }),
    );
  }
  return labels;
}

export function monthShortName(monthIndex0: number): string {
  const date = new Date(2024, monthIndex0, 1);
  return date.toLocaleDateString(localeTag(), {month: "short"});
}

export function dayOfMonth(dateOnly: string): number {
  const parsed = parseDateOnly(dateOnly);
  if (!parsed) return 0;
  return Number(parsed.slice(8, 10));
}

export function sameMonth(a: string, b: string): boolean {
  return a.slice(0, 7) === b.slice(0, 7);
}
