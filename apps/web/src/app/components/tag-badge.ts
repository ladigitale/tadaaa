import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property} from "lit/decorators.js";
import type {Tag} from "../api/types";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import tailwind from "../../css/tailwind";

@customElement("tag-badge")
export class TagBadge extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: inline-flex;
        vertical-align: middle;
      }
    `,
  ];

  @property({attribute: false})
  tag?: Tag;

  @property({type: Boolean})
  removable = false;

  @property({type: Boolean})
  selected = false;

  @property({type: Boolean})
  disabled = false;

  /** Compteur affiché en bulle (badge imbriqué dans le suffix). */
  @property({type: Number, attribute: false})
  count?: number;

  @property({type: String})
  size: "2xs" | "xs" | "sm" | "md" = "sm";

  private onRemove = (event: Event) => {
    event.stopPropagation();
    if (this.disabled || !this.tag) return;
    this.dispatchEvent(
      new CustomEvent("tag-remove", {
        bubbles: true,
        composed: true,
        detail: {tagId: this.tag.id},
      }),
    );
  };

  private onClick = () => {
    if (this.disabled || this.removable || !this.tag) return;
    this.dispatchEvent(
      new CustomEvent("tag-click", {
        bubbles: true,
        composed: true,
        detail: {tagId: this.tag.id},
      }),
    );
  };

  render() {
    if (!this.tag) return nothing;

    const showCount = this.count !== undefined && this.count !== null;

    return html`
      <sonic-badge
        type=${this.tag.color}
        size=${this.size}
        variant="default"
        class=${this.disabled
          ? "opacity-50"
          : this.removable
            ? ""
            : "cursor-pointer"}
        @click=${this.onClick}
      >
        ${this.removable
          ? html`
              <sonic-button
                slot="prefix"
                shape="circle"
                size="2xs"
                variant="ghost"
                ?disabled=${this.disabled}
                data-aria-label="Retirer ${this.tag.name}"
                @click=${this.onRemove}
              >
                <sonic-icon
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name="xmark"
                  size="sm"
                ></sonic-icon>
              </sonic-button>
            `
          : nothing}
        ${this.tag.name}
        ${showCount
          ? html`
              <sonic-badge slot="suffix" type="contrast" size="2xs">
                ${this.count}
              </sonic-badge>
            `
          : nothing}
      </sonic-badge>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tag-badge": TagBadge;
  }
}
