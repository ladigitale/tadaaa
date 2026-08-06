import "@supersoniks/concorde/button";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/badge";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {handle, subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {isAccountConnected, isCloudAdmin} from "../account-settings";
import {
  fetchAdminUsers,
  fetchCloudDatasets,
  fetchUsage,
  type AdminUserInfo,
  type CloudDatasetInfo,
  type UsageReport,
} from "../cloud-api/client";
import {usageFilterKey} from "../dp";
import {getAppLocale, tf, tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {
  DEFAULT_USAGE_FILTER,
  USAGE_METRICS,
  type UsageFilterForm,
  type UsageMetricKey,
  type UsageRangePreset,
} from "../usage/catalog";
import {formatMetricValue, rangeBounds} from "../usage/format";
import {normalizeUsage, type NormalizedUsage} from "../usage/normalize";
import {set, read} from "../../utils/dataprovider";
import {showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./config-scope-header";
import "./page-shell";
import "./pop-select";
import "./usage-viz";

@customElement("config-usage-page")
export class ConfigUsagePage extends LitElement {
  static styles = [tailwind];

  @state() private connected = false;
  @state() private isAdmin = false;
  @state() private loading = false;
  @state() private report: UsageReport | null = null;
  @state() private datasets: CloudDatasetInfo[] = [];
  @state() private adminUsers: AdminUserInfo[] = [];
  @state() private normalized: NormalizedUsage | null = null;
  @state() private dayFocus: string | null = null;
  @state() private ecsOpen = false;

  @subscribe(usageFilterKey)
  @state()
  filter: UsageFilterForm = {...DEFAULT_USAGE_FILTER};

  connectedCallback() {
    super.connectedCallback();
    const existing = read(usageFilterKey.path) as UsageFilterForm | undefined;
    if (!existing?.range) {
      set(usageFilterKey.path, {...DEFAULT_USAGE_FILTER});
    }
    void this.reload();
  }

  @handle(usageFilterKey.range)
  onRangeChange(_range: UsageRangePreset) {
    void this.reload();
  }

  @handle(usageFilterKey.metrics)
  onMetricsChange(_metrics: string[] | string | null) {
    this.recompute();
  }

  @handle(usageFilterKey.datasetId)
  onDatasetChange(_datasetId: string) {
    this.recompute();
  }

  @handle(usageFilterKey.userId)
  onUserIdChange(_userId: string) {
    void this.reload();
  }

  @handle(usageFilterKey.focus)
  onFocusChange(_focus: UsageFilterForm["focus"]) {
    this.recompute();
  }

  @handle(usageFilterKey.view)
  onViewChange(_view: UsageFilterForm["view"]) {
    this.requestUpdate();
  }

  private async reload() {
    this.connected = isAccountConnected();
    this.isAdmin = isCloudAdmin();
    if (!this.connected) {
      this.report = null;
      this.normalized = null;
      this.adminUsers = [];
      return;
    }
    this.loading = true;
    try {
      const filter = (read(usageFilterKey.path) as UsageFilterForm) ?? this.filter;
      const {from, to} = rangeBounds(filter.range ?? "30d");
      const usageOpts = this.isAdmin
        ? {userId: filter.userId ?? ""}
        : {};
      const [report, datasets, adminUsers] = await Promise.all([
        fetchUsage(from, to, usageOpts),
        fetchCloudDatasets().catch(() => [] as CloudDatasetInfo[]),
        this.isAdmin
          ? fetchAdminUsers().catch(() => [] as AdminUserInfo[])
          : Promise.resolve([] as AdminUserInfo[]),
      ]);
      this.report = report;
      this.datasets = datasets;
      this.adminUsers = adminUsers;
      this.recompute();
    } catch (error) {
      await showError(error);
    } finally {
      this.loading = false;
    }
  }

  private recompute() {
    if (!this.report) {
      this.normalized = null;
      return;
    }
    const filter = (read(usageFilterKey.path) as UsageFilterForm) ?? this.filter;
    const metrics = Array.isArray(filter.metrics)
      ? filter.metrics
      : filter.metrics
        ? [String(filter.metrics)]
        : [];
    this.normalized = normalizeUsage(this.report, {
      metrics,
      datasetId: filter.datasetId ?? "",
      focus: filter.focus ?? "auto",
      locale: getAppLocale(),
    });
  }

  private onFocusEvent = (e: Event) => {
    const key = (e as CustomEvent<{key: UsageMetricKey}>).detail?.key;
    if (!key) return;
    const filter = (read(usageFilterKey.path) as UsageFilterForm) ?? this.filter;
    set(usageFilterKey.path, {...filter, focus: key});
  };

  private onDayEvent = (e: Event) => {
    const day = (e as CustomEvent<{day: string}>).detail?.day;
    this.dayFocus = day ?? null;
  };

  private setRange(range: UsageRangePreset) {
    const filter = (read(usageFilterKey.path) as UsageFilterForm) ?? this.filter;
    set(usageFilterKey.path, {...filter, range});
    void this.reload();
  }

  private setView(view: UsageFilterForm["view"]) {
    const filter = (read(usageFilterKey.path) as UsageFilterForm) ?? this.filter;
    set(usageFilterKey.path, {...filter, view});
  }

  private dayDetail() {
    if (!this.dayFocus || !this.normalized) return nothing;
    const stack = this.normalized.stacks.find((s) => s.day === this.dayFocus);
    if (!stack) return nothing;
    return html`
      <sonic-alert status="info" class="mb-4">
        <div class="font-medium mb-1">${tf("usage.day_focus", {day: this.dayFocus})}</div>
        <div class="text-sm space-y-0.5">
          ${stack.parts.length === 0
            ? html`<span class="opacity-70">${t("usage.day_empty")}</span>`
            : stack.parts.map(
                (p) => html`
                  <div class="flex justify-between gap-4">
                    <span>${t(USAGE_METRICS.find((m) => m.key === p.key)!.labelKey)}</span>
                    <span class="font-mono"
                      >${formatMetricValue(
                        p.value,
                        USAGE_METRICS.find((m) => m.key === p.key)!.unit,
                        getAppLocale(),
                      )}</span
                    >
                  </div>
                `,
              )}
        </div>
        <sonic-button
          size="xs"
          variant="ghost"
          class="mt-2"
          @click=${() => {
            this.dayFocus = null;
          }}
          >${t("usage.day_clear")}</sonic-button
        >
      </sonic-alert>
    `;
  }

  private renderFilters() {
    const metricOptions = [
      {value: "__all__", label: tx("usage.filter.metrics_all"), checksAll: true},
      ...USAGE_METRICS.map((m) => ({
        value: m.key,
        label: tx(m.labelKey),
        icon: m.icon,
      })),
    ];
    const datasetOptions = [
      {value: "", label: tx("usage.filter.dataset_all")},
      ...this.datasets.map((d) => ({
        value: d.id,
        label: d.name || d.id.slice(0, 8),
      })),
    ];
    const focusOptions = [
      {value: "auto", label: tx("usage.filter.focus_auto")},
      ...USAGE_METRICS.map((m) => ({value: m.key, label: tx(m.labelKey)})),
    ];
    const selectedMetrics = Array.isArray(this.filter.metrics)
      ? this.filter.metrics
      : [];
    const userOptions = [
      {value: "", label: tx("usage.filter.user_all")},
      ...this.adminUsers
        .filter((u) => u.status === "active")
        .map((u) => ({
          value: u.id,
          label: u.email,
        })),
    ];

    return html`
      <div
        class="mb-5 space-y-3 rounded-[var(--sc-rounded)] border border-current/10 p-3"
        formDataProvider=${usageFilterKey.path}
      >
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs font-semibold uppercase tracking-wide opacity-55 mr-1"
            >${t("usage.filter.range")}</span
          >
          ${(["7d", "30d", "90d"] as const).map(
            (range) => html`
              <sonic-button
                size="sm"
                variant=${this.filter.range === range ? "default" : "outline"}
                type=${this.filter.range === range ? "primary" : "neutral"}
                @click=${() => this.setRange(range)}
                >${t(`usage.filter.range_${range}`)}</sonic-button
              >
            `,
          )}
          <span class="flex-1"></span>
          <sonic-button size="sm" variant="outline" @click=${() => this.reload()}>
            <sonic-icon
              slot="prefix"
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="refresh"
              size="sm"
            ></sonic-icon>
            ${t("usage.refresh")}
          </sonic-button>
        </div>

        <div class="flex flex-wrap gap-3 items-end">
          ${this.isAdmin
            ? html`
                <pop-select
                  showLabel
                  label=${t("usage.filter.user")}
                  name="userId"
                  mode="radio"
                  size="sm"
                  variant="outline"
                  minWidth="14rem"
                  .value=${this.filter.userId ?? ""}
                  .options=${userOptions}
                ></pop-select>
              `
            : nothing}
          <pop-select
            showLabel
            label=${t("usage.filter.metrics")}
            name="metrics"
            mode="multi"
            size="sm"
            variant="outline"
            minWidth="12rem"
            .value=${selectedMetrics}
            .options=${metricOptions}
          ></pop-select>
          <pop-select
            showLabel
            label=${t("usage.filter.dataset")}
            name="datasetId"
            mode="radio"
            size="sm"
            variant="outline"
            minWidth="10rem"
            .value=${this.filter.datasetId ?? ""}
            .options=${datasetOptions}
          ></pop-select>
          <pop-select
            showLabel
            label=${t("usage.filter.focus")}
            name="focus"
            mode="radio"
            size="sm"
            variant="outline"
            minWidth="10rem"
            .value=${this.filter.focus ?? "auto"}
            .options=${focusOptions}
          ></pop-select>
        </div>

        <div class="flex flex-wrap gap-2 pt-1">
          <span class="text-xs font-semibold uppercase tracking-wide opacity-55 self-center mr-1"
            >${t("usage.filter.view")}</span
          >
          ${(
            [
              ["overview", "usage.filter.view_overview"],
              ["timeseries", "usage.filter.view_timeseries"],
              ["heatmap", "usage.filter.view_heatmap"],
              ["bars", "usage.filter.view_bars"],
              ["rings", "usage.filter.view_rings"],
            ] as const
          ).map(
            ([view, key]) => html`
              <sonic-button
                size="xs"
                variant=${this.filter.view === view ? "default" : "ghost"}
                type=${this.filter.view === view ? "primary" : "neutral"}
                @click=${() => this.setView(view)}
                >${t(key)}</sonic-button
              >
            `,
          )}
        </div>
      </div>
    `;
  }

  private renderCharts() {
    const n = this.normalized;
    if (!n) return nothing;
    if (!n.hasData) {
      return html`<p class="opacity-60">${t("usage.none")}</p>`;
    }

    const view = this.filter.view ?? "overview";
    const focusUnit =
      USAGE_METRICS.find((m) => m.key === n.focusKey)?.unit ?? "count";

    const kpi = html`<usage-kpi-strip
      .kpis=${n.kpis}
      active=${n.focusKey}
      @usage-focus=${this.onFocusEvent}
    ></usage-kpi-strip>`;
    const ts = html`<usage-timeseries-chart
      .series=${n.series}
    ></usage-timeseries-chart>`;
    const heat = html`<usage-heatmap-chart
      .cells=${n.heatmap}
      focusKey=${n.focusKey}
      unit=${focusUnit}
      @usage-day=${this.onDayEvent}
    ></usage-heatmap-chart>`;
    const bars = html`<usage-bars-chart .stacks=${n.stacks}></usage-bars-chart>`;
    const rings = html`<usage-rings-chart
      .categories=${n.categoryTotals}
    ></usage-rings-chart>`;
    const mix = html`<usage-mix-chart .series=${n.series}></usage-mix-chart>`;

    if (view === "timeseries") return html`<div class="space-y-4">${kpi}${ts}</div>`;
    if (view === "heatmap") return html`<div class="space-y-4">${kpi}${heat}</div>`;
    if (view === "bars") return html`<div class="space-y-4">${kpi}${bars}${mix}</div>`;
    if (view === "rings") return html`<div class="space-y-4">${kpi}${rings}${mix}</div>`;

    return html`
      <div class="space-y-4">
        ${kpi}
        ${ts}
        <div class="grid gap-4 lg:grid-cols-2">
          ${heat}
          ${rings}
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          ${bars}
          ${mix}
        </div>
      </div>
    `;
  }

  private renderEcsPanel() {
    const docs = this.normalized?.docs ?? [];
    if (docs.length === 0) return nothing;
    const sample = docs.slice(0, 8);
    return html`
      <details
        class="mt-6 rounded-[var(--sc-rounded)] border border-current/10 p-3"
        ?open=${this.ecsOpen}
        @toggle=${(e: Event) => {
          this.ecsOpen = (e.target as HTMLDetailsElement).open;
        }}
      >
        <summary class="cursor-pointer text-sm font-medium">
          ${t("usage.ecs.title")}
          <sonic-badge size="xs" type="neutral" class="ml-2"
            >${docs.length}</sonic-badge
          >
        </summary>
        <p class="text-xs opacity-60 mt-2 mb-3">${t("usage.ecs.help")}</p>
        <pre
          class="text-[0.65rem] leading-snug overflow-x-auto rounded bg-black/5 dark:bg-white/5 p-3 font-mono"
        >${JSON.stringify(sample, null, 2)}</pre>
      </details>
    `;
  }

  render() {
    return html`
      <page-shell>
        <config-scope-header section="usage"></config-scope-header>
        ${!this.connected
          ? html`<account-required-cta
              messageKey="usage.need_account"
            ></account-required-cta>`
          : html`
              <p class="text-sm opacity-80 mb-4">
                ${t(this.isAdmin ? "usage.intro_admin" : "usage.intro")}
              </p>
              ${this.renderFilters()}
              ${this.dayDetail()}
              ${this.loading && !this.report
                ? html`<p class="opacity-60">${t("usage.loading")}</p>`
                : this.renderCharts()}
              ${this.renderEcsPanel()}
            `}
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-usage-page": ConfigUsagePage;
  }
}
