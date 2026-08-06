import "@supersoniks/concorde/button";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {tf, tx} from "../i18n";
import {areWebNotificationsEnabled} from "../settings";
import {
  disableWebNotifications,
  enableWebNotifications,
  notificationPermission,
} from "../notifications/web-notifications";
import {
  probePushStatus,
  requestPushTest,
  subscribePushStatus,
  subscribeServerPush,
  type PushStatus,
  type PushStatusCode,
} from "../notifications/push-subscribe";
import {
  fetchNotificationPreferences,
  updateNotificationPreferences,
  type NotificationPreferenceRow,
} from "../cloud-api/client";
import {isAccountConnected} from "../account-settings";
import {showAlert} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./config-scope-header";
import "./page-shell";

const PREF_LABEL_KEYS: Record<string, string> = {
  dataset_invite: "notif.pref.dataset_invite",
  member_joined: "notif.pref.member_joined",
  todo_checked: "notif.pref.todo_checked",
  todo_unchecked: "notif.pref.todo_unchecked",
  todo_deleted: "notif.pref.todo_deleted",
  todo_created: "notif.pref.todo_created",
};

const STATUS_LABEL: Record<PushStatusCode, string> = {
  ready: "notif.status.ready",
  checking: "notif.status.checking",
  local_only: "notif.status.local_only",
  need_account: "notif.status.need_account",
  need_permission: "notif.status.need_permission",
  permission_denied: "notif.status.permission_denied",
  unsupported: "notif.status.unsupported",
  server_disabled: "notif.status.server_disabled",
  no_service_worker: "notif.status.no_service_worker",
  not_subscribed: "notif.status.not_subscribed",
  server_missing: "notif.status.server_missing",
  register_failed: "notif.status.register_failed",
  offline: "notif.status.offline",
};

@customElement("config-notifications-page")
export class ConfigNotificationsPage extends LitElement {
  static styles = [tailwind];

  @state()
  private webNotifications = areWebNotificationsEnabled();

  @state()
  private notifPermission: ReturnType<typeof notificationPermission> =
    notificationPermission();

  @state()
  private pushStatus: PushStatus | null = null;

  @state()
  private pushBusy = false;

  @state()
  private testBusy = false;

  @state()
  private prefs: NotificationPreferenceRow[] = [];

  @state()
  private prefsLoading = false;

  @state()
  private lastTestSummary: string | null = null;

  private unsubPush: (() => void) | null = null;

  connectedCallback() {
    super.connectedCallback();
    this.webNotifications = areWebNotificationsEnabled();
    this.notifPermission = notificationPermission();
    this.unsubPush = subscribePushStatus((status) => {
      this.pushStatus = status;
    });
    void this.refreshPushStatus();
    void this.loadPrefs();
    document.addEventListener("visibilitychange", this.onForeground);
    window.addEventListener("focus", this.onForeground);
    window.addEventListener("online", this.onForeground);
  }

  disconnectedCallback() {
    this.unsubPush?.();
    this.unsubPush = null;
    document.removeEventListener("visibilitychange", this.onForeground);
    window.removeEventListener("focus", this.onForeground);
    window.removeEventListener("online", this.onForeground);
    super.disconnectedCallback();
  }

  private onForeground = () => {
    if (document.visibilityState === "visible") {
      void this.refreshPushStatus();
    }
  };

  private async refreshPushStatus(): Promise<void> {
    this.webNotifications = areWebNotificationsEnabled();
    this.notifPermission = notificationPermission();
    await probePushStatus({settingEnabled: this.webNotifications});
  }

  private async loadPrefs(): Promise<void> {
    if (!isAccountConnected() || !this.webNotifications) {
      this.prefs = [];
      return;
    }
    this.prefsLoading = true;
    try {
      this.prefs = await fetchNotificationPreferences();
    } catch (error) {
      console.warn("[notif] prefs load failed", error);
      this.prefs = [];
    } finally {
      this.prefsLoading = false;
    }
  }

  private onToggleNotifications = async () => {
    this.pushBusy = true;
    try {
      if (this.webNotifications) {
        await disableWebNotifications();
        this.webNotifications = false;
        this.prefs = [];
        await this.refreshPushStatus();
        return;
      }

      const result = await enableWebNotifications();
      this.notifPermission = notificationPermission();
      this.webNotifications = result.ok && areWebNotificationsEnabled();
      await this.refreshPushStatus();
      if (!result.ok) {
        const denied = this.notifPermission === "denied";
        await showAlert(
          tx("notif.title"),
          denied ? tx("notif.permission_denied") : tx("notif.unsupported"),
        );
        return;
      }
      await this.loadPrefs();
    } finally {
      this.pushBusy = false;
    }
  };

  private onRetryPush = async () => {
    this.pushBusy = true;
    try {
      if (!this.webNotifications) return;
      await subscribeServerPush();
      await this.refreshPushStatus();
      await this.loadPrefs();
    } finally {
      this.pushBusy = false;
    }
  };

  private onSendTest = async () => {
    if (!isAccountConnected() || !this.webNotifications) return;
    this.testBusy = true;
    this.lastTestSummary = null;
    try {
      const result = await requestPushTest();
      this.lastTestSummary = this.formatTestResult(result);
      await showAlert(tx("notif.test.title"), this.lastTestSummary);
      await this.refreshPushStatus();
    } catch (error) {
      const detail = error instanceof Error ? error.message : String(error);
      this.lastTestSummary = detail;
      await showAlert(tx("notif.test.title"), detail);
    } finally {
      this.testBusy = false;
    }
  };

  private formatTestResult(result: {
    ok: boolean;
    code: string;
    sent: number;
    failed: number;
    results: Array<{
      endpointHost: string;
      success: boolean;
      reason: string | null;
      statusCode: number | null;
    }>;
  }): string {
    if (result.code === "server_disabled") {
      return tx("notif.test.server_disabled");
    }
    if (result.code === "no_subscriptions") {
      return tx("notif.test.no_subscriptions");
    }
    const lines = [
      tf("notif.test.summary", {sent: result.sent, failed: result.failed}),
    ];
    for (const row of result.results) {
      if (row.success) {
        lines.push(tf("notif.test.row_ok", {host: row.endpointHost}));
      } else {
        const reason =
          row.reason ??
          (row.statusCode != null ? `HTTP ${row.statusCode}` : "error");
        lines.push(
          tf("notif.test.row_fail", {host: row.endpointHost, reason}),
        );
      }
    }
    if (result.ok && result.failed === 0) {
      lines.push(tx("notif.test.hint_ok"));
    } else if (!result.ok) {
      lines.push(tx("notif.test.hint_fail"));
    }
    return lines.join("\n");
  }

  private onTogglePref = async (type: string, enabled: boolean) => {
    if (!isAccountConnected()) return;
    try {
      this.prefs = await updateNotificationPreferences({[type]: enabled});
    } catch (error) {
      console.warn("[notif] prefs update failed", error);
      await showAlert(tx("notif.title"), tx("notif.prefs_error"));
    }
  };

  private statusTone(code: PushStatusCode): string {
    if (code === "ready") return "border-green-600/40 bg-green-50 text-green-900";
    if (code === "checking")
      return "border-neutral-300 bg-neutral-50 text-neutral-600";
    if (
      code === "permission_denied" ||
      code === "unsupported" ||
      code === "server_disabled" ||
      code === "register_failed" ||
      code === "no_service_worker" ||
      code === "server_missing"
    ) {
      return "border-red-600/30 bg-red-50 text-red-900";
    }
    return "border-amber-600/30 bg-amber-50 text-amber-950";
  }

  private renderPushStatus() {
    const status = this.pushStatus;
    if (!status) return nothing;
    const labelKey = STATUS_LABEL[status.code];
    const canRetry =
      this.webNotifications &&
      isAccountConnected() &&
      (status.code === "not_subscribed" ||
        status.code === "server_missing" ||
        status.code === "register_failed" ||
        status.code === "no_service_worker" ||
        status.code === "server_disabled");
    const canTest =
      this.webNotifications &&
      isAccountConnected() &&
      (status.code === "ready" ||
        status.code === "server_missing" ||
        status.serverSubscriptionCount > 0);

    return html`
      <div
        class="rounded-md border px-3 py-2 text-sm ${this.statusTone(status.code)}"
        role="status"
        aria-live="polite"
      >
        <p class="font-medium">${tx("notif.status.label")}</p>
        <p class="mt-0.5">${tx(labelKey)}</p>
        ${status.detail
          ? html`<p class="mt-1 text-xs opacity-80">${status.detail}</p>`
          : nothing}
        <ul class="mt-2 space-y-0.5 text-xs opacity-90">
          <li>
            ${tx("notif.status.line_permission")}: ${status.permission}
          </li>
          <li>
            ${tx("notif.status.line_account")}:
            ${status.accountConnected
              ? tx("notif.status.yes")
              : tx("notif.status.no")}
          </li>
          <li>
            ${tx("notif.status.line_vapid")}:
            ${status.vapidEnabled
              ? tx("notif.status.yes")
              : tx("notif.status.no")}
          </li>
          <li>
            ${tx("notif.status.line_sw")}:
            ${status.hasServiceWorker
              ? tx("notif.status.yes")
              : tx("notif.status.no")}
          </li>
          <li>
            ${tx("notif.status.line_device")}:
            ${status.hasPushSubscription
              ? tx("notif.status.yes")
              : tx("notif.status.no")}
          </li>
          <li>
            ${tx("notif.status.line_server")}:
            ${status.registeredOnServer
              ? tx("notif.status.yes")
              : tx("notif.status.no")}
            (${status.serverSubscriptionCount})
          </li>
          ${status.endpointHost
            ? html`<li>
                ${tx("notif.status.line_endpoint")}: ${status.endpointHost}
              </li>`
            : nothing}
        </ul>
        ${status.code === "need_account"
          ? html`<div class="mt-3">
              <account-required-cta bare></account-required-cta>
            </div>`
          : nothing}
        ${canRetry || canTest
          ? html`
              <div class="mt-2 flex flex-wrap gap-2">
                ${canRetry
                  ? html`
                      <sonic-button
                        size="sm"
                        type="default"
                        ?disabled=${this.pushBusy || this.testBusy}
                        @click=${this.onRetryPush}
                        >${tx("notif.status.retry")}</sonic-button
                      >
                    `
                  : nothing}
                ${canTest
                  ? html`
                      <sonic-button
                        size="sm"
                        type="default"
                        ?disabled=${this.pushBusy || this.testBusy}
                        @click=${this.onSendTest}
                        >${tx("notif.test.button")}</sonic-button
                      >
                    `
                  : nothing}
              </div>
            `
          : nothing}
        ${this.lastTestSummary
          ? html`<p class="mt-2 whitespace-pre-line text-xs opacity-90">
              ${this.lastTestSummary}
            </p>`
          : nothing}
      </div>
    `;
  }

  private renderPrefs() {
    if (!this.webNotifications || !isAccountConnected()) return nothing;
    if (this.prefsLoading) {
      return html`<p class="text-sm text-neutral-500">
        ${tx("notif.prefs_loading")}
      </p>`;
    }
    if (this.prefs.length === 0) return nothing;

    return html`
      <div class="space-y-2 pt-2">
        <p class="text-sm font-medium">${tx("notif.prefs_title")}</p>
        <p class="text-sm text-neutral-600">${tx("notif.prefs_help")}</p>
        <ul class="space-y-2">
          ${this.prefs.map((row) => {
            const labelKey = PREF_LABEL_KEYS[row.type] ?? row.type;
            return html`
              <li class="flex items-center gap-2">
                <label class="flex cursor-pointer items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    .checked=${row.enabled}
                    @change=${(e: Event) => {
                      const checked = (e.target as HTMLInputElement).checked;
                      void this.onTogglePref(row.type, checked);
                    }}
                  />
                  <span>${tx(labelKey)}</span>
                </label>
              </li>
            `;
          })}
        </ul>
      </div>
    `;
  }

  render() {
    const notifLabel = this.webNotifications
      ? tx("notif.disable")
      : tx("notif.enable");

    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="notifications"></config-scope-header>
        </div>

        <div class="mt-8 space-y-3">
          <p class="text-sm text-neutral-600">${tx("notif.help")}</p>
          <sonic-button
            size="sm"
            type=${this.webNotifications ? "primary" : "default"}
            ?disabled=${this.pushBusy}
            @click=${this.onToggleNotifications}
            >${notifLabel}</sonic-button
          >
          ${this.renderPushStatus()} ${this.renderPrefs()}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-notifications-page": ConfigNotificationsPage;
  }
}
