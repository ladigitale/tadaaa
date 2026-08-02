import "@supersoniks/concorde/button";
import {html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {tx} from "../i18n";
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

@customElement("config-install-page")
export class ConfigInstallPage extends LitElement {
  static styles = [tailwind];

  @state()
  private pwa: PwaInstallState = getPwaInstallState();

  private unsubPwa: (() => void) | null = null;

  connectedCallback() {
    super.connectedCallback();
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

  private onInstallPwa = async () => {
    const outcome = await promptPwaInstall();
    this.pwa = getPwaInstallState();
    if (outcome === "accepted") {
      await showAlert(tx("pwa.title"), tx("pwa.installed_ok"));
    } else if (outcome === "unavailable") {
      await showAlert(tx("pwa.title"), tx("pwa.unavailable"));
    }
  };

  render() {
    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="install"></config-scope-header>
        </div>

        <div class="mt-8 space-y-3">
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
              : html`<p class="text-sm text-neutral-500">${tx("pwa.hint")}</p>`}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-install-page": ConfigInstallPage;
  }
}
