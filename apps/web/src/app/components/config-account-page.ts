import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {
  isAccountConnected,
  isCloudAdmin,
  getMcpUrl,
  loadAccountSettings,
  saveAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {
  approveAdminUser,
  checkCloudApiHealth,
  createAccessToken,
  createCloudDataset,
  deleteCloudDataset,
  disableAdminUser,
  fetchAccessTokens,
  fetchAdminUsers,
  fetchCloudDatasets,
  loginAccount,
  logoutAccount,
  refreshAccountSession,
  registerAccount,
  rejectAdminUser,
  revokeAccessToken,
  type AccessTokenInfo,
  type AdminUserInfo,
  type CloudDatasetInfo,
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
import {confirmDialog, showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./access-token-row";
import "./config-scope-header";
import "./dataset-row";
import "./page-shell";

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

  connectedCallback() {
    super.connectedCallback();
    void this.bootstrap();
  }

  private async bootstrap() {
    const account = loadAccountSettings();
    const form = read(appConfigKey.path) as AppConfigForm | undefined;
    set(appConfigKey.path, {
      issueUrlTemplate: form?.issueUrlTemplate ?? "",
      issuePattern: form?.issuePattern ?? "",
      newDatasetName: form?.newDatasetName ?? "",
      p2pReceiveCode: form?.p2pReceiveCode ?? "",
      accountEmail: account.user?.email ?? form?.accountEmail ?? "",
      accountPassword: "",
      accountApiBaseUrl: account.apiBaseUrl,
      newCloudDatasetName: form?.newCloudDatasetName ?? "",
      newAccessTokenName: form?.newAccessTokenName ?? "",
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
          : "Impossible de charger le compte cloud.";
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
        new Error("Email et mot de passe requis."),
        "Connexion impossible",
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
      this.statusMessage = "Connecté.";
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, "Connexion impossible");
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
        new Error("Email requis et mot de passe d’au moins 8 caractères."),
        "Inscription impossible",
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
      await showError(error, "Inscription impossible");
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onLogout = async () => {
    if (this.busy) return;
    this.account = logoutAccount();
    this.cloudDatasets = [];
    this.statusMessage = "Déconnecté.";
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
    this.statusMessage = "Synchronisation en cours…";
    try {
      // Pull complet : récupère aussi les tâches inchangées (ex. parents
      // effacés localement par un ancien bug de sync incrémental).
      const result = await runDatasetSync({fullPull: true});
      if (result.error) {
        this.statusMessage = result.error;
      } else {
        this.statusMessage = `Sync OK — ${result.pushed} envoyé(s), ${result.pulledTodos} tâche(s) et ${result.pulledTags} tag(s) reçus.`;
      }
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, "Synchronisation impossible");
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onCreateCloudDataset = async () => {
    if (this.busy || !isAccountConnected(this.account)) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const name = form.newCloudDatasetName.trim() || "Nouveau jeu cloud";
    this.busy = true;
    try {
      await createCloudDataset(name, this.account);
      set(appConfigKey.path, {...form, newCloudDatasetName: ""});
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, "Impossible de créer le jeu cloud");
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
    this.statusMessage = `Ouverture de « ${dataset.name} »…`;
    try {
      const result = await openCloudDatasetForEditing(dataset);
      if (result.error) {
        this.statusMessage = result.error;
      } else {
        this.statusMessage = `Édition : « ${dataset.name} » — ${result.pulledTodos} tâche(s), ${result.pulledTags} tag(s).`;
      }
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, "Impossible d’ouvrir ce jeu pour l’édition");
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
      title: "Supprimer le jeu cloud",
      message: `Supprimer « ${dataset.name} » sur le serveur ?`,
      confirmLabel: "Supprimer",
      danger: true,
    });
    if (!ok) return;

    this.busy = true;
    try {
      await deleteCloudDataset(dataset.id, this.account);
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, "Impossible de supprimer le jeu cloud");
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onCreateAccessToken = async () => {
    if (this.busy || !isAccountConnected(this.account)) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const name = form.newAccessTokenName.trim() || "MCP Cursor";
    this.busy = true;
    this.lastPlainToken = null;
    try {
      const created = await createAccessToken(name, this.account);
      this.lastPlainToken = created.plainToken;
      this.mcpUrl = created.mcpUrl;
      set(appConfigKey.path, {...form, newAccessTokenName: ""});
      await this.reloadCloudState();
      this.statusMessage =
        "Token créé — utilisez « Copier la config JSON » puis collez dans Cursor.";
    } catch (error) {
      await showError(error, "Impossible de créer le token MCP");
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
      title: "Révoquer le token",
      message: `Révoquer « ${token.name} » (${token.tokenPrefix}…) ?`,
      confirmLabel: "Révoquer",
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
      await showError(error, "Impossible de révoquer le token");
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
      this.statusMessage = "Copié dans le presse-papiers.";
    } catch {
      this.statusMessage = "Copie impossible — sélectionnez le texte manuellement.";
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
      this.statusMessage = `Compte ${user.email} approuvé.`;
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, "Approbation impossible");
    } finally {
      this.busy = false;
    }
  };

  private onRejectUser = async (user: AdminUserInfo) => {
    if (this.busy) return;
    this.busy = true;
    try {
      await rejectAdminUser(user.id, this.account);
      this.statusMessage = `Compte ${user.email} refusé.`;
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, "Refus impossible");
    } finally {
      this.busy = false;
    }
  };

  private onDisableUser = async (user: AdminUserInfo) => {
    if (this.busy) return;
    const ok = await confirmDialog({
      title: "Désactiver le compte",
      message: `Désactiver « ${user.email} » ? L’accès API et MCP sera coupé.`,
      confirmLabel: "Désactiver",
      danger: true,
    });
    if (!ok) return;
    this.busy = true;
    try {
      await disableAdminUser(user.id, this.account);
      this.statusMessage = `Compte ${user.email} désactivé.`;
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, "Désactivation impossible");
    } finally {
      this.busy = false;
    }
  };

  private onCopyMcpConfig = async () => {
    if (!this.lastPlainToken) {
      await showError(
        new Error(
          "Créez d’abord un token : le secret n’est affiché qu’une fois, puis inclus dans le JSON.",
        ),
        "Config MCP",
      );
      return;
    }
    await this.copyText(this.buildMcpServerJson(this.lastPlainToken));
    this.statusMessage = "Config MCP JSON copiée — collez-la dans Cursor (MCP).";
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
        ? "Vérification…"
        : this.apiHealthy
          ? "API joignable"
          : "API injoignable";

    return html`
      <sonic-alert status=${connected ? "success" : "info"}>
        ${connected
          ? html`Connecté en tant que <strong>${this.account.user?.email}</strong>.`
          : html`Mode local uniquement — connectez-vous pour gérer vos jeux cloud.`}
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
            Se déconnecter
          </sonic-button>
        </sonic-form-actions>
      `;
    }

    return html`
      <sonic-form-layout>
        <sonic-input
          formDataProvider=${appConfigKey.path}
          name="accountApiBaseUrl"
          label="URL de l’API"
          placeholder="https://api.example.com"
        ></sonic-input>
        <sonic-input
          formDataProvider=${appConfigKey.path}
          name="accountEmail"
          label="Email"
          type="email"
          autocomplete="username"
        ></sonic-input>
        <sonic-input
          formDataProvider=${appConfigKey.path}
          name="accountPassword"
          label="Mot de passe"
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
          Se connecter
        </sonic-button>
        <sonic-button
          variant="outline"
          ?disabled=${this.busy}
          @click=${this.onRegister}
        >
          Créer un compte
        </sonic-button>
      </sonic-form-actions>
      <p class="text-sm text-neutral-500">
        Créer un compte envoie une demande à valider par un administrateur.
        Connectez-vous ensuite pour synchroniser le jeu local actif (IndexedDB)
        avec le cloud.
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
      <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">Administration des comptes</h2>
        ${pending.length === 0
          ? html`<p class="text-sm text-neutral-500">
              Aucune demande en attente.
            </p>`
          : html`
              <ul class="flex flex-col gap-2">
                ${pending.map(
                  (user) => html`
                    <li
                      class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 py-2"
                    >
                      <div>
                        <div class="font-medium">${user.email}</div>
                        <div class="text-sm text-neutral-500">
                          Demandé le ${this.formatDate(user.createdAt)}
                        </div>
                      </div>
                      <div class="flex gap-2">
                        <sonic-button
                          type="primary"
                          size="sm"
                          ?disabled=${this.busy}
                          @click=${() => this.onApproveUser(user)}
                          >Approuver</sonic-button
                        >
                        <sonic-button
                          variant="outline"
                          size="sm"
                          ?disabled=${this.busy}
                          @click=${() => this.onRejectUser(user)}
                          >Refuser</sonic-button
                        >
                      </div>
                    </li>
                  `,
                )}
              </ul>
            `}
        ${others.length
          ? html`
              <h3 class="text-sm font-medium text-neutral-600">Autres comptes</h3>
              <ul class="flex flex-col gap-2">
                ${others.map(
                  (user) => html`
                    <li
                      class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 py-2"
                    >
                      <div>
                        <div class="font-medium">${user.email}</div>
                        <div class="text-sm text-neutral-500">
                          ${user.status} — ${this.formatDate(user.createdAt)}
                        </div>
                      </div>
                      ${user.status === "active"
                        ? html`<sonic-button
                            type="danger"
                            variant="outline"
                            size="sm"
                            ?disabled=${this.busy}
                            @click=${() => this.onDisableUser(user)}
                            >Désactiver</sonic-button
                          >`
                        : user.status === "rejected" || user.status === "disabled"
                          ? html`<sonic-button
                              type="primary"
                              variant="outline"
                              size="sm"
                              ?disabled=${this.busy}
                              @click=${() => this.onApproveUser(user)}
                              >Réactiver</sonic-button
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
      : "Jamais";

    return html`
      <section class="space-y-3 border-t border-current/15 pt-4">
        <h2 class="text-lg font-semibold">Synchronisation</h2>
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <sonic-badge type=${this.pendingSyncCount > 0 ? "warning" : "neutral"} size="sm">
            ${this.pendingSyncCount} en attente
          </sonic-badge>
          <span class="text-neutral-500">Dernière sync : ${lastSync}</span>
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
            Synchroniser maintenant
          </sonic-button>
        </sonic-form-actions>
        <p class="text-sm text-neutral-500">
          Le jeu local actif est lié au cloud par son <code>baseId</code>. Au
          premier sync, un snapshot complet est envoyé ; ensuite seules les
          modifications incrémentales sont échangées (merge champ par champ).
        </p>
      </section>
    `;
  }

  private renderCloudDatasets() {
    if (!isAccountConnected(this.account)) return nothing;

    return html`
      <section class="space-y-3 border-t border-current/15 pt-4">
        <h2 class="text-lg font-semibold">Jeux cloud</h2>
        <p class="text-sm text-neutral-500">
          <strong>Éditer</strong> bascule l’app sur ce jeu (affichage + sync).
          Le badge <strong>MCP</strong> indique le jeu actif pour les agents —
          changeable uniquement via l’outil MCP <code>activate_dataset</code>.
        </p>
        <sonic-form-layout>
          <sonic-input
            formDataProvider=${appConfigKey.path}
            name="newCloudDatasetName"
            label="Nouveau jeu cloud"
            placeholder="Nom du jeu"
          ></sonic-input>
        </sonic-form-layout>
        <sonic-form-actions>
          <sonic-button
            type="primary"
            size="sm"
            ?disabled=${this.busy}
            @click=${this.onCreateCloudDataset}
          >
            Créer sur le serveur
          </sonic-button>
        </sonic-form-actions>

        ${this.cloudDatasets.length === 0
          ? html`<p class="text-sm text-neutral-500">Aucun jeu cloud.</p>`
          : html`
              <ul class="m-0 list-none p-0">
                ${this.cloudDatasets.map((dataset, index) => {
                  const editing =
                    this.editingBaseId !== null &&
                    formatBaseId(dataset.baseId) === this.editingBaseId;
                  const rowInfo = {...dataset, active: editing};
                  return html`
                    <li>
                      ${index > 0 ? this.listSeparator() : nothing}
                      <dataset-row
                        .datasetInfo=${rowInfo}
                        activeLabel="En édition"
                        activateLabel="Éditer"
                        ?mcpActive=${dataset.active}
                        ?disabled=${this.busy}
                        @dataset-activate=${this.onActivateCloudDataset}
                        @dataset-delete=${this.onDeleteCloudDataset}
                      ></dataset-row>
                    </li>
                  `;
                })}
              </ul>
            `}
      </section>
    `;
  }

  private renderMcpSection() {
    if (!isAccountConnected(this.account)) return nothing;

    return html`
      <section class="space-y-3 border-t border-current/15 pt-4">
        <h2 class="text-lg font-semibold">MCP (agents)</h2>
        <p class="text-sm text-neutral-500">
          Endpoint HTTP :
          <code class="text-xs">${this.mcpUrl}</code>.
          Cursor refuse le certificat auto-signé en mode
          <code class="text-xs">url</code> — la config générée passe par un proxy
          stdio local (+ CA dans
          <code class="text-xs">.ops/certs/</code>).
        </p>
        ${this.lastPlainToken
          ? html`
              <sonic-alert type="warning" size="sm">
                Secret (une seule fois) :
                <code class="break-all text-xs">${this.lastPlainToken}</code>
              </sonic-alert>
              <pre
                class="max-h-48 overflow-auto rounded-md border border-current/10 bg-neutral-500/5 p-3 text-xs"
              ><code>${this.buildMcpServerJson(this.lastPlainToken)}</code></pre>
            `
          : html`
              <p class="text-sm text-neutral-500">
                Après création d’un token, la config JSON (proxy stdio + token)
                s’affiche ici pour la coller dans Cursor → MCP.
              </p>
            `}
        <sonic-form-layout>
          <sonic-input
            formDataProvider=${appConfigKey.path}
            name="newAccessTokenName"
            label="Nom du token"
            placeholder="MCP Cursor"
          ></sonic-input>
        </sonic-form-layout>
        <sonic-form-actions>
          <sonic-button
            type="primary"
            size="sm"
            ?disabled=${this.busy}
            @click=${this.onCreateAccessToken}
          >
            Créer un token MCP
          </sonic-button>
          <sonic-button
            size="sm"
            variant="outline"
            ?disabled=${!this.lastPlainToken}
            @click=${this.onCopyMcpConfig}
          >
            Copier la config JSON
          </sonic-button>
        </sonic-form-actions>
        ${this.accessTokens.length === 0
          ? html`<p class="text-sm text-neutral-500">Aucun token actif.</p>`
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
        <p class="text-sm text-neutral-500">
          Cursor → Settings → MCP → coller le JSON (ou fusionner dans
          <code>mcp.json</code>).
        </p>
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

        <div class="space-y-4 pt-4">
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
