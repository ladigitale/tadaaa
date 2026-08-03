import type {UsageReport} from "../cloud-api/client";
import {
  isUsageMetricKey,
  USAGE_METRIC_BY_KEY,
  USAGE_METRICS,
  type MetricUnit,
  type UsageEventCategory,
  type UsageMetricKey,
} from "./catalog";
import {eachDay, formatDelta} from "./format";

/** Elastic Common Schema–inspired document (normalized for charts / export). */
export type UsageEcsDoc = {
  "@timestamp": string;
  "event.dataset": string | null;
  "event.category": UsageEventCategory;
  "event.action": string;
  "metric.name": string;
  "metric.unit": MetricUnit;
  "metric.value": number;
  "labels.metric_key": UsageMetricKey;
};

export type UsageSeriesPoint = {
  day: string;
  value: number;
};

export type UsageSeries = {
  key: UsageMetricKey;
  labelKey: string;
  color: string;
  unit: MetricUnit;
  points: UsageSeriesPoint[];
  total: number;
};

export type UsageKpi = {
  key: UsageMetricKey;
  labelKey: string;
  color: string;
  unit: MetricUnit;
  value: number;
  previous: number;
  delta: ReturnType<typeof formatDelta>;
  sparkline: number[];
};

export type UsageDayStack = {
  day: string;
  parts: Array<{key: UsageMetricKey; value: number; color: string}>;
  total: number;
};

export type NormalizedUsage = {
  from: string;
  to: string;
  docs: UsageEcsDoc[];
  series: UsageSeries[];
  kpis: UsageKpi[];
  stacks: UsageDayStack[];
  categoryTotals: Array<{
    category: UsageEventCategory;
    value: number;
    color: string;
    labelKey: string;
  }>;
  heatmap: Array<{day: string; value: number}>;
  focusKey: UsageMetricKey;
  hasData: boolean;
};

function selectedKeys(metrics: string[]): UsageMetricKey[] {
  const picked = metrics.filter(isUsageMetricKey);
  return picked.length > 0 ? picked : USAGE_METRICS.map((m) => m.key);
}

function aggregateDayCounters(
  report: UsageReport,
  datasetId: string,
): Map<string, Record<string, number>> {
  const map = new Map<string, Record<string, number>>();
  for (const row of report.byDay) {
    if (datasetId && row.datasetId !== datasetId) continue;
    const prev = map.get(row.day) ?? {};
    for (const [k, v] of Object.entries(row.counters)) {
      prev[k] = (prev[k] ?? 0) + v;
    }
    map.set(row.day, prev);
  }
  return map;
}

export function normalizeUsage(
  report: UsageReport,
  opts: {
    metrics: string[];
    datasetId: string;
    focus: UsageMetricKey | "auto";
    locale?: string;
  },
): NormalizedUsage {
  const keys = selectedKeys(opts.metrics);
  const days = eachDay(report.from, report.to);
  const byDay = aggregateDayCounters(report, opts.datasetId);

  const docs: UsageEcsDoc[] = [];
  for (const row of report.byDay) {
    if (opts.datasetId && row.datasetId !== opts.datasetId) continue;
    for (const [rawKey, value] of Object.entries(row.counters)) {
      if (!isUsageMetricKey(rawKey) || !keys.includes(rawKey) || value === 0) {
        continue;
      }
      const def = USAGE_METRIC_BY_KEY[rawKey];
      docs.push({
        "@timestamp": `${row.day}T00:00:00.000Z`,
        "event.dataset": row.datasetId,
        "event.category": def.category,
        "event.action": def.action,
        "metric.name": def.metricName,
        "metric.unit": def.unit,
        "metric.value": value,
        "labels.metric_key": rawKey,
      });
    }
  }

  const series: UsageSeries[] = keys.map((key) => {
    const def = USAGE_METRIC_BY_KEY[key];
    const points = days.map((day) => ({
      day,
      value: byDay.get(day)?.[key] ?? 0,
    }));
    const total = points.reduce((s, p) => s + p.value, 0);
    return {
      key,
      labelKey: def.labelKey,
      color: def.color,
      unit: def.unit,
      points,
      total,
    };
  });

  const mid = Math.floor(days.length / 2);
  const kpis: UsageKpi[] = series.map((s) => {
    const recent = s.points.slice(mid).reduce((a, p) => a + p.value, 0);
    const previous = s.points.slice(0, mid).reduce((a, p) => a + p.value, 0);
    return {
      key: s.key,
      labelKey: s.labelKey,
      color: s.color,
      unit: s.unit,
      value: s.total,
      previous,
      delta: formatDelta(recent, previous, opts.locale),
      sparkline: s.points.map((p) => p.value),
    };
  });

  const stacks: UsageDayStack[] = days.map((day) => {
    const parts = keys
      .map((key) => {
        const value = byDay.get(day)?.[key] ?? 0;
        return {key, value, color: USAGE_METRIC_BY_KEY[key].color};
      })
      .filter((p) => p.value > 0);
    return {
      day,
      parts,
      total: parts.reduce((s, p) => s + p.value, 0),
    };
  });

  const categoryMap = new Map<
    UsageEventCategory,
    {value: number; color: string; labelKey: string}
  >();
  for (const s of series) {
    if (s.unit === "byte") continue;
    const def = USAGE_METRIC_BY_KEY[s.key];
    const prev = categoryMap.get(def.category) ?? {
      value: 0,
      color: def.color,
      labelKey: `usage.category.${def.category}`,
    };
    prev.value += s.total;
    categoryMap.set(def.category, prev);
  }
  const categoryTotals = [...categoryMap.entries()]
    .map(([category, v]) => ({category, ...v}))
    .filter((c) => c.value > 0)
    .sort((a, b) => b.value - a.value);

  const focusKey: UsageMetricKey =
    opts.focus !== "auto" && keys.includes(opts.focus)
      ? opts.focus
      : (series.find((s) => s.total > 0)?.key ?? keys[0] ?? "todos_created");

  const focusSeries = series.find((s) => s.key === focusKey);
  const heatmap = (focusSeries?.points ?? days.map((day) => ({day, value: 0}))).map(
    (p) => ({day: p.day, value: p.value}),
  );

  return {
    from: report.from,
    to: report.to,
    docs,
    series,
    kpis: kpis.filter((k) => k.value > 0 || keys.length <= 4),
    stacks,
    categoryTotals,
    heatmap,
    focusKey,
    hasData: series.some((s) => s.total > 0),
  };
}
