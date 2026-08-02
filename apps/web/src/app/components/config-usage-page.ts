import "@supersoniks/concorde/button";
import "@supersoniks/concorde/alert";
import {html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {isAccountConnected} from "../account-settings";
import {fetchUsage, type UsageReport} from "../cloud-api/client";
import {showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";

@customElement("config-usage-page")
export class ConfigUsagePage extends LitElement {
  static styles = [tailwind];

  @state() private connected = false;
  @state() private loading = false;
  @state() private report: UsageReport | null = null;

  connectedCallback() {
    super.connectedCallback();
    void this.reload();
  }

  private async reload() {
    this.connected = isAccountConnected();
    if (!this.connected) {
      this.report = null;
      return;
    }
    this.loading = true;
    try {
      this.report = await fetchUsage();
    } catch (error) {
      await showError(error);
    } finally {
      this.loading = false;
    }
  }

  private formatBytes(n: number): string {
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  }

  private labelFor(key: string): string {
    return key.replace(/_/g, " ");
  }

  render() {
    const totals = this.report?.totals ?? {};
    const keys = Object.keys(totals).sort();
    const hasData = keys.length > 0;

    return html`
      <page-shell>
        <config-scope-header section="usage"></config-scope-header>
        ${!this.connected
          ? html`<sonic-alert type="info">${t("usage.need_account")}</sonic-alert>`
          : html`
              <p class="text-sm opacity-80 mb-4">${t("usage.intro")}</p>
              <sonic-button size="sm" class="mb-4" @click=${() => this.reload()}>
                ${t("usage.refresh")}
              </sonic-button>
              ${this.loading
                ? html`<p class="opacity-60">…</p>`
                : !hasData
                  ? html`<p class="opacity-60">${t("usage.none")}</p>`
                  : html`
                      <h3 class="font-medium mb-2">${t("usage.totals")}</h3>
                      <ul class="text-sm mb-6 space-y-1">
                        ${keys.map((key) => {
                          const value = totals[key] ?? 0;
                          const display =
                            key === "webhook_bytes"
                              ? this.formatBytes(value)
                              : String(value);
                          return html`
                            <li class="flex justify-between gap-4 border-t border-[color:var(--sc-border)] py-1">
                              <span>${this.labelFor(key)}</span>
                              <span class="font-medium">${display}</span>
                            </li>
                          `;
                        })}
                      </ul>
                      <h3 class="font-medium mb-2">${t("usage.by_day")}</h3>
                      <div class="text-sm space-y-2">
                        ${(this.report?.byDay ?? []).map(
                          (row) => html`
                            <div class="border-t border-[color:var(--sc-border)] py-2">
                              <div class="font-medium">
                                ${row.day}
                                ${row.datasetId
                                  ? html`<span class="opacity-60 text-xs ml-2"
                                      >${row.datasetId.slice(0, 8)}…</span
                                    >`
                                  : ""}
                              </div>
                              <div class="opacity-80">
                                ${Object.entries(row.counters)
                                  .map(([k, v]) =>
                                    k === "webhook_bytes"
                                      ? `${this.labelFor(k)}: ${this.formatBytes(v)}`
                                      : `${this.labelFor(k)}: ${v}`,
                                  )
                                  .join(" · ")}
                              </div>
                            </div>
                          `,
                        )}
                      </div>
                    `}
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
