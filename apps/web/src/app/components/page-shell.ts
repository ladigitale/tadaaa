import {css, html, LitElement} from "lit";
import {customElement} from "lit/decorators.js";
import tailwind from "../../css/tailwind";

/**
 * Enveloppe commune à toutes les pages métier :
 * même vertical rhythm que le layout principal.
 */
@customElement("page-shell")
export class PageShell extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
        height: 100%;
        min-height: 0;
        overflow-x: hidden;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: transparent transparent;
      }

      :host(:hover) {
        scrollbar-color: var(--sc-base-500) transparent;
      }

      :host::-webkit-scrollbar {
        width: 0.5rem;
        height: 0.5rem;
        border: solid 0.15rem transparent;
        border-radius: var(--sc-rounded);
        background: transparent;
      }

      :host::-webkit-scrollbar-thumb {
        transition: box-shadow 0.2s;
        border: solid 0.15rem transparent;
        border-radius: var(--sc-rounded);
        box-shadow: inset 0 0 0 0 transparent;
      }

      :host(:hover)::-webkit-scrollbar-thumb {
        box-shadow: inset 0 0 2rem 2rem var(--sc-base-800);
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
