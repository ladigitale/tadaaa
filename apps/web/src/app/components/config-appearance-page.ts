import "@supersoniks/concorde/button";
import {html, LitElement} from "lit";
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
  getPwaInstallState,
  promptPwaInstall,
  subscribePwaInstall,
  type PwaInstallState,
} from "../pwa-install";
import {showAlert} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";

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
  }

  disconnectedCallback() {
    this.unsubPwa?.();
    this.unsubPwa = null;
    super.disconnectedCallback();
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
      disableWebNotifications();
      this.webNotifications = false;
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
    await showAlert(tx("notif.title"), tx("notif.enabled_ok"));
  };

  private onInstallPwa = async () => {
    const outcome = await promptPwaInstall();
    this.pwa = getPwaInstallState();
    if (outcome === "accepted") {
      await showAlert(tx("pwa.title"), tx("pwa.installed_ok"));
      return;
    }
    if (outcome === "unavailable") {
      await showAlert(tx("pwa.title"), tx("pwa.unavailable"));
    }
  };

  private renderCard(theme: AppThemeMeta) {
    const selected = theme.id === this.themeId;
    return html`
      <button
        type="button"
        class="flex w-full flex-col gap-3 rounded border-[.18rem] border-current p-3 text-left transition-opacity hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary ${selected
          ? "bg-neutral-100"
          : "bg-neutral-0"}"
        aria-pressed=${selected ? "true" : "false"}
        @click=${() => this.onSelect(theme.id)}
        @mouseenter=${() => this.onPreviewEnter(theme.id)}
        @mouseleave=${this.onPreviewLeave}
        @focus=${() => this.onPreviewEnter(theme.id)}
        @blur=${this.onPreviewLeave}
      >
        <div class="flex items-center gap-2" aria-hidden="true">
          ${theme.swatches.map(
            (color) => html`
              <span
                class="inline-block h-8 w-8 rounded border border-current"
                style="background-color: ${color}"
              ></span>
            `,
          )}
        </div>
        <div class="min-w-0">
          <p class="font-semibold text-neutral-900">
            ${theme.label}
            ${selected
              ? html`<span class="ml-1 text-xs font-normal text-neutral-500"
                  >${t("appearance.active")}</span
                >`
              : null}
          </p>
          <p class="mt-0.5 text-sm text-neutral-600">${theme.description}</p>
        </div>
      </button>
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
