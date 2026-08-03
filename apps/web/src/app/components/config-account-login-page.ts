import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  ACCOUNT_CHANGED_EVENT,
  isAccountConnected,
  loadAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {tx} from "../i18n";
import {
  checkCloudApiHealth,
  loginAccount,
  refreshAccountSession,
} from "../cloud-api/client";
import {read} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {
  clearAccountPasswordField,
  hydrateAccountForm,
  persistAccountApiBaseUrl,
} from "../utils/account-form";
import {navigateTo} from "../utils/navigate";
import {configSectionPath} from "../utils/config-paths";
import {TACHE_ROOT} from "../utils/tache-paths";
import {showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./account-api-url-field";
import "./config-scope-header";
import "./page-shell";

@customElement("config-account-login-page")
export class ConfigAccountLoginPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @subscribe(appConfigKey.accountEmail)
  @state()
  accountEmail = "";

  @subscribe(appConfigKey.accountPassword)
  @state()
  accountPassword = "";

  @subscribe(appConfigKey.accountApiBaseUrl)
  @state()
  accountApiBaseUrl = "";

  @state()
  private account: AccountSettings = loadAccountSettings();

  @state()
  private apiHealthy: boolean | null = null;

  @state()
  private busy = false;

  @state()
  private statusMessage = "";

  private onAccountChanged = () => {
    this.account = loadAccountSettings();
    if (isAccountConnected(this.account)) {
      navigateTo(TACHE_ROOT, true);
    }
  };

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    void this.bootstrap();
  }

  disconnectedCallback() {
    window.removeEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    super.disconnectedCallback();
  }

  private async bootstrap() {
    const account = loadAccountSettings();
    if (account.token) {
      try {
        const next = await refreshAccountSession(account);
        if (isAccountConnected(next)) {
          navigateTo(TACHE_ROOT, true);
          return;
        }
      } catch {
        /* expired — show login form */
      }
    }
    hydrateAccountForm(loadAccountSettings());
    this.account = loadAccountSettings();
    this.apiHealthy = await checkCloudApiHealth(this.account);
  }

  private onLogin = async () => {
    if (this.busy) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const email = form.accountEmail.trim();
    const password = form.accountPassword;
    if (!email || !password) {
      await showError(
        new Error(tx("dialogs.unknown_error")),
        tx("dialogs.error"),
      );
      return;
    }

    this.busy = true;
    this.statusMessage = "";
    try {
      this.account = persistAccountApiBaseUrl(this.account);
      this.account = await loginAccount(
        email,
        password,
        form.accountApiBaseUrl,
      );
      clearAccountPasswordField();
      navigateTo(TACHE_ROOT);
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  render() {
    const healthLabel =
      this.apiHealthy === null
        ? tx("account.api_checking")
        : this.apiHealthy
          ? tx("account.api_ok")
          : tx("account.api_ko");

    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="accountLogin"></config-scope-header>
        </div>

        <div class="space-y-6 pt-8">
          <sonic-alert status="info">
            ${t("account.login_intro")}
            <div class="mt-1 text-sm opacity-80">${healthLabel}</div>
          </sonic-alert>
          ${this.statusMessage
            ? html`<p class="text-sm text-neutral-500">${this.statusMessage}</p>`
            : nothing}
          <sonic-form-layout>
            <account-api-url-field></account-api-url-field>
            <sonic-input
              formDataProvider=${appConfigKey.path}
              name="accountEmail"
              label=${tx("account.email")}
              type="email"
              autocomplete="username"
            ></sonic-input>
            <sonic-input
              formDataProvider=${appConfigKey.path}
              name="accountPassword"
              label=${tx("account.password")}
              type="password"
              autocomplete="current-password"
            ></sonic-input>
          </sonic-form-layout>
          <sonic-form-actions>
            <sonic-button
              type="primary"
              ?disabled=${this.busy}
              @click=${this.onLogin}
            >
              ${t("account.login_submit")}
            </sonic-button>
            <sonic-button
              variant="outline"
              href=${configSectionPath("accountRegister")}
              pushstate
            >
              ${t("account.go_register")}
            </sonic-button>
          </sonic-form-actions>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-account-login-page": ConfigAccountLoginPage;
  }
}
