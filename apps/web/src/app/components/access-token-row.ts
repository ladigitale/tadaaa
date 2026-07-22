import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {css, html, LitElement} from "lit";
import {customElement, property} from "lit/decorators.js";
import type {AccessTokenInfo} from "../cloud-api/client";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";

@customElement("access-token-row")
export class AccessTokenRow extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }
    `,
  ];

  @property({attribute: false})
  token!: AccessTokenInfo;

  @property({type: Boolean})
  disabled = false;

  private formatDate(value: string): string {
    try {
      return new Date(value).toLocaleString("fr-FR", {
        dateStyle: "short",
        timeStyle: "short",
      });
    } catch {
      return value;
    }
  }

  private get metaLine(): string {
    const parts = [
      `${this.token.tokenPrefix}…`,
      `créé ${this.formatDate(this.token.createdAt)}`,
    ];
    if (this.token.lastUsedAt) {
      parts.push(`utilisé ${this.formatDate(this.token.lastUsedAt)}`);
    }
    return parts.join(" · ");
  }

  private renderMenuItemIcon(name: string) {
    return html`
      <sonic-icon
        slot="prefix"
        library=${ICON_LIBRARY}
        prefix=${ICON_PREFIX}
        name=${name}
        size="sm"
      ></sonic-icon>
    `;
  }

  private onRevoke() {
    this.dispatchEvent(
      new CustomEvent("access-token-revoke", {
        bubbles: true,
        composed: true,
        detail: {token: this.token},
      }),
    );
  }

  render() {
    if (!this.token) return html``;

    return html`
      <article class="py-4 sm:py-5">
        <div class="flex items-start gap-2 sm:gap-3">
          <div class="min-w-0 flex-1">
            <span class="text-sm font-medium text-neutral-900 sm:text-base"
              >${this.token.name}</span
            >
            <p class="mt-0.5 text-xs text-neutral-500">${this.metaLine}</p>
          </div>

          <div class="flex shrink-0 items-center gap-0.5">
            <sonic-pop class="inline-block" placement="bottom">
              <sonic-button
                shape="circle"
                size="sm"
                variant="ghost"
                ?disabled=${this.disabled}
                data-aria-label="Actions"
                title="Actions"
              >
                <sonic-icon
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name="more-vert"
                  size="lg"
                ></sonic-icon>
              </sonic-button>

              <sonic-menu
                slot="content"
                direction="column"
                align="left"
                size="sm"
                minWidth="12rem"
              >
                <sonic-menu-item
                  type="danger"
                  ?disabled=${this.disabled}
                  @click=${this.onRevoke}
                >
                  ${this.renderMenuItemIcon("trash")} Révoquer
                </sonic-menu-item>
              </sonic-menu>
            </sonic-pop>
          </div>
        </div>
      </article>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "access-token-row": AccessTokenRow;
  }
}
