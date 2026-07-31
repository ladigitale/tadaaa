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
  private prefs: NotificationPreferenceRow[] = [];

  @state()
  private prefsLoading = false;

  @state()
  private pwa: PwaInstallState = getPwaInstallState();

  private unsubPwa: (() => void) | null = null;

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
    void this.loadPrefs();
  }

  disconnectedCallback() {
    this.unsubPwa?.();
    this.unsubPwa = null;
    super.disconnectedCallback();
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
    if (this.webNotifications) {
      await disableWebNotifications();
      this.webNotifications = false;
      this.prefs = [];
      return;
    }

    const ok = await enableWebNotifications();
    this.notifPermission = notificationPermission();
    this.webNotifications = ok && areWebNotificationsEnabled();
    if (!ok) {
      const denied = this.notifPermission === "denied";
      await showAlert(
        tx("notif.title"),
        denied ? tx("notif.permission_denied") : tx("notif.unsupported"),
      );
      return;
    }
    await this.loadPrefs();
    await showAlert(tx("notif.title"), tx("notif.enabled_ok"));
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
              @click=${this.onToggleNotifications}
              >${notifLabel}</sonic-button
            >
            ${this.notifPermission === "denied"
              ? html`<p class="text-sm text-neutral-500">
                  ${tx("notif.permission_denied")}
                </p>`
              : null}
            ${!isAccountConnected() && this.webNotifications
              ? html`<p class="text-sm text-neutral-500">
                  ${tx("notif.push_needs_account")}
                </p>`
              : null}
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
