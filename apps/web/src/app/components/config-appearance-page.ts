import "@supersoniks/concorde/button";
import {html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {
  APP_THEMES,
  applyTheme,
  loadThemeId,
  setTheme,
  type AppThemeId,
  type AppThemeMeta,
} from "../theme";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";

@customElement("config-appearance-page")
export class ConfigAppearancePage extends LitElement {
  static styles = [tailwind];

  @state()
  private themeId: AppThemeId = loadThemeId();

  connectedCallback() {
    super.connectedCallback();
    this.themeId = loadThemeId();
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
                  >(active)</span
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

        <div class="mt-6 space-y-3">
          <p class="text-sm text-neutral-600">
            Themes use Concorde CSS variables
            <code class="text-xs">--sc-*</code>. Hover to preview; click to
            save.
          </p>
          <div
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            role="listbox"
            aria-label="Appearance themes"
          >
            ${APP_THEMES.map((theme) => this.renderCard(theme))}
          </div>
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
