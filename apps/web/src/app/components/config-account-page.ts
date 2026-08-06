import "@supersoniks/concorde/button";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  ACCOUNT_CHANGED_EVENT,
  isAccountConnected,
  isCloudAdmin,
  loadAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  approveAdminUser,
  checkCloudApiHealth,
  deleteAdminUser,
  deleteMyAccount,
  disableAdminUser,
  exportMyAccountData,
  fetchAdminUsers,
  logoutAccount,
  refreshAccountSession,
  rejectAdminUser,
  type AdminUserInfo,
} from "../cloud-api/client";
import {
  clearAccountCredentialsFields,
  hydrateAccountForm,
} from "../utils/account-form";
import {navigateTo} from "../utils/navigate";
import {legalPath} from "../legal";
import {
  confirmDialog,
  promptTextDialog,
  showError,
} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./config-scope-header";
import "./legal-footer-links";
import "./page-shell";
import "./user-avatar";

@customElement("config-account-page")
export class ConfigAccountPage extends LitElement {
  static styles = [tailwind];

  @state()
  private account: AccountSettings = loadAccountSettings();

  @state()
  private apiHealthy: boolean | null = null;

  @state()
  private busy = false;

  @state()
  private statusMessage = "";

  @state()
  private adminUsers: AdminUserInfo[] = [];

  @state()
  private adminMessage = "";

  private onAccountChanged = () => {
    this.account = loadAccountSettings();
    if (!isAccountConnected(this.account)) {
      this.adminUsers = [];
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
    hydrateAccountForm(account);
    this.account = account;
    if (!isAccountConnected(account)) return;
    await this.reloadCloudState();
  }

  private async reloadCloudState() {
    this.apiHealthy = await checkCloudApiHealth(this.account);
    if (!isAccountConnected(this.account)) {
      this.adminUsers = [];
      return;
    }
    try {
      this.account = await refreshAccountSession(this.account);
      if (isCloudAdmin(this.account)) {
        this.adminUsers = await fetchAdminUsers(undefined, this.account);
      } else {
        this.adminUsers = [];
      }
    } catch (error) {
      this.account = loadAccountSettings();
      this.adminUsers = [];
      this.statusMessage =
        error instanceof Error ? error.message : tx("dialogs.unknown_error");
      console.error(error);
    }
  }

  private onLogout = async () => {
    if (this.busy) return;
    this.account = logoutAccount();
    clearAccountCredentialsFields();
    navigateTo("/");
  };

  private onExportMyData = async () => {
    if (this.busy) return;
    this.busy = true;
    try {
      const pack = await exportMyAccountData(this.account);
      const blob = new Blob([JSON.stringify(pack, null, 2)], {
        type: "application/json",
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `tadaaa-export-${new Date().toISOString().slice(0, 10)}.json`;
      a.click();
      URL.revokeObjectURL(url);
      this.statusMessage = tx("account.gdpr.export_ok");
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  private onDeleteMyAccount = async () => {
    if (this.busy) return;
    const email = this.account.user?.email ?? "";
    const confirmed = await confirmDialog({
      title: tx("account.gdpr.delete_title"),
      message: tx("account.gdpr.delete_help"),
      confirmLabel: tx("account.gdpr.delete"),
      danger: true,
    });
    if (!confirmed) return;
    const typed = await promptTextDialog({
      title: tx("account.gdpr.delete_title"),
      label: tx("account.gdpr.delete_prompt"),
      confirmLabel: tx("account.gdpr.delete"),
    });
    if (typed === null) return;
    if (typed.toLowerCase() !== email.toLowerCase()) {
      await showError(
        new Error(tx("account.gdpr.delete_mismatch")),
        tx("dialogs.error"),
      );
      return;
    }
    this.busy = true;
    try {
      await deleteMyAccount(typed, this.account);
      this.account = logoutAccount();
      clearAccountCredentialsFields();
      this.statusMessage = tx("account.gdpr.delete_ok");
      navigateTo("/");
    } catch (error) {
      await showError(error, tx("dialogs.error"));
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
      await approveAdminUser(user.id, this.account, this.adminMessage);
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
      await rejectAdminUser(user.id, this.account, this.adminMessage);
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
      await disableAdminUser(user.id, this.account, this.adminMessage);
      this.statusMessage = tx("account.admin.disable");
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  private onDeleteUser = async (user: AdminUserInfo) => {
    if (this.busy) return;
    const ok = await confirmDialog({
      title: tx("account.admin.delete_title"),
      message: tf("account.admin.delete_confirm", {email: user.email}),
      confirmLabel: tx("account.admin.delete"),
      danger: true,
    });
    if (!ok) return;
    this.busy = true;
    try {
      await deleteAdminUser(user.id, this.account, this.adminMessage);
      this.statusMessage = tx("account.admin.delete");
      await this.reloadCloudState();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  private renderConnectionStatus() {
    const healthLabel =
      this.apiHealthy === null
        ? tx("account.api_checking")
        : this.apiHealthy
          ? tx("account.api_ok")
          : tx("account.api_ko");

    return html`
      <sonic-alert status="success">
        <span class="inline-flex items-center gap-2">
          <user-avatar
            email=${this.account.user?.email ?? ""}
            .size=${28}
          ></user-avatar>
          <span
            >${t("account.connected_as")}
            <strong>${this.account.user?.email}</strong>.</span
          >
        </span>
        <div class="mt-1 text-sm opacity-80">${healthLabel}</div>
      </sonic-alert>
      ${this.statusMessage
        ? html`<p class="text-sm text-neutral-500">${this.statusMessage}</p>`
        : nothing}
    `;
  }

  private renderGdprSection() {
    return html`
      <section class="flex flex-col gap-3 border-t border-current/15 pt-8">
        <h2 class="text-lg font-semibold">${t("account.gdpr.title")}</h2>
        <p class="text-sm text-neutral-600">${t("account.gdpr.export_help")}</p>
        <sonic-form-actions>
          <sonic-button
            variant="outline"
            ?disabled=${this.busy}
            @click=${this.onExportMyData}
          >
            ${t("account.gdpr.export")}
          </sonic-button>
          <sonic-button
            type="danger"
            variant="outline"
            ?disabled=${this.busy}
            @click=${this.onDeleteMyAccount}
          >
            ${t("account.gdpr.delete")}
          </sonic-button>
        </sonic-form-actions>
        <p class="text-sm text-neutral-500">
          <a
            href=${legalPath("privacy")}
            class="underline"
            @click=${(e: Event) => {
              e.preventDefault();
              navigateTo(legalPath("privacy"));
            }}
            >${t("legal.nav.privacy")}</a
          >
        </p>
      </section>
    `;
  }

  private renderAdminUsers() {
    if (!isCloudAdmin(this.account)) return nothing;
    const pending = this.adminUsers.filter((u) => u.status === "pending");
    const others = this.adminUsers.filter((u) => u.status !== "pending");

    return html`
      <section class="flex flex-col gap-3 border-t border-current/15 pt-8">
        <h2 class="text-lg font-semibold">${t("account.admin.title")}</h2>
        <label class="flex flex-col gap-1 text-sm">
          <span>${t("account.admin.message_label")}</span>
          <textarea
            class="min-h-[4.5rem] rounded border border-current/20 bg-transparent p-2"
            .value=${this.adminMessage}
            @input=${(e: Event) => {
              this.adminMessage = (e.target as HTMLTextAreaElement).value;
            }}
            placeholder=${tx("account.admin.message_ph")}
          ></textarea>
        </label>
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
                      <div class="flex flex-wrap gap-2">
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
                        <sonic-button
                          type="danger"
                          variant="outline"
                          size="sm"
                          ?disabled=${this.busy}
                          @click=${() => this.onDeleteUser(user)}
                          >${t("account.admin.delete")}</sonic-button
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
                      <div class="flex flex-wrap gap-2">
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
                        <sonic-button
                          type="danger"
                          variant="outline"
                          size="sm"
                          ?disabled=${this.busy}
                          @click=${() => this.onDeleteUser(user)}
                          >${t("account.admin.delete")}</sonic-button
                        >
                      </div>
                    </li>
                  `,
                )}
              </ul>
            `
          : nothing}
      </section>
    `;
  }

  render() {
    const connected = isAccountConnected(this.account);

    if (!connected) {
      return html`
        <page-shell>
          <div
            class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
          >
            <config-scope-header section="account"></config-scope-header>
          </div>
          <div class="pt-8">
            <account-required-cta
              messageKey="account.local_only"
            ></account-required-cta>
          </div>
        </page-shell>
      `;
    }

    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="account"></config-scope-header>
        </div>

        <div class="space-y-8 pt-8">
          ${this.renderConnectionStatus()}
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
          ${this.renderGdprSection()}
          ${this.renderAdminUsers()}
          <legal-footer-links></legal-footer-links>
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
