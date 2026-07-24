import "@supersoniks/concorde/button";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/tooltip";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  isAccountConnected,
  loadAccountSettings,
  type AccountSettings,
} from "../account-settings";
import {
  acceptDatasetInvite,
  previewDatasetInvite,
  type DatasetInvitePreview,
} from "../cloud-api/client";
import {openCloudDatasetForEditing} from "../sync/engine";
import {navigateTo} from "../utils/navigate";
import {showError} from "../utils/modal-dialog";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tx} from "../i18n";
import tailwind from "../../css/tailwind";
import "./page-shell";

function readInviteToken(): string {
  const params = new URLSearchParams(window.location.search);
  return (params.get("token") ?? "").trim();
}

@customElement("invite-page")
export class InvitePage extends LitElement {
  static styles = [tailwind];

  @state()
  private account: AccountSettings = loadAccountSettings();

  @state()
  private token = "";

  @state()
  private preview: DatasetInvitePreview | null = null;

  @state()
  private busy = false;

  @state()
  private statusMessage = "";

  connectedCallback() {
    super.connectedCallback();
    this.token = readInviteToken();
    void this.loadPreview();
  }

  private async loadPreview() {
    if (!this.token) {
      this.statusMessage = tx("invite.invalid_token");
      return;
    }
    if (!isAccountConnected(this.account)) {
      this.statusMessage = tx("invite.need_login");
      return;
    }
    this.busy = true;
    try {
      this.preview = await previewDatasetInvite(this.token, this.account);
      if (!this.preview.usable) {
        this.statusMessage = tx("invite.unusable");
      }
    } catch (error) {
      this.preview = null;
      this.statusMessage =
        error instanceof Error ? error.message : tx("invite.load_error");
    } finally {
      this.busy = false;
    }
  }

  private onAccept = async () => {
    if (!this.token || this.busy || !isAccountConnected(this.account)) return;
    this.busy = true;
    this.statusMessage = "";
    try {
      const result = await acceptDatasetInvite(this.token, this.account);
      const sync = await openCloudDatasetForEditing({
        id: result.dataset.id,
        baseId: result.dataset.baseId,
        name: result.dataset.name,
        role: result.dataset.role,
      });
      if (sync.error) {
        this.statusMessage = sync.error;
      }
      navigateTo("/tache");
    } catch (error) {
      await showError(error, tx("invite.accept_error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onGoAccount = () => {
    navigateTo("/config/account");
  };

  render() {
    const connected = isAccountConnected(this.account);

    return html`
      <page-shell>
        <div class="mb-4">
          <sonic-tooltip label=${tx("config.back")} placement="bottom">
            <sonic-button
              goBack
              shape="circle"
              variant="ghost"
              size="sm"
              data-aria-label=${tx("config.back")}
            >
              <sonic-icon
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="nav-arrow-left"
                size="sm"
              ></sonic-icon>
            </sonic-button>
          </sonic-tooltip>
          <h1 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">
            ${t("invite.title")}
          </h1>
          <p class="text-sm text-neutral-500">${t("invite.subtitle")}</p>
        </div>

        <div class="mx-auto max-w-lg space-y-4">
          ${this.statusMessage
            ? html`<sonic-alert type="info" size="sm"
                >${this.statusMessage}</sonic-alert
              >`
            : nothing}

          ${!connected
            ? html`
                <p class="text-sm text-neutral-600">
                  ${t("invite.need_account")}
                </p>
                <sonic-button type="primary" @click=${this.onGoAccount}
                  >${t("invite.go_account")}</sonic-button
                >
              `
            : nothing}

          ${this.preview
            ? html`
                <div class="space-y-2">
                  <p class="text-base font-medium">${this.preview.datasetName}</p>
                  <p class="text-sm text-neutral-500">
                    ${t("invite.role")} :
                    ${this.preview.role === "writer"
                      ? t("invite.role_writer")
                      : t("invite.role_reader")}
                    — ${t("invite.expires")}
                    ${new Date(this.preview.expiresAt).toLocaleString()}
                  </p>
                  <sonic-button
                    type="primary"
                    ?disabled=${this.busy || !this.preview.usable}
                    @click=${this.onAccept}
                    >${t("invite.accept")}</sonic-button
                  >
                </div>
              `
            : nothing}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "invite-page": InvitePage;
  }
}
