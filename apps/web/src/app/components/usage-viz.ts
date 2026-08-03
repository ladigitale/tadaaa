import {css, html, LitElement, nothing, svg} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {getAppLocale, tx} from "../i18n";
import type {UsageMetricKey} from "../usage/catalog";
import {USAGE_METRIC_BY_KEY} from "../usage/catalog";
import {
  formatDayLabel,
  formatMetricValue,
  formatShortDay,
} from "../usage/format";
import type {
  UsageDayStack,
  UsageKpi,
  UsageSeries,
} from "../usage/normalize";
import {arcPath, areaPath, niceMax, ringArc, sparkPath} from "../usage/svg";
import tailwind from "../../css/tailwind";

const vizCss = css`
  :host {
    display: block;
  }

  .panel {
    border: 1px solid color-mix(in srgb, var(--sc-base-content) 12%, transparent);
    border-radius: var(--sc-rounded);
    background:
      radial-gradient(
        120% 80% at 0% 0%,
        color-mix(in srgb, var(--sc-primary) 8%, transparent),
        transparent 55%
      ),
      color-mix(in srgb, var(--sc-base) 92%, var(--sc-base-content));
    padding: 0.85rem 1rem;
  }

  .panel-title {
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    opacity: 0.65;
    margin-bottom: 0.65rem;
  }

  .mono {
    font-family: "IBM Plex Mono", ui-monospace, monospace;
  }

  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
    gap: 0.65rem;
  }

  .kpi {
    position: relative;
    overflow: hidden;
    border-radius: calc(var(--sc-rounded) - 2px);
    border: 1px solid color-mix(in srgb, var(--sc-base-content) 10%, transparent);
    padding: 0.7rem 0.75rem 0.55rem;
    cursor: pointer;
    transition:
      transform 160ms ease,
      border-color 160ms ease,
      box-shadow 160ms ease;
  }

  .kpi:hover,
  .kpi[data-active] {
    transform: translateY(-1px);
    border-color: color-mix(in srgb, var(--kpi-color, var(--sc-primary)) 55%, transparent);
    box-shadow: 0 8px 20px -12px color-mix(in srgb, var(--kpi-color, var(--sc-primary)) 55%, transparent);
  }

  .kpi::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 3px;
    background: var(--kpi-color, var(--sc-primary));
  }

  .delta-up {
    color: var(--sc-success);
  }
  .delta-down {
    color: var(--sc-danger);
  }
  .delta-flat {
    opacity: 0.55;
  }

  .chart-wrap {
    position: relative;
    width: 100%;
  }

  .tooltip {
    position: absolute;
    z-index: 2;
    pointer-events: none;
    transform: translate(-50%, calc(-100% - 8px));
    background: var(--sc-base-content);
    color: var(--sc-base);
    font-size: 0.7rem;
    line-height: 1.35;
    padding: 0.35rem 0.5rem;
    border-radius: 0.35rem;
    white-space: nowrap;
    max-width: 16rem;
    box-shadow: 0 8px 24px -10px rgb(0 0 0 / 0.35);
  }

  .legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.75rem;
    margin-top: 0.65rem;
  }

  .legend button {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.72rem;
    background: transparent;
    border: 0;
    padding: 0;
    cursor: pointer;
    color: inherit;
    opacity: 0.85;
  }

  .legend button[data-off] {
    opacity: 0.35;
    text-decoration: line-through;
  }

  .swatch {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 999px;
    background: var(--c);
  }

  .heat-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 3px;
  }

  .heat-cell {
    aspect-ratio: 1;
    border-radius: 3px;
    border: 0;
    padding: 0;
    cursor: pointer;
    transition: transform 120ms ease, outline-color 120ms ease;
  }

  .heat-cell:hover,
  .heat-cell:focus-visible {
    transform: scale(1.12);
    outline: 2px solid var(--sc-primary);
    outline-offset: 1px;
    z-index: 1;
  }

  .bar-row {
    display: grid;
    grid-template-columns: 4.2rem 1fr 3.2rem;
    gap: 0.5rem;
    align-items: center;
    font-size: 0.72rem;
  }

  .bar-track {
    display: flex;
    height: 0.7rem;
    border-radius: 999px;
    overflow: hidden;
    background: color-mix(in srgb, var(--sc-base-content) 8%, transparent);
  }

  .bar-seg {
    height: 100%;
    min-width: 0;
  }

  @keyframes usage-draw {
    from {
      stroke-dashoffset: 1;
    }
    to {
      stroke-dashoffset: 0;
    }
  }

  .draw-line {
    stroke-dasharray: 1;
    stroke-dashoffset: 1;
    animation: usage-draw 900ms ease forwards;
  }
`;

@customElement("usage-kpi-strip")
export class UsageKpiStrip extends LitElement {
  static styles = [tailwind, vizCss];

  @property({attribute: false}) kpis: UsageKpi[] = [];
  @property({type: String}) active: string = "";

  private locale = getAppLocale();

  private onPick(key: UsageMetricKey) {
    this.dispatchEvent(
      new CustomEvent("usage-focus", {
        detail: {key},
        bubbles: true,
        composed: true,
      }),
    );
  }

  render() {
    if (this.kpis.length === 0) return nothing;
    return html`
      <div class="panel">
        <div class="panel-title">${t("usage.viz.kpis")}</div>
        <div class="kpi-grid">
          ${this.kpis.map((kpi) => {
            const path = sparkPath(kpi.sparkline, 120, 36);
            const tone =
              kpi.delta.tone === "up"
                ? "delta-up"
                : kpi.delta.tone === "down"
                  ? "delta-down"
                  : "delta-flat";
            return html`
              <button
                type="button"
                class="kpi text-left"
                style="--kpi-color:${kpi.color}"
                ?data-active=${this.active === kpi.key}
                @click=${() => this.onPick(kpi.key)}
              >
                <div class="text-[0.68rem] opacity-60 truncate">
                  ${t(kpi.labelKey)}
                </div>
                <div class="mono text-lg font-semibold leading-tight mt-0.5">
                  ${formatMetricValue(kpi.value, kpi.unit, this.locale)}
                </div>
                <div class="flex items-end justify-between gap-2 mt-1">
                  <span class="text-[0.68rem] ${tone} mono">${kpi.delta.label}</span>
                  <svg width="72" height="28" viewBox="0 0 120 36" aria-hidden="true">
                    <path
                      d=${path}
                      fill="none"
                      stroke=${kpi.color}
                      stroke-width="2.2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      opacity="0.9"
                    />
                  </svg>
                </div>
              </button>
            `;
          })}
        </div>
      </div>
    `;
  }
}

@customElement("usage-timeseries-chart")
export class UsageTimeseriesChart extends LitElement {
  static styles = [tailwind, vizCss];

  @property({attribute: false}) series: UsageSeries[] = [];
  @state() private off = new Set<string>();
  @state() private tip: {x: number; y: number; html: string} | null = null;

  private locale = getAppLocale();

  private toggle(key: string) {
    const next = new Set(this.off);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    this.off = next;
  }

  private onMove(e: MouseEvent, width: number, days: string[], max: number) {
    const rect = (e.currentTarget as SVGElement).getBoundingClientRect();
    const x = e.clientX - rect.left;
    const idx = Math.round((x / width) * Math.max(days.length - 1, 0));
    const i = Math.min(days.length - 1, Math.max(0, idx));
    const day = days[i]!;
    const lines = this.series
      .filter((s) => !this.off.has(s.key))
      .map((s) => {
        const v = s.points[i]?.value ?? 0;
        return `${tx(s.labelKey)}: ${formatMetricValue(v, s.unit, this.locale)}`;
      });
    this.tip = {
      x: (i / Math.max(days.length - 1, 1)) * width,
      y: 12,
      html: `${formatDayLabel(day, this.locale)} · ${lines.join(" · ")}`,
    };
    void max;
  }

  render() {
    const visible = this.series.filter((s) => !this.off.has(s.key));
    if (this.series.length === 0) return nothing;
    const days = this.series[0]?.points.map((p) => p.day) ?? [];
    const width = 640;
    const height = 180;
    const max = niceMax(Math.max(0, ...visible.flatMap((s) => s.points.map((p) => p.value))));

    return html`
      <div class="panel">
        <div class="panel-title">${t("usage.viz.timeseries")}</div>
        <div class="chart-wrap">
          ${this.tip
            ? html`<div class="tooltip" style="left:${this.tip.x}px;top:${this.tip.y}px">
                ${this.tip.html}
              </div>`
            : nothing}
          <svg
            viewBox="0 0 ${width} ${height}"
            class="w-full h-auto"
            role="img"
            aria-label=${tx("usage.viz.timeseries")}
            @mousemove=${(e: MouseEvent) => this.onMove(e, width, days, max)}
            @mouseleave=${() => {
              this.tip = null;
            }}
          >
            ${[0, 0.25, 0.5, 0.75, 1].map((r) => {
              const y = height - 4 - r * (height - 8);
              return svg`<line x1="0" x2=${width} y1=${y} y2=${y} stroke="currentColor" opacity="0.08" />`;
            })}
            ${visible.map((s) => {
              const values = s.points.map((p) => p.value);
              const {line, area} = areaPath(values, width, height, max);
              return svg`
                <path d=${area} fill=${s.color} opacity="0.12"></path>
                <path
                  class="draw-line"
                  pathLength="1"
                  d=${line}
                  fill="none"
                  stroke=${s.color}
                  stroke-width="2.4"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                ></path>
              `;
            })}
          </svg>
        </div>
        <div class="legend">
          ${this.series.map(
            (s) => html`
              <button
                type="button"
                ?data-off=${this.off.has(s.key)}
                @click=${() => this.toggle(s.key)}
              >
                <span class="swatch" style="--c:${s.color}"></span>
                <span>${t(s.labelKey)}</span>
                <span class="mono opacity-60"
                  >${formatMetricValue(s.total, s.unit, this.locale)}</span
                >
              </button>
            `,
          )}
        </div>
      </div>
    `;
  }
}

@customElement("usage-heatmap-chart")
export class UsageHeatmapChart extends LitElement {
  static styles = [tailwind, vizCss];

  @property({attribute: false}) cells: Array<{day: string; value: number}> = [];
  @property({type: String}) focusKey: string = "";
  @property({type: String}) unit: "count" | "byte" = "count";

  private locale = getAppLocale();

  private onDay(day: string) {
    this.dispatchEvent(
      new CustomEvent("usage-day", {
        detail: {day},
        bubbles: true,
        composed: true,
      }),
    );
  }

  render() {
    if (this.cells.length === 0) return nothing;
    const max = Math.max(1, ...this.cells.map((c) => c.value));
    const focusDef = this.focusKey
      ? USAGE_METRIC_BY_KEY[this.focusKey as UsageMetricKey]
      : undefined;
    const label = focusDef ? tx(focusDef.labelKey) : tx("usage.viz.heatmap");

    // Pad to week start (Mon=0 style via JS getDay)
    const first = this.cells[0]!;
    const firstDate = new Date(`${first.day}T12:00:00`);
    const pad = (firstDate.getDay() + 6) % 7; // Monday-first
    const pads = Array.from({length: pad}, (_, i) => i);

    return html`
      <div class="panel">
        <div class="panel-title">${t("usage.viz.heatmap")} · ${label}</div>
        <div class="heat-grid" role="grid" aria-label=${tx("usage.viz.heatmap")}>
          ${pads.map(
            () => html`<div class="aspect-square rounded-[3px] opacity-0"></div>`,
          )}
          ${this.cells.map((c) => {
            const intensity = c.value <= 0 ? 0.08 : 0.18 + 0.82 * (c.value / max);
            const title = `${formatDayLabel(c.day, this.locale)}: ${formatMetricValue(c.value, this.unit, this.locale)}`;
            return html`
              <button
                type="button"
                class="heat-cell"
                title=${title}
                style="background: color-mix(in srgb, var(--sc-primary) ${Math.round(intensity * 100)}%, color-mix(in srgb, var(--sc-base-content) 8%, transparent))"
                @click=${() => this.onDay(c.day)}
              ></button>
            `;
          })}
        </div>
        <div class="flex justify-between text-[0.65rem] opacity-55 mt-2 mono">
          <span>${formatShortDay(this.cells[0]!.day, this.locale)}</span>
          <span>${formatShortDay(this.cells[this.cells.length - 1]!.day, this.locale)}</span>
        </div>
      </div>
    `;
  }
}

@customElement("usage-bars-chart")
export class UsageBarsChart extends LitElement {
  static styles = [tailwind, vizCss];

  @property({attribute: false}) stacks: UsageDayStack[] = [];

  private locale = getAppLocale();

  render() {
    const rows = this.stacks.filter((s) => s.total > 0).slice(-14);
    if (rows.length === 0) {
      return html`
        <div class="panel">
          <div class="panel-title">${t("usage.viz.bars")}</div>
          <p class="text-sm opacity-55">${t("usage.none")}</p>
        </div>
      `;
    }
    const max = Math.max(...rows.map((r) => r.total), 1);
    return html`
      <div class="panel">
        <div class="panel-title">${t("usage.viz.bars")}</div>
        <div class="space-y-1.5">
          ${rows.map((row) => {
            return html`
              <div class="bar-row">
                <span class="opacity-60 truncate" title=${row.day}
                  >${formatShortDay(row.day, this.locale)}</span
                >
                <div class="bar-track" title=${row.day}>
                  ${row.parts.map((p) => {
                    const pct = (p.value / max) * 100;
                    return html`<div
                      class="bar-seg"
                      style="width:${pct}%;background:${p.color}"
                      title=${`${tx(USAGE_METRIC_BY_KEY[p.key].labelKey)}: ${p.value}`}
                    ></div>`;
                  })}
                </div>
                <span class="mono text-right opacity-70">${formatMetricValue(row.total, "count", this.locale)}</span>
              </div>
            `;
          })}
        </div>
      </div>
    `;
  }
}

@customElement("usage-rings-chart")
export class UsageRingsChart extends LitElement {
  static styles = [tailwind, vizCss];

  @property({attribute: false})
  categories: Array<{
    category: string;
    value: number;
    color: string;
    labelKey: string;
  }> = [];

  private locale = getAppLocale();

  render() {
    if (this.categories.length === 0) {
      return html`
        <div class="panel">
          <div class="panel-title">${t("usage.viz.rings")}</div>
          <p class="text-sm opacity-55">${t("usage.none")}</p>
        </div>
      `;
    }
    const total = this.categories.reduce((s, c) => s + c.value, 0) || 1;
    const size = 160;
    const cx = size / 2;
    const cy = size / 2;
    const rings = this.categories.slice(0, 4);

    return html`
      <div class="panel">
        <div class="panel-title">${t("usage.viz.rings")}</div>
        <div class="flex flex-col sm:flex-row items-center gap-4">
          <svg viewBox="0 0 ${size} ${size}" class="w-40 h-40 shrink-0" role="img">
            ${rings.map((cat, i) => {
              const r = 72 - i * 16;
              const {track, value} = ringArc(cx, cy, r, 10, cat.value / total);
              return svg`
                <path d=${track} fill="currentColor" opacity="0.08"></path>
                <path d=${value} fill=${cat.color}></path>
              `;
            })}
            <text
              x=${cx}
              y=${cy - 2}
              text-anchor="middle"
              class="mono"
              fill="currentColor"
              font-size="18"
              font-weight="600"
            >
              ${formatMetricValue(total, "count", this.locale)}
            </text>
            <text
              x=${cx}
              y=${cy + 14}
              text-anchor="middle"
              fill="currentColor"
              font-size="9"
              opacity="0.55"
            >
              ${t("usage.viz.events")}
            </text>
          </svg>
          <div class="flex flex-col items-start gap-2">
            ${rings.map(
              (cat) => html`
                <div class="inline-flex items-center gap-2 text-sm">
                  <span class="swatch" style="--c:${cat.color}"></span>
                  <span>${t(cat.labelKey)}</span>
                  <span class="mono opacity-60"
                    >${formatMetricValue(cat.value, "count", this.locale)}</span
                  >
                </div>
              `,
            )}
          </div>
        </div>
      </div>
    `;
  }
}

@customElement("usage-mix-chart")
export class UsageMixChart extends LitElement {
  static styles = [tailwind, vizCss];

  @property({attribute: false}) series: UsageSeries[] = [];

  private locale = getAppLocale();

  render() {
    const parts = this.series.filter((s) => s.unit === "count" && s.total > 0);
    if (parts.length === 0) {
      return html`
        <div class="panel">
          <div class="panel-title">${t("usage.viz.mix")}</div>
          <p class="text-sm opacity-55">${t("usage.none")}</p>
        </div>
      `;
    }
    const total = parts.reduce((s, p) => s + p.total, 0);
    const size = 140;
    const cx = size / 2;
    const cy = size / 2;
    let angle = -90;
    const slices = parts.map((p) => {
      const sweep = (p.total / total) * 360;
      const start = angle;
      angle += sweep;
      return {p, path: arcPath(cx, cy, 58, 28, start, angle)};
    });

    return html`
      <div class="panel">
        <div class="panel-title">${t("usage.viz.mix")}</div>
        <div class="flex flex-col sm:flex-row items-center gap-4">
          <svg viewBox="0 0 ${size} ${size}" class="w-36 h-36 shrink-0">
            ${slices.map(
              (s) => svg`<path d=${s.path} fill=${s.p.color}>
                <title>${tx(s.p.labelKey)}: ${formatMetricValue(s.p.total, "count", this.locale)}</title>
              </path>`,
            )}
          </svg>
          <div class="space-y-1 w-full">
            ${parts.map((p) => {
              const pct = Math.round((p.total / total) * 100);
              return html`
                <div class="flex items-center gap-2 text-xs">
                  <span class="swatch" style="--c:${p.color}"></span>
                  <span class="flex-1 truncate">${t(p.labelKey)}</span>
                  <span class="mono opacity-70">${pct}%</span>
                </div>
              `;
            })}
          </div>
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "usage-kpi-strip": UsageKpiStrip;
    "usage-timeseries-chart": UsageTimeseriesChart;
    "usage-heatmap-chart": UsageHeatmapChart;
    "usage-bars-chart": UsageBarsChart;
    "usage-rings-chart": UsageRingsChart;
    "usage-mix-chart": UsageMixChart;
  }
}
