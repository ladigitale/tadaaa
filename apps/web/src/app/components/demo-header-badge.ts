import "@supersoniks/concorde/button";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {tx} from "../i18n";
import {
  ACCOUNT_CHANGED_EVENT,
  isAccountConnected,
} from "../account-settings";
import {openDemoTour} from "../demo-tour";
import tailwind from "../../css/tailwind";

/** Header “Demo” sticker — hidden when a cloud account is connected. */
@customElement("demo-header-badge")
export class DemoHeaderBadge extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: contents;
      }
    `,
  ];

  @state()
  private connected = isAccountConnected();

  private onAccountChanged = () => {
    this.connected = isAccountConnected();
  };

  connectedCallback() {
    super.connectedCallback();
    this.connected = isAccountConnected();
    window.addEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
  }

  disconnectedCallback() {
    window.removeEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    super.disconnectedCallback();
  }

  private onClick = (event: Event) => {
    event.preventDefault();
    event.stopPropagation();
    openDemoTour();
  };

  render() {
    if (this.connected) return nothing;
    return html`
      <sonic-tooltip label=${tx("demo.badge_title")} placement="bottom">
        <sonic-button
          type="info"
          size="2xs"
          data-aria-label=${tx("demo.badge_title")}
          @click=${this.onClick}
          >${t("demo.badge")}</sonic-button
        >
      </sonic-tooltip>
    `;
  }
}
