import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {tx} from "../i18n";
import {read} from "../../utils/dataprovider";
import {resetAccountPassword} from "../cloud-api/client";
import {captureSisterReturnParam} from "../utils/sister-return";
import {isEnterSubmitEvent} from "../utils/form-enter-submit";
import {showError} from "../utils/modal-dialog";
import {navigateAfterAuth} from "../utils/pending-invite";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";

const FORM = "accountResetForm";

/** Nouveau mot de passe, avec le jeton du lien reçu par email ; connecte ensuite. */
@customElement("config-account-reset-page")
export class ConfigAccountResetPage extends LitElement {
  static styles = [tailwind];

  @state() private busy = false;
  @state() private token = "";

  connectedCallback() {
    super.connectedCallback();
    // Le lien de l'email porte l'adresse de retour (nouvel onglet : pas de sessionStorage).
    captureSisterReturnParam();
    this.token = new URLSearchParams(window.location.search).get("token")?.trim() ?? "";
  }

  private onKeyDown = (event: KeyboardEvent) => {
    if (!isEnterSubmitEvent(event)) return;
    event.preventDefault();
    void this.onSubmit();
  };

  private onSubmit = async () => {
    if (this.busy) return;
    const form = (read(FORM) as {password?: string; confirm?: string} | undefined) ?? {};
    const password = String(form.password ?? "");
    if (password.length < 8) {
      await showError(new Error(tx("account.reset.too_short")), tx("dialogs.error"));
      return;
    }
    if (password !== String(form.confirm ?? "")) {
      await showError(new Error(tx("account.reset.mismatch")), tx("dialogs.error"));
      return;
    }
    this.busy = true;
    try {
      const settings = await resetAccountPassword(this.token, password);
      await navigateAfterAuth(settings);
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  render() {
    return html`
      <page-shell>
        <config-scope-header section="accountReset"></config-scope-header>
        <div class="space-y-6 pt-8" @keydown=${this.onKeyDown}>
          ${this.token
            ? html`<p>${t("account.reset.intro")}</p>
                <sonic-form-layout>
                  <sonic-input formDataProvider=${FORM} name="password" label=${tx("account.reset.password")} type="password" autocomplete="new-password"></sonic-input>
                  <sonic-input formDataProvider=${FORM} name="confirm" label=${tx("account.reset.confirm")} type="password" autocomplete="new-password"></sonic-input>
                </sonic-form-layout>
                <sonic-form-actions>
                  <sonic-button type="primary" ?disabled=${this.busy} @click=${this.onSubmit}>${t("account.reset.submit")}</sonic-button>
                </sonic-form-actions>`
            : html`<sonic-alert status="error">${t("account.reset.missing_token")}</sonic-alert>
                <sonic-button variant="outline" href="/account/forgot" pushstate>${t("account.reset.ask_again")}</sonic-button>`}
          ${nothing}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-account-reset-page": ConfigAccountResetPage;
  }
}
