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

  connectedCallback() {
    super.connectedCallback();
    this.themeId = loadThemeId();
    this.locale = getAppLocale();
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

  render() {
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
            <div
              class="flex flex-wrap gap-2"
              role="listbox"
              aria-label=${tx("appearance.language")}
            >
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
