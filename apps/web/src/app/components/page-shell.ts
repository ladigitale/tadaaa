import {css, html, LitElement} from "lit";
import {customElement} from "lit/decorators.js";
import tailwind from "../../css/tailwind";

/**
 * Enveloppe commune à toutes les pages métier :
 * même vertical rhythm que le layout principal.
 * Le scroll est global (sur `<main>` du layout) — pas de nested scroll ici.
 */
@customElement("page-shell")
export class PageShell extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }
    `,
  ];

  render() {
    return html`
      <div class="flex flex-col gap-3 sm:gap-4 md:gap-5">
        <slot></slot>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "page-shell": PageShell;
  }
}
