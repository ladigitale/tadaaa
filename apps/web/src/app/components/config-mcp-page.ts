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
  getMcpUrl,
  isAccountConnected,
  loadAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  createAccessToken,
  fetchAccessTokens,
  refreshAccountSession,
  revokeAccessToken,
  type AccessTokenInfo,
} from "../cloud-api/client";
import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./access-token-row";
import "./account-required-cta";
import "./config-scope-header";
import "./page-shell";

@customElement("config-mcp-page")
export class ConfigMcpPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @subscribe(appConfigKey.newAccessTokenName)
  @state()
  newAccessTokenName = "";

  @state()
  private account: AccountSettings = loadAccountSettings();

  @state()
  private accessTokens: AccessTokenInfo[] = [];

  @state()
  private lastPlainToken: string | null = null;

  @state()
  private mcpUrl = getMcpUrl();

  @state()
  private busy = false;

  @state()
  private statusMessage = "";

  connectedCallback() {
    super.connectedCallback();
    void this.bootstrap();
  }

  private async bootstrap() {
    const account = loadAccountSettings();
    const form = read(appConfigKey.path) as AppConfigForm | undefined;
    set(appConfigKey.path, {
      newDatasetName: form?.newDatasetName ?? "",
      p2pReceiveCode: form?.p2pReceiveCode ?? "",
      accountEmail: account.user?.email ?? form?.accountEmail ?? "",
      accountPassword: "",
      accountApiBaseUrl: account.apiBaseUrl,
      newCloudDatasetName: form?.newCloudDatasetName ?? "",
      newAccessTokenName: form?.newAccessTokenName ?? "",
      shareInviteEmail: form?.shareInviteEmail ?? "",
      webhookUrl: form?.webhookUrl ?? "",
      embedName: form?.embedName ?? "",
      embedOrigins: form?.embedOrigins ?? "",
    });
    this.account = account;
    await this.reload();
  }

  private async reload() {
    this.mcpUrl = getMcpUrl(this.account);
    if (!isAccountConnected(this.account)) {
      this.accessTokens = [];
      return;
    }
    try {
      this.account = await refreshAccountSession(this.account);
      this.accessTokens = await fetchAccessTokens(this.account);
      this.mcpUrl = getMcpUrl(this.account);
    } catch (error) {
      this.account = loadAccountSettings();
      this.accessTokens = [];
      this.statusMessage =
        error instanceof Error ? error.message : tx("dialogs.unknown_error");
      console.error(error);
    }
  }

  private listSeparator = () =>
    html`<div
      class="w-full bg-neutral-100"
      style="min-height: 2px"
      role="separator"
    ></div>`;

  private async copyText(value: string) {
    try {
      await navigator.clipboard.writeText(value);
      this.statusMessage = tx("account.mcp.copy_json");
    } catch {
      this.statusMessage = tx("dialogs.unknown_error");
    }
  }

  private buildMcpServerJson(plainToken: string): string {
    return JSON.stringify(
      {
        mcpServers: {
          tadaaa: {
            url: this.mcpUrl,
            headers: {
              Authorization: `Bearer ${plainToken}`,
            },
          },
        },
      },
      null,
      2,
    );
  }

  private onCreateAccessToken = async () => {
    if (this.busy || !isAccountConnected(this.account)) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const name = form.newAccessTokenName.trim() || tx("account.mcp.token_ph");
    this.busy = true;
    this.lastPlainToken = null;
    try {
      const created = await createAccessToken(name, this.account);
      this.lastPlainToken = created.plainToken;
      this.mcpUrl = created.mcpUrl;
      set(appConfigKey.path, {...form, newAccessTokenName: ""});
      await this.reload();
      this.statusMessage = tx("account.mcp.created_toast");
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onRevokeAccessToken = async (
    event: CustomEvent<{token: AccessTokenInfo}>,
  ) => {
    const token = event.detail.token;
    if (this.busy) return;
    const ok = await confirmDialog({
      title: tx("account.mcp.revoke_title"),
      message: tf("account.mcp.revoke_confirm", {
        name: token.name,
        prefix: token.tokenPrefix,
      }),
      confirmLabel: tx("account.mcp.revoke"),
      danger: true,
    });
    if (!ok) return;
    this.busy = true;
    try {
      await revokeAccessToken(token.id, this.account);
      if (this.lastPlainToken?.startsWith(token.tokenPrefix)) {
        this.lastPlainToken = null;
      }
      await this.reload();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onCopyMcpConfig = async () => {
    if (!this.lastPlainToken) {
      await showError(
        new Error(tx("account.mcp.after_create")),
        tx("account.mcp.title"),
      );
      return;
    }
    await this.copyText(this.buildMcpServerJson(this.lastPlainToken));
    this.statusMessage = tx("account.mcp.copy_json");
  };

  render() {
    const connected = isAccountConnected(this.account);

    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="mcp"></config-scope-header>
        </div>

        <div class="space-y-6 pt-8">
          ${!connected
            ? html`<account-required-cta
                messageKey="connectivity.need_account"
              ></account-required-cta>`
            : html`
                <p class="text-sm text-neutral-500">
                  ${tf("account.mcp.help", {url: this.mcpUrl})}
                </p>
                ${this.statusMessage
                  ? html`<p class="text-sm text-neutral-500">
                      ${this.statusMessage}
                    </p>`
                  : nothing}
                ${this.lastPlainToken
                  ? html`
                      <sonic-alert type="warning" size="sm">
                        ${t("account.mcp.secret_once")}
                        <code class="break-all text-xs"
                          >${this.lastPlainToken}</code
                        >
                      </sonic-alert>
                      <pre
                        class="max-h-48 overflow-auto rounded-md border border-current/10 bg-neutral-500/5 p-3 text-xs"
                      ><code>${this.buildMcpServerJson(this.lastPlainToken)}</code></pre>
                    `
                  : html`
                      <p class="text-sm text-neutral-500">
                        ${t("account.mcp.after_create")}
                      </p>
                    `}
                <sonic-form-layout>
                  <sonic-input
                    formDataProvider=${appConfigKey.path}
                    name="newAccessTokenName"
                    label=${tx("account.mcp.token_name")}
                    placeholder=${tx("account.mcp.token_ph")}
                  ></sonic-input>
                </sonic-form-layout>
                <sonic-form-actions>
                  <sonic-button
                    type="primary"
                    size="sm"
                    ?disabled=${this.busy}
                    @click=${this.onCreateAccessToken}
                  >
                    ${t("account.mcp.create")}
                  </sonic-button>
                  <sonic-button
                    size="sm"
                    variant="outline"
                    ?disabled=${!this.lastPlainToken}
                    @click=${this.onCopyMcpConfig}
                  >
                    ${t("account.mcp.copy_json")}
                  </sonic-button>
                </sonic-form-actions>
                ${this.accessTokens.length === 0
                  ? html`<p class="text-sm text-neutral-500">
                      ${t("account.mcp.none")}
                    </p>`
                  : html`
                      <ul class="m-0 list-none p-0">
                        ${this.accessTokens.map(
                          (token, index) => html`
                            <li>
                              ${index > 0 ? this.listSeparator() : nothing}
                              <access-token-row
                                .token=${token}
                                ?disabled=${this.busy}
                                @access-token-revoke=${this.onRevokeAccessToken}
                              ></access-token-row>
                            </li>
                          `,
                        )}
                      </ul>
                    `}
              `}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-mcp-page": ConfigMcpPage;
  }
}
