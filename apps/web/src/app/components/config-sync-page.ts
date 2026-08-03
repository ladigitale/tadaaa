import "@supersoniks/concorde/button";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  ACCOUNT_CHANGED_EVENT,
  isAccountConnected,
  loadAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  getActiveDatasetSyncState,
  getActivePendingCount,
  runDatasetSync,
} from "../sync/engine";
import type {SyncState} from "../sync/outbox-types";
import {showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./cloud-quota-gauges";
import "./config-scope-header";
import "./page-shell";

@customElement("config-sync-page")
export class ConfigSyncPage extends LitElement {
  static styles = [tailwind];

  @state()
  private account: AccountSettings = loadAccountSettings();

  @state()
  private busy = false;

  @state()
  private statusMessage = "";

  @state()
  private syncState: SyncState | null = null;

  @state()
  private pendingSyncCount = 0;

  private onAccountChanged = () => {
    this.account = loadAccountSettings();
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
    this.account = loadAccountSettings();
    if (!isAccountConnected(this.account)) {
      this.syncState = null;
      this.pendingSyncCount = 0;
      return;
    }
    try {
      this.syncState = await getActiveDatasetSyncState();
      this.pendingSyncCount = await getActivePendingCount();
    } catch (error) {
      this.syncState = null;
      this.pendingSyncCount = 0;
      this.statusMessage =
        error instanceof Error ? error.message : tx("dialogs.unknown_error");
      console.error(error);
    }
  }

  private formatDate(value: string): string {
    try {
      return new Date(value).toLocaleString(undefined, {
        dateStyle: "short",
        timeStyle: "short",
      });
    } catch {
      return value;
    }
  }

  private onSyncNow = async () => {
    if (this.busy || !isAccountConnected(this.account)) return;
    this.busy = true;
    this.statusMessage = tx("account.sync.title");
    try {
      const result = await runDatasetSync({fullPull: true});
      if (result.error) {
        this.statusMessage = result.error;
      } else {
        this.statusMessage = tx("account.sync.now");
      }
      this.syncState = await getActiveDatasetSyncState();
      this.pendingSyncCount = await getActivePendingCount();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      this.statusMessage =
        error instanceof Error ? error.message : tx("dialogs.unknown_error");
    } finally {
      this.busy = false;
    }
  };

  render() {
    const connected = isAccountConnected(this.account);
    const lastSync = this.syncState?.lastSyncAt
      ? this.formatDate(this.syncState.lastSyncAt)
      : tx("account.sync.never");

    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="sync"></config-scope-header>
        </div>

        <div class="space-y-6 pt-8">
          ${!connected
            ? html`<account-required-cta
                messageKey="data.need_account"
              ></account-required-cta>`
            : html`
                <div class="flex flex-wrap items-center gap-2 text-sm">
                  <sonic-badge
                    type=${this.pendingSyncCount > 0 ? "warning" : "neutral"}
                    size="sm"
                  >
                    ${tf("account.sync.pending", {n: this.pendingSyncCount})}
                  </sonic-badge>
                  <span class="text-neutral-500"
                    >${t("account.sync.last")} ${lastSync}</span
                  >
                </div>
                ${this.syncState?.lastError
                  ? html`<sonic-alert type="warning" size="sm"
                      >${this.syncState.lastError}</sonic-alert
                    >`
                  : nothing}
                ${this.statusMessage
                  ? html`<p class="text-sm text-neutral-500">
                      ${this.statusMessage}
                    </p>`
                  : nothing}
                <sonic-form-actions>
                  <sonic-button
                    type="primary"
                    size="sm"
                    ?disabled=${this.busy}
                    @click=${this.onSyncNow}
                  >
                    ${t("account.sync.now")}
                  </sonic-button>
                </sonic-form-actions>
                <cloud-quota-gauges></cloud-quota-gauges>
                <p class="text-sm text-neutral-500">${t("account.sync.help")}</p>
              `}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-sync-page": ConfigSyncPage;
  }
}
