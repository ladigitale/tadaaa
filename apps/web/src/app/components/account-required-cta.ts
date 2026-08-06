import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/button";
import {html, LitElement} from "lit";
import {customElement, property} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {inviteAuthPath} from "../utils/pending-invite";
import tailwind from "../../css/tailwind";

/**
 * Shared gate when a cloud account is required: message + login / register CTAs.
 */
@customElement("account-required-cta")
export class AccountRequiredCta extends LitElement {
  static styles = [tailwind];

  /** i18n key for the body copy (defaults to the common prompt). */
  @property()
  messageKey = "account.cta.need";

  /** When set, login/register links carry `?invite=` for post-auth auto-accept. */
  @property()
  inviteToken = "";

  /** When true, only the action buttons are rendered (no alert wrapper). */
  @property({type: Boolean})
  bare = false;

  render() {
    const token = this.inviteToken.trim() || undefined;
    const loginHref = inviteAuthPath("accountLogin", token);
    const registerHref = inviteAuthPath("accountRegister", token);
    const actions = html`
      <div class="flex flex-wrap gap-2">
        <sonic-button type="primary" size="sm" href=${loginHref} pushstate>
          ${t("account.login")}
        </sonic-button>
        <sonic-button
          variant="outline"
          size="sm"
          href=${registerHref}
          pushstate
        >
          ${t("account.signup")}
        </sonic-button>
      </div>
    `;

    if (this.bare) return actions;

    return html`
      <sonic-alert type="info">
        <div class="flex flex-col gap-3">
          <p>${t(this.messageKey)}</p>
          ${actions}
        </div>
      </sonic-alert>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "account-required-cta": AccountRequiredCta;
  }
}
