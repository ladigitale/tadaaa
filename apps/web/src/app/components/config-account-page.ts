import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  isAccountConnected,
  isCloudAdmin,
  loadAccountSettings,
  saveAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  approveAdminUser,
  checkCloudApiHealth,
  disableAdminUser,
  fetchAdminUsers,
  loginAccount,
  logoutAccount,
  refreshAccountSession,
  registerAccount,
  rejectAdminUser,
  type AdminUserInfo,
} from "../cloud-api/client";
import {
  getActiveDatasetSyncState,
  getActivePendingCount,
  runDatasetSync,
} from "../sync/engine";
import type {SyncState} from "../sync/outbox-types";
import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";
import "./user-avatar";

@customElement("config-account-page")
export class ConfigAccountPage extends LitElement {
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

  @state()
  private pendingRegistrationMessage = "";

  @state()
  private adminUsers: AdminUserInfo[] = [];

  @state()
  private syncState: SyncState | null = null;

  @state()
  private pendingSyncCount = 0;

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
    });
    this.account = account;
    await this.reloadCloudState();
  }

  private async reloadCloudState() {
    this.apiHealthy = await checkCloudApiHealth(this.account);
    if (!isAccountConnected(this.account)) {
      this.adminUsers = [];
      this.syncState = null;
      this.pendingSyncCount = 0;
      return;
    }
    try {
      this.account = await refreshAccountSession(this.account);
      this.syncState = await getActiveDatasetSyncState();
      this.pendingSyncCount = await getActivePendingCount();
      if (isCloudAdmin(this.account)) {
        this.adminUsers = await fetchAdminUsers(undefined, this.account);
      } else {
        this.adminUsers = [];
      }
    } catch (error) {
      this.account = loadAccountSettings();
      this.adminUsers = [];
      this.syncState = null;
      this.pendingSyncCount = 0;
      this.statusMessage =
        error instanceof Error ? error.message : tx("dialogs.unknown_error");
      console.error(error);
    }
  }

  private persistApiBaseUrl() {
    const form = read(appConfigKey.path) as AppConfigForm;
    const next = {
      ...this.account,
      apiBaseUrl: form.accountApiBaseUrl.trim(),
    };
    saveAccountSettings(next);
    this.account = next;
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
      this.persistApiBaseUrl();
      this.account = await loginAccount(
        email,
        password,
        form.accountApiBaseUrl,
      );
      set(appConfigKey.path, {...form, accountPassword: ""});
      this.statusMessage = tx("account.connected_as");
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onRegister = async () => {
    if (this.busy) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const email = form.accountEmail.trim();
    const password = form.accountPassword;
    if (!email || password.length < 8) {
      await showError(
        new Error(tx("dialogs.unknown_error")),
        tx("dialogs.error"),
      );
      return;
    }

    this.busy = true;
    this.statusMessage = "";
    this.pendingRegistrationMessage = "";
    try {
      this.persistApiBaseUrl();
      const result = await registerAccount(
        email,
        password,
        form.accountApiBaseUrl,
      );
      set(appConfigKey.path, {...form, accountPassword: ""});
      if (result.pending) {
        this.pendingRegistrationMessage = result.message;
        this.statusMessage = result.message;
      } else {
        this.account = result.settings;
        this.statusMessage = result.message;
        await this.reloadCloudState();
      }
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onLogout = async () => {
    if (this.busy) return;
    this.account = logoutAccount();
    this.statusMessage = tx("account.logout");
    const form = read(appConfigKey.path) as AppConfigForm;
    set(appConfigKey.path, {
      ...form,
      accountPassword: "",
      accountEmail: "",
    });
    await this.reloadCloudState();
  };

  private onSyncNow = async () => {
    if (this.busy || !isAccountConnected(this.account)) return;
    this.busy = true;
    this.statusMessage = tx("account.sync.title");
    try {
      const result = await runDatasetSync({fullPull: true});
      if (result.error) {
        this.statusMessage = result.error;
      } else {
        this.statusMessage = tx("account.sync.now");
      }
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private formatDate(value: string): string {
    try {
      return new Date(value).toLocaleString("fr-FR", {
        dateStyle: "short",
        timeStyle: "short",
      });
    } catch {
      return value;
    }
  }

  private onApproveUser = async (user: AdminUserInfo) => {
    if (this.busy) return;
    this.busy = true;
    try {
      await approveAdminUser(user.id, this.account);
      this.statusMessage = tx("account.admin.approve");
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  private onRejectUser = async (user: AdminUserInfo) => {
    if (this.busy) return;
    this.busy = true;
    try {
      await rejectAdminUser(user.id, this.account);
      this.statusMessage = tx("account.admin.reject");
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  private onDisableUser = async (user: AdminUserInfo) => {
    if (this.busy) return;
    const ok = await confirmDialog({
      title: tx("account.admin.disable_title"),
      message: tf("account.admin.disable_confirm", {email: user.email}),
      confirmLabel: tx("account.admin.disable"),
      danger: true,
    });
    if (!ok) return;
    this.busy = true;
    try {
      await disableAdminUser(user.id, this.account);
      this.statusMessage = tx("account.admin.disable");
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  private renderConnectionStatus() {
    const connected = isAccountConnected(this.account);
    const healthLabel =
      this.apiHealthy === null
        ? tx("account.api_checking")
        : this.apiHealthy
          ? tx("account.api_ok")
          : tx("account.api_ko");

    return html`
      <sonic-alert status=${connected ? "success" : "info"}>
        ${connected
          ? html`<span class="inline-flex items-center gap-2">
              <user-avatar
                email=${this.account.user?.email ?? ""}
                .size=${28}
              ></user-avatar>
              <span
                >${t("account.connected_as")}
                <strong>${this.account.user?.email}</strong>.</span
              >
            </span>`
          : html`${t("account.local_only")}`}
        <div class="mt-1 text-sm opacity-80">${healthLabel}</div>
      </sonic-alert>
      ${this.statusMessage
        ? html`<p class="text-sm text-neutral-500">${this.statusMessage}</p>`
        : nothing}
    `;
  }

  private renderAuthForm() {
    if (isAccountConnected(this.account)) {
      return html`
        <sonic-form-actions>
          <sonic-button
            type="neutral"
            variant="outline"
            ?disabled=${this.busy}
            @click=${this.onLogout}
          >
            ${t("account.logout")}
          </sonic-button>
        </sonic-form-actions>
      `;
    }

    return html`
      <sonic-form-layout>
        <sonic-input
          formDataProvider=${appConfigKey.path}
          name="accountApiBaseUrl"
          label=${tx("account.api_url")}
          placeholder=${tx("account.api_url_ph")}
        ></sonic-input>
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
          ${t("account.login")}
        </sonic-button>
        <sonic-button
          variant="outline"
          ?disabled=${this.busy}
          @click=${this.onRegister}
        >
          ${t("account.register")}
        </sonic-button>
      </sonic-form-actions>
      <p class="text-sm text-neutral-500">${t("account.register_hint")}</p>
      ${this.pendingRegistrationMessage
        ? html`<sonic-alert status="info"
            >${this.pendingRegistrationMessage}</sonic-alert
          >`
        : nothing}
    `;
  }

  private renderAdminUsers() {
    if (!isCloudAdmin(this.account)) return nothing;
    const pending = this.adminUsers.filter((u) => u.status === "pending");
    const others = this.adminUsers.filter((u) => u.status !== "pending");

    return html`
      <section class="flex flex-col gap-3 border-t border-current/15 pt-8">
        <h2 class="text-lg font-semibold">${t("account.admin.title")}</h2>
        ${pending.length === 0
          ? html`<p class="text-sm text-neutral-500">
              ${t("account.admin.no_pending")}
            </p>`
          : html`
              <ul class="flex flex-col gap-2">
                ${pending.map(
                  (user) => html`
                    <li
                      class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 py-2"
                    >
                      <div class="flex min-w-0 items-center gap-2">
                        <user-avatar
                          email=${user.email}
                          .size=${32}
                        ></user-avatar>
                        <div class="min-w-0">
                          <div class="truncate font-medium">${user.email}</div>
                          <div class="text-sm text-neutral-500">
                            ${tf("account.admin.requested", {
                              date: this.formatDate(user.createdAt),
                            })}
                          </div>
                        </div>
                      </div>
                      <div class="flex gap-2">
                        <sonic-button
                          type="primary"
                          size="sm"
                          ?disabled=${this.busy}
                          @click=${() => this.onApproveUser(user)}
                          >${t("account.admin.approve")}</sonic-button
                        >
                        <sonic-button
                          variant="outline"
                          size="sm"
                          ?disabled=${this.busy}
                          @click=${() => this.onRejectUser(user)}
                          >${t("account.admin.reject")}</sonic-button
                        >
                      </div>
                    </li>
                  `,
                )}
              </ul>
            `}
        ${others.length
          ? html`
              <h3 class="text-sm font-medium text-neutral-600">
                ${t("account.admin.other")}
              </h3>
              <ul class="flex flex-col gap-2">
                ${others.map(
                  (user) => html`
                    <li
                      class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 py-2"
                    >
                      <div class="flex min-w-0 items-center gap-2">
                        <user-avatar
                          email=${user.email}
                          .size=${32}
                        ></user-avatar>
                        <div class="min-w-0">
                          <div class="truncate font-medium">${user.email}</div>
                          <div class="text-sm text-neutral-500">
                            ${user.status} — ${this.formatDate(user.createdAt)}
                          </div>
                        </div>
                      </div>
                      ${user.status === "active"
                        ? html`<sonic-button
                            type="danger"
                            variant="outline"
                            size="sm"
                            ?disabled=${this.busy}
                            @click=${() => this.onDisableUser(user)}
                            >${t("account.admin.disable")}</sonic-button
                          >`
                        : user.status === "rejected" ||
                            user.status === "disabled"
                          ? html`<sonic-button
                              type="primary"
                              variant="outline"
                              size="sm"
                              ?disabled=${this.busy}
                              @click=${() => this.onApproveUser(user)}
                              >${t("account.admin.reactivate")}</sonic-button
                            >`
                          : nothing}
                    </li>
                  `,
                )}
              </ul>
            `
          : nothing}
      </section>
    `;
  }

  private renderSyncSection() {
    if (!isAccountConnected(this.account)) return nothing;

    const lastSync = this.syncState?.lastSyncAt
      ? this.formatDate(this.syncState.lastSyncAt)
      : tx("account.sync.never");

    return html`
      <section class="space-y-3 border-t border-current/15 pt-8">
        <h2 class="text-lg font-semibold">${t("account.sync.title")}</h2>
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <sonic-badge
            type=${this.pendingSyncCount > 0 ? "warning" : "neutral"}
            size="sm"
          >
            ${tf("account.sync.pending", {n: this.pendingSyncCount})}
          </sonic-badge>
          <span class="text-neutral-500"
            >${t("account.sync.last")} ${lastSync}</span
          >
        </div>
        ${this.syncState?.lastError
          ? html`<sonic-alert type="warning" size="sm"
              >${this.syncState.lastError}</sonic-alert
            >`
          : nothing}
        <sonic-form-actions>
          <sonic-button
            type="primary"
            size="sm"
            ?disabled=${this.busy}
            @click=${this.onSyncNow}
          >
            ${t("account.sync.now")}
          </sonic-button>
        </sonic-form-actions>
        <p class="text-sm text-neutral-500">${t("account.sync.help")}</p>
      </section>
    `;
  }

  render() {
    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="account"></config-scope-header>
        </div>

        <div class="space-y-8 pt-8">
          ${this.renderConnectionStatus()} ${this.renderAuthForm()}
          ${this.renderAdminUsers()} ${this.renderSyncSection()}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-account-page": ConfigAccountPage;
  }
}
