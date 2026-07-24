import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property} from "lit/decorators.js";
import {tx} from "../i18n";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";

/**
 * En-tête de page :
 * - liste : titre + bouton d’ajout (href ou événement `add`)
 * - formulaire : chevron retour (goBack) + titre
 */
@customElement("page-header")
export class PageHeader extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }
    `,
  ];

  @property()
  heading = "";

  @property()
  addLabel = "";

  /** Si défini, le bouton + navigue vers cette URL (pushstate). */
  @property()
  addHref = "";

  @property()
  description = "";

  /** Affiche un chevron retour (goBack) à la place du bouton +. */
  @property({type: Boolean})
  withBack = false;

  private get resolvedAddLabel(): string {
    return this.addLabel || tx("common.add");
  }

  private onAdd() {
    this.dispatchEvent(
      new CustomEvent("add", {bubbles: true, composed: true}),
    );
  }

  private get hasLeading(): boolean {
    return this.withBack || Boolean(this.heading);
  }

  render() {
    return html`
      <header
        class="flex shrink-0 items-center gap-3 ${this.hasLeading
          ? "justify-between"
          : "justify-end"}"
      >
        ${this.hasLeading
          ? html`
              <div class="flex min-w-0 items-center gap-2">
                ${this.withBack
                  ? html`
                      <sonic-tooltip
                        label=${tx("common.back")}
                        placement="bottom"
                      >
                        <sonic-button
                          goBack
                          shape="circle"
                          variant="ghost"
                          size="sm"
                          data-aria-label=${tx("common.back")}
                        >
                          <sonic-icon
                            library=${ICON_LIBRARY}
                            prefix=${ICON_PREFIX}
                            name="nav-arrow-left"
                            size="sm"
                          ></sonic-icon>
                        </sonic-button>
                      </sonic-tooltip>
                    `
                  : nothing}
                ${this.heading
                  ? html`
                      <h1
                        class="truncate text-[1.75rem] font-semibold leading-none tracking-tight text-neutral-900 sm:text-4xl"
                      >
                        ${this.heading}
                      </h1>
                    `
                  : nothing}
              </div>
            `
          : nothing}
        <div class="flex shrink-0 items-center gap-1.5 sm:gap-2">
          ${!this.withBack
            ? this.addHref
              ? html`
                  <sonic-button
                    href=${this.addHref}
                    pushstate
                    type="primary"
                    size="sm"
                  >
                    <sonic-icon
                      library=${ICON_LIBRARY}
                      prefix=${ICON_PREFIX}
                      name="plus"
                      size="sm"
                    ></sonic-icon>
                    ${this.resolvedAddLabel}
                  </sonic-button>
                `
              : html`
                  <sonic-button
                    type="primary"
                    size="sm"
                    @click=${this.onAdd}
                  >
                    <sonic-icon
                      library=${ICON_LIBRARY}
                      prefix=${ICON_PREFIX}
                      name="plus"
                      size="sm"
                    ></sonic-icon>
                    ${this.resolvedAddLabel}
                  </sonic-button>
                `
            : nothing}
          <slot name="actions"></slot>
        </div>
      </header>
      ${this.description
        ? html`
            <p class="mt-1 hidden text-sm text-neutral-600 sm:block">
              ${this.description}
            </p>
          `
        : nothing}
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "page-header": PageHeader;
  }
}
