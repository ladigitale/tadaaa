import "@supersoniks/concorde/button";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  APP_THEMES,
  applyTheme,
  loadThemeId,
  setTheme,
  type AppThemeId,
  type AppThemeMeta,
} from "../theme";
import {
  APP_LOCALES,
  getAppLocale,
  localeLabel,
  setAppLocale,
  type AppLocale,
} from "../i18n";
import {tx} from "../i18n";
import {areWebNotificationsEnabled} from "../settings";
import {
  disableWebNotifications,
  enableWebNotifications,
  notificationPermission,
} from "../notifications/web-notifications";
import {
  probePushStatus,
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
import {
  getPwaInstallState,
  promptPwaInstall,
  subscribePwaInstall,
  type PwaInstallState,
} from "../pwa-install";
import {showAlert} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
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
  register_failed: "notif.status.register_failed",
  offline: "notif.status.offline",
};

@customElement("config-appearance-page")
export class ConfigAppearancePage extends LitElement {
  static styles = [tailwind];

  @state()
  private themeId: AppThemeId = loadThemeId();

  @state()
  private locale: AppLocale = getAppLocale();

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
  private prefs: NotificationPreferenceRow[] = [];

  @state()
  private prefsLoading = false;

  @state()
  private pwa: PwaInstallState = getPwaInstallState();

  private unsubPwa: (() => void) | null = null;
  private unsubPush: (() => void) | null = null;

  connectedCallback() {
    super.connectedCallback();
    this.themeId = loadThemeId();
    this.locale = getAppLocale();
    this.webNotifications = areWebNotificationsEnabled();
    this.notifPermission = notificationPermission();
    this.pwa = getPwaInstallState();
    this.unsubPwa = subscribePwaInstall(() => {
      this.pwa = getPwaInstallState();
    });
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
    this.unsubPwa?.();
    this.unsubPwa = null;
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

  private onSelect = (id: AppThemeId) => {
    this.themeId = id;
    setTheme(id);
  };

  private onPreviewEnter = (id: AppThemeId) => {
    applyTheme(id);
  };

  private onPreviewLeave = () => {
    applyTheme(this.themeId);
  };

  private onSelectLocale = (locale: AppLocale) => {
    this.locale = locale;
    setAppLocale(locale);
  };

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
      if (!result.pushRegistered) {
        // Status banner already explains why — keep a short confirm.
        return;
      }
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

  private onTogglePref = async (type: string, enabled: boolean) => {
    if (!isAccountConnected()) return;
    try {
      this.prefs = await updateNotificationPreferences({[type]: enabled});
    } catch (error) {
      console.warn("[notif] prefs update failed", error);
      await showAlert(tx("notif.title"), tx("notif.prefs_error"));
    }
  };

  private onInstallPwa = async () => {
    const outcome = await promptPwaInstall();
    this.pwa = getPwaInstallState();
    if (outcome === "accepted") {
      await showAlert(tx("pwa.title"), tx("pwa.installed_ok"));
    } else if (outcome === "unavailable") {
      await showAlert(tx("pwa.title"), tx("pwa.unavailable"));
    }
  };

  private renderCard(theme: AppThemeMeta) {
    const active = this.themeId === theme.id;
    return html`
      <button
        type="button"
        role="option"
        aria-selected=${active}
        class="rounded-lg border border-neutral-300 p-3 text-left transition hover:border-neutral-500 ${active
          ? "ring-2 ring-current"
          : ""}"
        data-theme=${theme.id}
        @mouseenter=${() => this.onPreviewEnter(theme.id)}
        @mouseleave=${this.onPreviewLeave}
        @click=${() => this.onSelect(theme.id)}
      >
        <div class="flex gap-1.5" aria-hidden="true">
          ${theme.swatches.map(
            (c) =>
              html`<span
                class="h-5 w-5 rounded-full border border-black/10"
                style="background:${c}"
              ></span>`,
          )}
        </div>
        <div class="mt-2">
          <p class="text-sm font-medium">
            ${theme.label}
            ${active
              ? html`<span class="text-neutral-500"
                  >${tx("appearance.active")}</span
                >`
              : null}
          </p>
          <p class="mt-0.5 text-sm text-neutral-600">${theme.description}</p>
        </div>
      </button>
    `;
  }

  private statusTone(code: PushStatusCode): string {
    if (code === "ready") return "border-green-600/40 bg-green-50 text-green-900";
    if (code === "checking") return "border-neutral-300 bg-neutral-50 text-neutral-600";
    if (
      code === "permission_denied" ||
      code === "unsupported" ||
      code === "server_disabled" ||
      code === "register_failed" ||
      code === "no_service_worker"
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
        status.code === "register_failed" ||
        status.code === "no_service_worker" ||
        status.code === "server_disabled");

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
            ${tx("notif.status.line_permission")}:
            ${status.permission}
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
        </ul>
        ${canRetry
          ? html`
              <div class="mt-2">
                <sonic-button
                  size="sm"
                  type="default"
                  ?disabled=${this.pushBusy}
                  @click=${this.onRetryPush}
                  >${tx("notif.status.retry")}</sonic-button
                >
              </div>
            `
          : nothing}
      </div>
    `;
  }

  private renderPrefs() {
    if (!this.webNotifications || !isAccountConnected()) return nothing;
    if (this.prefsLoading) {
      return html`<p class="text-sm text-neutral-500">${tx("notif.prefs_loading")}</p>`;
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
          <config-scope-header section="appearance"></config-scope-header>
        </div>

        <div class="mt-8 space-y-10">
          <section class="space-y-3">
            <h2 class="text-base font-semibold">${t("appearance.themes")}</h2>
            <p class="text-sm text-neutral-600">${t("appearance.intro")}</p>
            <div
              class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
              role="listbox"
              aria-label=${tx("appearance.themes_aria")}
            >
              ${APP_THEMES.map((theme) => this.renderCard(theme))}
            </div>
          </section>

          <section class="space-y-3">
            <h2 class="text-base font-semibold">${t("appearance.language")}</h2>
            <p class="text-sm text-neutral-600">
              ${t("appearance.language_help")}
            </p>
            <div class="flex flex-wrap gap-2" role="listbox" aria-label=${tx("appearance.language")}>
              ${APP_LOCALES.map(
                (locale) => html`
                  <sonic-button
                    size="sm"
                    type=${this.locale === locale ? "primary" : "default"}
                    @click=${() => this.onSelectLocale(locale)}
                    >${localeLabel(locale)}</sonic-button
                  >
                `,
              )}
            </div>
          </section>

          <section class="space-y-3">
            <h2 class="text-base font-semibold">${tx("notif.title")}</h2>
            <p class="text-sm text-neutral-600">${tx("notif.help")}</p>
            <sonic-button
              size="sm"
              type=${this.webNotifications ? "primary" : "default"}
              ?disabled=${this.pushBusy}
              @click=${this.onToggleNotifications}
              >${notifLabel}</sonic-button
            >
            ${this.renderPushStatus()}
            ${this.renderPrefs()}
          </section>

          <section class="space-y-3">
            <h2 class="text-base font-semibold">${tx("pwa.title")}</h2>
            <p class="text-sm text-neutral-600">${tx("pwa.help")}</p>
            ${this.pwa.installed
              ? html`<p class="text-sm text-neutral-700">${tx("pwa.already")}</p>`
              : this.pwa.canPrompt
                ? html`
                    <sonic-button
                      size="sm"
                      type="primary"
                      @click=${this.onInstallPwa}
                      >${tx("pwa.install")}</sonic-button
                    >
                  `
                : html`<p class="text-sm text-neutral-500">
                    ${tx("pwa.hint")}
                  </p>`}
          </section>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-appearance-page": ConfigAppearancePage;
  }
}
