/** SI / IEC formatting aligned with common observability UIs (Grafana, Datadog). */

const BYTE_UNITS = ["B", "KiB", "MiB", "GiB", "TiB"] as const;
const BYTE_UNITS_FR = ["o", "Kio", "Mio", "Gio", "Tio"] as const;

export function formatBytes(n: number, locale: string = "en"): string {
  const units = locale.startsWith("fr") ? BYTE_UNITS_FR : BYTE_UNITS;
  const abs = Math.abs(n);
  if (abs < 1024) return `${n} ${units[0]}`;
  let v = abs;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i += 1;
  }
  const digits = v >= 100 ? 0 : v >= 10 ? 1 : 2;
  const signed = n < 0 ? "-" : "";
  return `${signed}${v.toFixed(digits)} ${units[i]}`;
}

export function formatCount(n: number, locale: string = "en"): string {
  try {
    return new Intl.NumberFormat(locale, {
      notation: n >= 10_000 ? "compact" : "standard",
      maximumFractionDigits: n >= 10_000 ? 1 : 0,
    }).format(n);
  } catch {
    return String(n);
  }
}

export function formatMetricValue(
  n: number,
  unit: "count" | "byte",
  locale: string = "en",
): string {
  return unit === "byte" ? formatBytes(n, locale) : formatCount(n, locale);
}

export function formatDelta(
  current: number,
  previous: number,
  _locale: string = "en",
): {label: string; tone: "up" | "down" | "flat"} {
  if (previous <= 0 && current <= 0) {
    return {label: "—", tone: "flat"};
  }
  if (previous <= 0) {
    return {label: "+∞", tone: "up"};
  }
  const pct = ((current - previous) / previous) * 100;
  if (Math.abs(pct) < 0.5) {
    return {label: "0%", tone: "flat"};
  }
  const sign = pct > 0 ? "+" : "";
  const label = `${sign}${pct.toFixed(0)}%`;
  return {label, tone: pct > 0 ? "up" : "down"};
}

export function formatDayLabel(isoDay: string, locale: string = "en"): string {
  const d = new Date(`${isoDay}T12:00:00`);
  if (Number.isNaN(d.getTime())) return isoDay;
  try {
    return new Intl.DateTimeFormat(locale, {
      weekday: "short",
      day: "numeric",
      month: "short",
    }).format(d);
  } catch {
    return isoDay;
  }
}

export function formatShortDay(isoDay: string, locale: string = "en"): string {
  const d = new Date(`${isoDay}T12:00:00`);
  if (Number.isNaN(d.getTime())) return isoDay.slice(5);
  try {
    return new Intl.DateTimeFormat(locale, {day: "numeric", month: "short"}).format(
      d,
    );
  } catch {
    return isoDay.slice(5);
  }
}

export function isoDay(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

export function addDays(iso: string, delta: number): string {
  const d = new Date(`${iso}T12:00:00`);
  d.setDate(d.getDate() + delta);
  return isoDay(d);
}

export function rangeBounds(
  range: "7d" | "30d" | "90d",
  today: Date = new Date(),
): {from: string; to: string} {
  const to = isoDay(today);
  const span = range === "7d" ? 6 : range === "90d" ? 89 : 29;
  return {from: addDays(to, -span), to};
}

export function eachDay(from: string, to: string): string[] {
  const out: string[] = [];
  let cur = from;
  let guard = 0;
  while (cur <= to && guard < 400) {
    out.push(cur);
    cur = addDays(cur, 1);
    guard += 1;
  }
  return out;
}
