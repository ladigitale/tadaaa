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
  getMcpUrl,
  loadAccountSettings,
  saveAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  approveAdminUser,
  checkCloudApiHealth,
  createAccessToken,
  createCloudDataset,
  createDatasetInvite,
  deleteCloudDataset,
  disableAdminUser,
  fetchAccessTokens,
  fetchAdminUsers,
  fetchCloudDatasets,
  fetchDatasetMembers,
  inviteDatasetByEmail,
  loginAccount,
  logoutAccount,
  refreshAccountSession,
  registerAccount,
  rejectAdminUser,
  removeDatasetMember,
  renameCloudDataset,
  revokeAccessToken,
  type AccessTokenInfo,
  type AdminUserInfo,
  type CloudDatasetInfo,
  type DatasetMemberInfo,
} from "../cloud-api/client";
import {formatBaseId} from "../api/data-package";
import {getIdbTodoStore} from "../api/store-idb";
import {
  getActiveDatasetSyncState,
  getActivePendingCount,
  openCloudDatasetForEditing,
  runDatasetSync,
} from "../sync/engine";
import type {SyncState} from "../sync/outbox-types";
import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {confirmDialog, promptTextDialog, showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./access-token-row";
import "./config-scope-header";
import "./dataset-row";
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

  @subscribe(appConfigKey.newCloudDatasetName)
  @state()
  newCloudDatasetName = "";

  @subscribe(appConfigKey.newAccessTokenName)
  @state()
  newAccessTokenName = "";

  @state()
  private account: AccountSettings = loadAccountSettings();

  @state()
  private cloudDatasets: CloudDatasetInfo[] = [];

  /** baseId du jeu local actif (édition web), normalisé. */
  @state()
  private editingBaseId: string | null = null;

  @state()
  private accessTokens: AccessTokenInfo[] = [];

  @state()
  private lastPlainToken: string | null = null;

  @state()
  private mcpUrl = getMcpUrl();

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

  @state()
  private sharingDataset: CloudDatasetInfo | null = null;

  @state()
  private shareMembers: DatasetMemberInfo[] = [];

  @state()
  private shareInviteRole: "writer" | "reader" = "reader";

  @state()
  private lastInviteUrl: string | null = null;

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
    });
    this.account = account;
    await this.reloadCloudState();
  }

  private async reloadCloudState() {
    this.apiHealthy = await checkCloudApiHealth(this.account);
    this.mcpUrl = getMcpUrl(this.account);
    if (!isAccountConnected(this.account)) {
      this.cloudDatasets = [];
      this.editingBaseId = null;
      this.accessTokens = [];
      this.adminUsers = [];
      this.syncState = null;
      this.pendingSyncCount = 0;
      return;
    }
    try {
      this.account = await refreshAccountSession(this.account);
      this.cloudDatasets = await fetchCloudDatasets(this.account);
      const locals = await getIdbTodoStore().listDatasets();
      const activeLocal = locals.find((dataset) => dataset.active);
      this.editingBaseId = activeLocal
        ? formatBaseId(activeLocal.baseId)
        : null;
      this.accessTokens = await fetchAccessTokens(this.account);
      this.syncState = await getActiveDatasetSyncState();
      this.pendingSyncCount = await getActivePendingCount();
      this.mcpUrl = getMcpUrl(this.account);
      if (isCloudAdmin(this.account)) {
        this.adminUsers = await fetchAdminUsers(undefined, this.account);
      } else {
        this.adminUsers = [];
      }
    } catch (error) {
      this.account = loadAccountSettings();
      this.cloudDatasets = [];
      this.editingBaseId = null;
      this.accessTokens = [];
      this.adminUsers = [];
      this.syncState = null;
      this.pendingSyncCount = 0;
      this.statusMessage =
        error instanceof Error
          ? error.message
          : tx("dialogs.unknown_error");
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
    this.cloudDatasets = [];
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
      // Pull complet : récupère aussi les tâches inchangées (ex. parents
      // effacés localement par un ancien bug de sync incrémental).
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

  private onCreateCloudDataset = async () => {
    if (this.busy || !isAccountConnected(this.account)) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const name = form.newCloudDatasetName.trim() || tx("cloud.new_dataset");
    this.busy = true;
    try {
      await createCloudDataset(name, this.account);
      set(appConfigKey.path, {...form, newCloudDatasetName: ""});
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onActivateCloudDataset = async (
    event: CustomEvent<{dataset: CloudDatasetInfo}>,
  ) => {
    const dataset = event.detail.dataset;
    const isEditing =
      this.editingBaseId !== null &&
      formatBaseId(dataset.baseId) === this.editingBaseId;
    if (this.busy || isEditing) return;
    this.busy = true;
    this.statusMessage = tx("cloud.edit");
    try {
      const result = await openCloudDatasetForEditing(dataset);
      if (result.error) {
        this.statusMessage = result.error;
      } else {
        this.statusMessage = tx("cloud.editing");
      }
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onDeleteCloudDataset = async (
    event: CustomEvent<{dataset: CloudDatasetInfo}>,
  ) => {
    const dataset = event.detail.dataset;
    if (this.busy) return;
    const ok = await confirmDialog({
      title: tx("cloud.delete_title"),
      message: tf("cloud.delete_confirm", {name: dataset.name}),
      confirmLabel: tx("cloud.delete"),
      danger: true,
    });
    if (!ok) return;

    this.busy = true;
    try {
      await deleteCloudDataset(dataset.id, this.account);
      if (this.sharingDataset?.id === dataset.id) {
        this.sharingDataset = null;
        this.shareMembers = [];
        this.lastInviteUrl = null;
      }
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onRenameCloudDataset = async (
    event: CustomEvent<{dataset: CloudDatasetInfo}>,
  ) => {
    const dataset = event.detail.dataset;
    if (this.busy || (dataset.role ?? "owner") !== "owner") return;
    const nextName = await promptTextDialog({
      title: tx("datasets.rename_title"),
      label: tx("datasets.rename_label"),
      initialValue: dataset.name,
      confirmLabel: tx("datasets.rename_save"),
    });
    if (!nextName || nextName === dataset.name) return;
    this.busy = true;
    try {
      await renameCloudDataset(dataset.id, nextName, this.account);
      // Aligner le miroir local (même baseId) pour rester lisible.
      const store = getIdbTodoStore();
      const locals = await store.listDatasets();
      const local = locals.find(
        (row) => formatBaseId(row.baseId) === formatBaseId(dataset.baseId),
      );
      if (local && local.name !== nextName) {
        await store.renameDataset(local.id, nextName);
      }
      if (this.sharingDataset?.id === dataset.id) {
        this.sharingDataset = {...this.sharingDataset, name: nextName};
      }
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private cloudRoleLabel(role: CloudDatasetInfo["role"]): string {
    switch (role) {
      case "writer":
        return tx("cloud.role_badge_writer");
      case "reader":
        return tx("cloud.role_badge_reader");
      default:
        return "";
    }
  }

  private onShareCloudDataset = async (
    event: CustomEvent<{dataset: CloudDatasetInfo}>,
  ) => {
    const dataset = event.detail?.dataset;
    if (!dataset?.id) {
      await showError(new Error(tx("dialogs.unknown_error")), tx("cloud.share"));
      return;
    }
    this.sharingDataset = dataset;
    this.lastInviteUrl = null;
    this.shareInviteRole = "reader";
    this.shareMembers = [];
    this.busy = true;
    try {
      this.shareMembers = await fetchDatasetMembers(dataset.id, this.account);
    } catch (error) {
      this.shareMembers = [];
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
      await this.updateComplete;
      this.renderRoot
        .querySelector<HTMLElement>("[data-share-panel]")
        ?.scrollIntoView({behavior: "smooth", block: "nearest"});
    }
  };

  private onCloseSharePanel = () => {
    this.sharingDataset = null;
    this.shareMembers = [];
    this.lastInviteUrl = null;
    const form = read(appConfigKey.path) as AppConfigForm;
    set(appConfigKey.path, {...form, shareInviteEmail: ""});
  };

  private onCreateInvite = async () => {
    if (!this.sharingDataset || this.busy) return;
    this.busy = true;
    this.statusMessage = "";
    try {
      const invite = await createDatasetInvite(
        this.sharingDataset.id,
        this.shareInviteRole,
        this.account,
      );
      const url = `${window.location.origin}${invite.urlPath}`;
      this.lastInviteUrl = url;
      try {
        await navigator.clipboard.writeText(url);
        this.statusMessage = tx("cloud.link_copied");
      } catch {
        this.statusMessage = tx("cloud.link_ready");
      }
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onInviteByEmail = async () => {
    if (!this.sharingDataset || this.busy) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const email = form.shareInviteEmail.trim();
    if (!email) {
      await showError(new Error(tx("cloud.invite_email_required")), tx("cloud.invite_email"));
      return;
    }
    this.busy = true;
    this.statusMessage = "";
    try {
      const invite = await inviteDatasetByEmail(
        this.sharingDataset.id,
        email,
        this.shareInviteRole,
        this.account,
      );
      const url = `${window.location.origin}${invite.urlPath}`;
      this.lastInviteUrl = url;
      set(appConfigKey.path, {...form, shareInviteEmail: ""});
      this.statusMessage = invite.notified
        ? tf("cloud.invite_email_sent", {email: invite.email})
        : tf("cloud.invite_email_link_only", {email: invite.email});
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onRemoveShareMember = async (member: DatasetMemberInfo) => {
    if (!this.sharingDataset || member.role === "owner" || this.busy) return;
    const ok = await confirmDialog({
      title: tx("cloud.remove_member_title"),
      message: tf("cloud.remove_member_confirm", {email: member.email}),
      confirmLabel: tx("cloud.remove"),
      danger: true,
    });
    if (!ok) return;
    this.busy = true;
    try {
      await removeDatasetMember(
        this.sharingDataset.id,
        member.userId,
        this.account,
      );
      this.shareMembers = await fetchDatasetMembers(
        this.sharingDataset.id,
        this.account,
      );
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

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
      await this.reloadCloudState();
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
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

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

  /** Config Cursor mcpServers — HTTP Bearer (prod) ; en DEV, hint stdio optionnel sans chemins locaux. */
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
      <p class="text-sm text-neutral-500">
        ${t("account.register_hint")}
      </p>
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
                        : user.status === "rejected" || user.status === "disabled"
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
          <sonic-badge type=${this.pendingSyncCount > 0 ? "warning" : "neutral"} size="sm">
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

  private renderCloudDatasets() {
    if (!isAccountConnected(this.account)) return nothing;

    return html`
      <section class="space-y-3 border-t border-current/15 pt-8">
        <h2 class="text-lg font-semibold">${t("cloud.datasets_title")}</h2>
        <p class="text-sm text-neutral-500">${t("cloud.datasets_help")}</p>
        ${this.syncState?.cloudRole === "reader"
          ? html`
              <sonic-alert type="info" size="sm">
                ${t("cloud.readonly_alert")}
              </sonic-alert>
            `
          : nothing}
        ${this.renderSharePanel()}
        <sonic-form-layout>
          <sonic-input
            formDataProvider=${appConfigKey.path}
            name="newCloudDatasetName"
            label=${tx("cloud.new_dataset")}
            placeholder=${tx("cloud.new_dataset_ph")}
          ></sonic-input>
        </sonic-form-layout>
        <sonic-form-actions>
          <sonic-button
            type="primary"
            size="sm"
            ?disabled=${this.busy}
            @click=${this.onCreateCloudDataset}
          >
            ${t("cloud.create")}
          </sonic-button>
        </sonic-form-actions>

        ${this.cloudDatasets.length === 0
          ? html`<p class="text-sm text-neutral-500">${t("cloud.none")}</p>`
          : html`
              <ul class="m-0 list-none p-0">
                ${this.cloudDatasets.map((dataset, index) => {
                  const editing =
                    this.editingBaseId !== null &&
                    formatBaseId(dataset.baseId) === this.editingBaseId;
                  const rowInfo = {...dataset, active: editing};
                  const isOwner = (dataset.role ?? "owner") === "owner";
                  return html`
                    <li>
                      ${index > 0 ? this.listSeparator() : nothing}
                      <dataset-row
                        .datasetInfo=${rowInfo}
                        activeLabel=${tx("cloud.editing")}
                        activateLabel=${tx("cloud.edit")}
                        roleLabel=${this.cloudRoleLabel(dataset.role)}
                        ?mcpActive=${dataset.active}
                        ?canShare=${isOwner}
                        ?canRename=${isOwner}
                        ?canDelete=${isOwner}
                        ?disabled=${this.busy}
                        @dataset-activate=${this.onActivateCloudDataset}
                        @dataset-delete=${this.onDeleteCloudDataset}
                        @dataset-rename=${this.onRenameCloudDataset}
                        @dataset-share=${this.onShareCloudDataset}
                      ></dataset-row>
                    </li>
                  `;
                })}
              </ul>
            `}
      </section>
    `;
  }

  private renderSharePanel() {
    if (!this.sharingDataset) return nothing;

    return html`
      <div
        data-share-panel
        class="space-y-3 rounded-md border-2 border-current/25 bg-neutral-500/10 p-4"
      >
        <div class="flex items-start justify-between gap-2">
          <div>
            <h3 class="text-base font-semibold">
              ${tx("cloud.share_title")} « ${this.sharingDataset.name} »
            </h3>
            <p class="text-sm text-neutral-500">${tx("cloud.share_help")}</p>
          </div>
          <sonic-button
            size="sm"
            variant="ghost"
            ?disabled=${this.busy}
            @click=${this.onCloseSharePanel}
            >${tx("cloud.close")}</sonic-button
          >
        </div>
        <div class="flex flex-wrap gap-2">
          <sonic-button
            size="sm"
            type=${this.shareInviteRole === "reader" ? "primary" : "default"}
            ?disabled=${this.busy}
            @click=${() => {
              this.shareInviteRole = "reader";
            }}
            >${tx("cloud.role_reader")}</sonic-button
          >
          <sonic-button
            size="sm"
            type=${this.shareInviteRole === "writer" ? "primary" : "default"}
            ?disabled=${this.busy}
            @click=${() => {
              this.shareInviteRole = "writer";
            }}
            >${tx("cloud.role_writer")}</sonic-button
          >
          <sonic-button
            size="sm"
            type="primary"
            ?disabled=${this.busy}
            @click=${this.onCreateInvite}
            >${tx("cloud.create_link")}</sonic-button
          >
        </div>
        <sonic-form-layout>
          <sonic-input
            formDataProvider=${appConfigKey.path}
            name="shareInviteEmail"
            type="email"
            label=${tx("cloud.invite_email")}
            placeholder=${tx("cloud.invite_email_ph")}
          ></sonic-input>
        </sonic-form-layout>
        <sonic-form-actions>
          <sonic-button
            size="sm"
            type="primary"
            ?disabled=${this.busy}
            @click=${this.onInviteByEmail}
            >${tx("cloud.invite_email_send")}</sonic-button
          >
        </sonic-form-actions>
        <p class="text-sm text-neutral-500">${tx("cloud.invite_email_help")}</p>
        ${this.lastInviteUrl
          ? html`
              <p class="break-all font-mono text-xs text-neutral-700">
                ${this.lastInviteUrl}
              </p>
            `
          : nothing}
        <div class="space-y-2">
          <h4 class="text-sm font-medium">${tx("cloud.members")}</h4>
          ${this.shareMembers.length === 0
            ? html`<p class="text-sm text-neutral-500">${tx("cloud.no_members")}</p>`
            : html`
                <ul class="m-0 list-none space-y-2 p-0">
                  ${this.shareMembers.map(
                    (member) => html`
                      <li
                        class="flex items-center justify-between gap-2 text-sm"
                      >
                        <span class="flex min-w-0 items-center gap-2">
                          <user-avatar
                            email=${member.email}
                            .size=${28}
                          ></user-avatar>
                          <span class="min-w-0 truncate"
                            >${member.email}
                            <span class="text-neutral-500"
                              >(${member.role})</span
                            ></span
                          >
                        </span>
                        ${member.role !== "owner"
                          ? html`
                              <sonic-button
                                size="xs"
                                variant="ghost"
                                type="danger"
                                ?disabled=${this.busy}
                                @click=${() => this.onRemoveShareMember(member)}
                                >${tx("cloud.remove")}</sonic-button
                              >
                            `
                          : nothing}
                      </li>
                    `,
                  )}
                </ul>
              `}
        </div>
      </div>
    `;
  }

  private renderMcpSection() {
    if (!isAccountConnected(this.account)) return nothing;

    return html`
      <section class="space-y-3 border-t border-current/15 pt-8">
        <h2 class="text-lg font-semibold">${t("account.mcp.title")}</h2>
        <p class="text-sm text-neutral-500">
          ${tf("account.mcp.help", {url: this.mcpUrl})}
        </p>
        ${this.lastPlainToken
          ? html`
              <sonic-alert type="warning" size="sm">
                ${t("account.mcp.secret_once")}
                <code class="break-all text-xs">${this.lastPlainToken}</code>
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
          ? html`<p class="text-sm text-neutral-500">${t("account.mcp.none")}</p>`
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
          ${this.renderConnectionStatus()}
          ${this.renderAuthForm()}
          ${this.renderAdminUsers()}
          ${this.renderSyncSection()}
          ${this.renderMcpSection()}
          ${this.renderCloudDatasets()}
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
