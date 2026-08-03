import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/button";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  ACCOUNT_CHANGED_EVENT,
  isAccountConnected,
  loadAccountSettings,
} from "../account-settings";
import {tx} from "../i18n";
import {verifyAccountEmail} from "../cloud-api/client";
import {hydrateAccountForm} from "../utils/account-form";
import {navigateTo} from "../utils/navigate";
import {configSectionPath} from "../utils/config-paths";
import {TACHE_ROOT} from "../utils/tache-paths";
import {showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";

@customElement("config-account-verify-page")
export class ConfigAccountVerifyPage extends LitElement {
  static styles = [tailwind];

  @state() private busy = true;
  @state() private error = "";
  @state() private done = false;

  private onAccountChanged = () => {
    if (isAccountConnected()) {
      navigateTo(TACHE_ROOT, true);
    }
  };

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    void this.runVerify();
  }

  disconnectedCallback() {
    window.removeEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    super.disconnectedCallback();
  }

  private async runVerify() {
    const params = new URLSearchParams(window.location.search);
    const token = params.get("token")?.trim() ?? "";
    if (!token) {
      this.busy = false;
      this.error = tx("account.verify.missing_token");
      return;
    }
    this.busy = true;
    this.error = "";
    try {
      hydrateAccountForm(loadAccountSettings());
      await verifyAccountEmail(token);
      this.done = true;
      navigateTo(TACHE_ROOT, true);
    } catch (error) {
      this.error =
        error instanceof Error ? error.message : tx("dialogs.unknown_error");
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  }

  render() {
    return html`
      <page-shell>
        <config-scope-header section="accountVerify"></config-scope-header>
        <div class="space-y-4 pt-8">
          ${this.busy
            ? html`<p class="opacity-70">${t("account.verify.working")}</p>`
            : nothing}
          ${this.done
            ? html`<sonic-alert status="success"
                >${t("account.verify.ok")}</sonic-alert
              >`
            : nothing}
          ${this.error
            ? html`<sonic-alert status="error">${this.error}</sonic-alert>
                <sonic-button
                  variant="outline"
                  href=${configSectionPath("accountLogin")}
                  pushstate
                  >${t("account.go_login")}</sonic-button
                >`
            : nothing}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-account-verify-page": ConfigAccountVerifyPage;
  }
}
