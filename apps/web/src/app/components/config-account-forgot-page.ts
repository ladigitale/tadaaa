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
import {requestPasswordReset} from "../cloud-api/client";
import {captureSisterReturnParam, readSisterReturnTo} from "../utils/sister-return";
import {isEnterSubmitEvent} from "../utils/form-enter-submit";
import {showError} from "../utils/modal-dialog";
import {inviteAuthPath} from "../utils/pending-invite";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";

const FORM = "accountForgotForm";

/** Mot de passe oublié : l'adresse du compte reçoit un lien pour en choisir un nouveau. */
@customElement("config-account-forgot-page")
export class ConfigAccountForgotPage extends LitElement {
  static styles = [tailwind];

  @state() private busy = false;
  @state() private sent = "";

  connectedCallback() {
    super.connectedCallback();
    captureSisterReturnParam();
  }

  private onKeyDown = (event: KeyboardEvent) => {
    if (!isEnterSubmitEvent(event)) return;
    event.preventDefault();
    void this.onSubmit();
  };

  private onSubmit = async () => {
    if (this.busy) return;
    const email = String((read(FORM) as {email?: string} | undefined)?.email ?? "").trim();
    if (!email) {
      await showError(new Error(tx("account.forgot.missing_email")), tx("dialogs.error"));
      return;
    }
    this.busy = true;
    try {
      this.sent = await requestPasswordReset(email, readSisterReturnTo() || undefined);
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  render() {
    return html`
      <page-shell>
        <config-scope-header section="accountForgot"></config-scope-header>
        <div class="space-y-6 pt-8" @keydown=${this.onKeyDown}>
          ${this.sent
            ? html`<sonic-alert status="success">${this.sent}</sonic-alert>`
            : html`<p>${t("account.forgot.intro")}</p>
                <sonic-form-layout>
                  <sonic-input formDataProvider=${FORM} name="email" label=${tx("account.email")} type="email" autocomplete="username"></sonic-input>
                </sonic-form-layout>`}
          <sonic-form-actions>
            ${this.sent
              ? nothing
              : html`<sonic-button type="primary" ?disabled=${this.busy} @click=${this.onSubmit}>${t("account.forgot.submit")}</sonic-button>`}
            <sonic-button variant="outline" href=${inviteAuthPath("accountLogin")} pushstate>${t("account.forgot.back")}</sonic-button>
          </sonic-form-actions>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-account-forgot-page": ConfigAccountForgotPage;
  }
}
