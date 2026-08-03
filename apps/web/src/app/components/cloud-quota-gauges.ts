import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/button";
import {css, html, LitElement, nothing, svg} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  ACCOUNT_CHANGED_EVENT,
  isAccountConnected,
  loadAccountSettings,
} from "../account-settings";
import {getAppLocale, tf, tx} from "../i18n";
import {fetchQuotas, type QuotasReport} from "../cloud-api/client";
import {formatBytes} from "../usage/format";
import {ringArc} from "../usage/svg";
import tailwind from "../../css/tailwind";

@customElement("cloud-quota-gauges")
export class CloudQuotaGauges extends LitElement {
  static styles = [
    tailwind,
    css`
      .quota-panel {
        border: 1px solid color-mix(in srgb, var(--sc-base-content) 12%, transparent);
        border-radius: var(--sc-rounded);
        background:
          radial-gradient(
            100% 80% at 100% 0%,
            color-mix(in srgb, var(--sc-info) 10%, transparent),
            transparent 50%
          ),
          color-mix(in srgb, var(--sc-base) 92%, var(--sc-base-content));
        padding: 0.85rem 1rem;
      }

      .rings {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(7.5rem, 1fr));
        gap: 0.75rem;
      }

      .ring-card {
        text-align: center;
      }

      .ring-label {
        font-size: 0.68rem;
        opacity: 0.65;
        margin-top: 0.25rem;
      }

      .ring-value {
        font-family: "IBM Plex Mono", ui-monospace, monospace;
        font-size: 0.72rem;
        font-weight: 600;
      }
    `,
  ];

  @state() private report: QuotasReport | null = null;
  @state() private loading = false;

  private onAccountChanged = () => {
    void this.reload();
  };

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    void this.reload();
  }

  disconnectedCallback() {
    window.removeEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    super.disconnectedCallback();
  }

  private async reload() {
    if (!isAccountConnected()) {
      this.report = null;
      return;
    }
    this.loading = true;
    try {
      this.report = await fetchQuotas(loadAccountSettings());
    } catch {
      this.report = null;
    } finally {
      this.loading = false;
    }
  }

  private ring(
    label: string,
    usedLabel: string,
    ratio: number | null,
    color: string,
  ) {
    const size = 88;
    const cx = size / 2;
    const cy = size / 2;
    const pct = ratio === null ? null : Math.min(1, Math.max(0, ratio));
    const warn = pct !== null && pct >= 0.8;
    const fill = warn ? "var(--sc-warning)" : color;
    const {track, value} = ringArc(cx, cy, 38, 8, pct ?? 0.08);
    return html`
      <div class="ring-card">
        <svg viewBox="0 0 ${size} ${size}" class="w-[5.5rem] h-[5.5rem] mx-auto" role="img" aria-label=${label}>
          ${svg`
            <path d=${track} fill="currentColor" opacity="0.1"></path>
            <path d=${value} fill=${fill}></path>
            <text x=${cx} y=${cy + 4} text-anchor="middle" fill="currentColor" font-size="14" font-weight="700" font-family="IBM Plex Mono, monospace">
              ${pct === null ? "∞" : `${Math.round(pct * 100)}%`}
            </text>
          `}
        </svg>
        <div class="ring-label">${label}</div>
        <div class="ring-value">${usedLabel}</div>
      </div>
    `;
  }

  render() {
    if (!isAccountConnected()) return nothing;
    if (this.loading && !this.report) {
      return html`<p class="text-sm opacity-60">${t("quota.loading")}</p>`;
    }
    if (!this.report) return nothing;

    const locale = getAppLocale();
    const {storage, bandwidth} = this.report;
    const storageLabel = storage.unlimited
      ? tf("quota.storage_unlimited", {
          used: formatBytes(storage.usedBytes, locale),
        })
      : tf("quota.storage_used", {
          used: formatBytes(storage.usedBytes, locale),
          quota: formatBytes(storage.quotaBytes ?? 0, locale),
        });
    const dayLabel = bandwidth.unlimited
      ? tf("quota.bw_day_unlimited", {
          used: formatBytes(bandwidth.dayUsedBytes, locale),
        })
      : tf("quota.bw_day", {
          used: formatBytes(bandwidth.dayUsedBytes, locale),
          quota: formatBytes(bandwidth.dayQuotaBytes ?? 0, locale),
        });
    const monthLabel = bandwidth.unlimited
      ? tf("quota.bw_month_unlimited", {
          used: formatBytes(bandwidth.monthUsedBytes, locale),
        })
      : tf("quota.bw_month", {
          used: formatBytes(bandwidth.monthUsedBytes, locale),
          quota: formatBytes(bandwidth.monthQuotaBytes ?? 0, locale),
        });

    const dayRatio =
      bandwidth.unlimited || !bandwidth.dayQuotaBytes
        ? null
        : bandwidth.dayUsedBytes / bandwidth.dayQuotaBytes;
    const monthRatio =
      bandwidth.unlimited || !bandwidth.monthQuotaBytes
        ? null
        : bandwidth.monthUsedBytes / bandwidth.monthQuotaBytes;

    return html`
      <section class="quota-panel space-y-3">
        <div class="flex items-center justify-between gap-2">
          <h3 class="text-sm font-medium">${t("quota.title")}</h3>
          <sonic-button size="sm" variant="outline" @click=${() => this.reload()}
            >${t("quota.refresh")}</sonic-button
          >
        </div>
        <div class="rings">
          ${this.ring(
            tx("quota.ring.storage"),
            storageLabel,
            storage.unlimited ? null : storage.ratio,
            "var(--sc-primary)",
          )}
          ${this.ring(
            tx("quota.ring.bw_day"),
            dayLabel,
            dayRatio,
            "var(--sc-info)",
          )}
          ${this.ring(
            tx("quota.ring.bw_month"),
            monthLabel,
            monthRatio,
            "var(--sc-success)",
          )}
        </div>
        <p class="text-xs opacity-60">${t("quota.hint")}</p>
      </section>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "cloud-quota-gauges": CloudQuotaGauges;
  }
}
