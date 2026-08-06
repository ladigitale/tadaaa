/** Metric catalog — ECS-inspired field names + Prometheus-style metric names. */

export type UsageMetricKey =
  | "todos_created"
  | "todos_updated"
  | "tags_mutated"
  | "mcp_calls"
  | "webhook_deliveries"
  | "webhook_bytes"
  | "webhook_failures"
  | "invites_sent"
  | "datasets_created"
  | "embed_requests"
  | "embed_bytes"
  | "embed_origin_denied";

export type UsageEventCategory =
  | "todo"
  | "tag"
  | "mcp"
  | "webhook"
  | "invite"
  | "dataset"
  | "embed";

export type MetricUnit = "count" | "byte";

export type UsageMetricDef = {
  key: UsageMetricKey;
  /** ECS `event.category` */
  category: UsageEventCategory;
  /** ECS `event.action` */
  action: string;
  /** Prometheus-style `metric.name` */
  metricName: string;
  unit: MetricUnit;
  /** CSS color (theme tokens preferred) */
  color: string;
  icon: string;
  labelKey: string;
};

export const USAGE_METRICS: readonly UsageMetricDef[] = [
  {
    key: "todos_created",
    category: "todo",
    action: "create",
    metricName: "tada.todos.created",
    unit: "count",
    color: "var(--sc-primary)",
    icon: "check-circle",
    labelKey: "usage.metric.todos_created",
  },
  {
    key: "todos_updated",
    category: "todo",
    action: "update",
    metricName: "tada.todos.updated",
    unit: "count",
    color: "var(--sc-info)",
    icon: "edit-pencil",
    labelKey: "usage.metric.todos_updated",
  },
  {
    key: "tags_mutated",
    category: "tag",
    action: "mutate",
    metricName: "tada.tags.mutated",
    unit: "count",
    color: "#7c3aed",
    icon: "label",
    labelKey: "usage.metric.tags_mutated",
  },
  {
    key: "mcp_calls",
    category: "mcp",
    action: "call",
    metricName: "tada.mcp.calls",
    unit: "count",
    color: "#0d9488",
    icon: "terminal",
    labelKey: "usage.metric.mcp_calls",
  },
  {
    key: "webhook_deliveries",
    category: "webhook",
    action: "deliver",
    metricName: "tada.webhook.deliveries",
    unit: "count",
    color: "#ea580c",
    icon: "send-diagonal",
    labelKey: "usage.metric.webhook_deliveries",
  },
  {
    key: "webhook_bytes",
    category: "webhook",
    action: "bandwidth",
    metricName: "tada.webhook.bytes",
    unit: "byte",
    color: "#c2410c",
    icon: "data-transfer-both",
    labelKey: "usage.metric.webhook_bytes",
  },
  {
    key: "webhook_failures",
    category: "webhook",
    action: "failure",
    metricName: "tada.webhook.failures",
    unit: "count",
    color: "var(--sc-danger)",
    icon: "warning-triangle",
    labelKey: "usage.metric.webhook_failures",
  },
  {
    key: "invites_sent",
    category: "invite",
    action: "send",
    metricName: "tada.invites.sent",
    unit: "count",
    color: "var(--sc-success)",
    icon: "mail",
    labelKey: "usage.metric.invites_sent",
  },
  {
    key: "datasets_created",
    category: "dataset",
    action: "create",
    metricName: "tada.datasets.created",
    unit: "count",
    color: "#2563eb",
    icon: "db",
    labelKey: "usage.metric.datasets_created",
  },
  {
    key: "embed_requests",
    category: "embed",
    action: "fetch",
    metricName: "tada.embed.requests",
    unit: "count",
    color: "#0891b2",
    icon: "code",
    labelKey: "usage.metric.embed_requests",
  },
  {
    key: "embed_bytes",
    category: "embed",
    action: "bandwidth",
    metricName: "tada.embed.bytes",
    unit: "byte",
    color: "#0e7490",
    icon: "data-transfer-both",
    labelKey: "usage.metric.embed_bytes",
  },
  {
    key: "embed_origin_denied",
    category: "embed",
    action: "deny",
    metricName: "tada.embed.origin_denied",
    unit: "count",
    color: "var(--sc-danger)",
    icon: "warning-triangle",
    labelKey: "usage.metric.embed_origin_denied",
  },
] as const;

export const USAGE_METRIC_BY_KEY: Record<UsageMetricKey, UsageMetricDef> =
  Object.fromEntries(USAGE_METRICS.map((m) => [m.key, m])) as Record<
    UsageMetricKey,
    UsageMetricDef
  >;

export function isUsageMetricKey(key: string): key is UsageMetricKey {
  return key in USAGE_METRIC_BY_KEY;
}

export type UsageRangePreset = "7d" | "30d" | "90d";

export type UsageFilterForm = {
  range: UsageRangePreset;
  /** Empty = all metrics */
  metrics: string[];
  /** Empty = all datasets */
  datasetId: string;
  /** Admin only: empty = all users */
  userId: string;
  /** Primary metric for heatmap / focus */
  focus: UsageMetricKey | "auto";
  /** Which viz layout to emphasize */
  view: "overview" | "timeseries" | "heatmap" | "bars" | "rings";
};

export const DEFAULT_USAGE_FILTER: UsageFilterForm = {
  range: "30d",
  metrics: [],
  datasetId: "",
  userId: "",
  focus: "auto",
  view: "overview",
};
