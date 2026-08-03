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
  isAccountConnected,
  loadAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  createCloudDataset,
  createDatasetInvite,
  deleteCloudDataset,
  fetchCloudDatasets,
  fetchDatasetMembers,
  inviteDatasetByEmail,
  refreshAccountSession,
  removeDatasetMember,
  renameCloudDataset,
  type CloudDatasetInfo,
  type DatasetMemberInfo,
} from "../cloud-api/client";
import {formatBaseId} from "../api/data-package";
import {getIdbTodoStore} from "../api/store-idb";
import {getActiveDatasetSyncState, openCloudDatasetForEditing} from "../sync/engine";
import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {confirmDialog, promptTextDialog, showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./config-scope-header";
import "./dataset-row";
import "./page-shell";
import "./user-avatar";

@customElement("config-cloud-datasets-page")
export class ConfigCloudDatasetsPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @subscribe(appConfigKey.newCloudDatasetName)
  @state()
  newCloudDatasetName = "";

  @state()
  private account: AccountSettings = loadAccountSettings();

  @state()
  private cloudDatasets: CloudDatasetInfo[] = [];

  @state()
  private editingBaseId: string | null = null;

  @state()
  private busy = false;

  @state()
  private statusMessage = "";

  @state()
  private sharingDataset: CloudDatasetInfo | null = null;

  @state()
  private shareMembers: DatasetMemberInfo[] = [];

  @state()
  private shareInviteRole: "writer" | "reader" = "reader";

  @state()
  private lastInviteUrl: string | null = null;

  @state()
  private cloudRole: string | null = null;

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
    await this.reload();
  }

  private async reload() {
    if (!isAccountConnected(this.account)) {
      this.cloudDatasets = [];
      this.editingBaseId = null;
      this.cloudRole = null;
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
      const syncState = await getActiveDatasetSyncState();
      this.cloudRole = syncState?.cloudRole ?? null;
    } catch (error) {
      this.account = loadAccountSettings();
      this.cloudDatasets = [];
      this.editingBaseId = null;
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

  private onCreateCloudDataset = async () => {
    if (this.busy || !isAccountConnected(this.account)) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const name = form.newCloudDatasetName.trim() || tx("cloud.new_dataset");
    this.busy = true;
    try {
      await createCloudDataset(name, this.account);
      set(appConfigKey.path, {...form, newCloudDatasetName: ""});
      await this.reload();
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
      await this.reload();
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
      await this.reload();
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
      await this.reload();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

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
      await showError(
        new Error(tx("cloud.invite_email_required")),
        tx("cloud.invite_email"),
      );
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
            ? html`<p class="text-sm text-neutral-500">
                ${tx("cloud.no_members")}
              </p>`
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

  render() {
    const connected = isAccountConnected(this.account);

    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="cloud"></config-scope-header>
        </div>

        <div class="space-y-6 pt-8">
          ${!connected
            ? html`<account-required-cta
                messageKey="data.need_account"
              ></account-required-cta>`
            : html`
                <p class="text-sm text-neutral-500">${t("cloud.datasets_help")}</p>
                ${this.statusMessage
                  ? html`<p class="text-sm text-neutral-500">
                      ${this.statusMessage}
                    </p>`
                  : nothing}
                ${this.cloudRole === "reader"
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
                  ? html`<p class="text-sm text-neutral-500">
                      ${t("cloud.none")}
                    </p>`
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
              `}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-cloud-datasets-page": ConfigCloudDatasetsPage;
  }
}
