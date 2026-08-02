import "@supersoniks/concorde/button";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/alert";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {isAccountConnected} from "../account-settings";
import {tx} from "../i18n";
import {fetchActivity, type ActivityLogInfo} from "../cloud-api/client";
import {showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";

@customElement("config-activity-page")
export class ConfigActivityPage extends LitElement {
  static styles = [tailwind];

  @state() private connected = false;
  @state() private loading = false;
  @state() private rows: ActivityLogInfo[] = [];
  @state() private category = "";

  connectedCallback() {
    super.connectedCallback();
    void this.reload();
  }

  private async reload() {
    this.connected = isAccountConnected();
    if (!this.connected) {
      this.rows = [];
      return;
    }
    this.loading = true;
    try {
      this.rows = await fetchActivity(this.category || undefined, 100);
    } catch (error) {
      await showError(error);
    } finally {
      this.loading = false;
    }
  }

  private formatDate(value: string): string {
    try {
      return new Date(value).toLocaleString("fr-FR", {
        dateStyle: "short",
        timeStyle: "medium",
      });
    } catch {
      return value;
    }
  }

  render() {
    return html`
      <page-shell>
        <config-scope-header section="activity"></config-scope-header>
        ${!this.connected
          ? html`<sonic-alert type="info">${t("activity.need_account")}</sonic-alert>`
          : html`
              <p class="text-sm opacity-80 mb-4">${t("activity.intro")}</p>
              <div class="flex flex-wrap items-center gap-3 mb-4">
                <label class="text-sm flex items-center gap-2">
                  <span>${t("activity.filter")}</span>
                  <select
                    class="rounded border border-[color:var(--sc-border)] bg-transparent px-2 py-1"
                    .value=${this.category}
                    @change=${(e: Event) => {
                      this.category = (e.target as HTMLSelectElement).value;
                      void this.reload();
                    }}
                  >
                    <option value="">${tx("activity.filter_all")}</option>
                    <option value="webhook">webhook</option>
                    <option value="mcp">mcp</option>
                    <option value="token">token</option>
                    <option value="oauth">oauth</option>
                  </select>
                </label>
                <sonic-button size="sm" @click=${() => this.reload()}>
                  ${t("activity.refresh")}
                </sonic-button>
              </div>
              ${this.loading
                ? html`<p class="opacity-60">…</p>`
                : this.rows.length === 0
                  ? html`<p class="opacity-60">${t("activity.none")}</p>`
                  : this.rows.map(
                      (row) => html`
                        <div
                          class="border-t border-[color:var(--sc-border)] py-3 text-sm"
                        >
                          <div class="flex flex-wrap gap-2 items-center">
                            <sonic-badge size="sm">${row.category}</sonic-badge>
                            <span class="font-medium">${row.action}</span>
                            <span class="opacity-60">${this.formatDate(row.createdAt)}</span>
                          </div>
                          ${Object.keys(row.meta).length
                            ? html`<pre
                                class="mt-1 text-xs opacity-70 overflow-x-auto whitespace-pre-wrap"
                              >
${JSON.stringify(row.meta, null, 2)}</pre
                              >`
                            : nothing}
                        </div>
                      `,
                    )}
            `}
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-activity-page": ConfigActivityPage;
  }
}
