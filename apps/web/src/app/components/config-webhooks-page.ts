import "@supersoniks/concorde/button";
import "@supersoniks/concorde/input";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {isAccountConnected} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  createWebhook,
  deleteWebhook,
  fetchCloudDatasets,
  fetchWebhookDeliveries,
  fetchWebhookEvents,
  fetchWebhooks,
  pingWebhook,
  updateWebhook,
  type CloudDatasetInfo,
  type WebhookDeliveryInfo,
  type WebhookEventInfo,
  type WebhookInfo,
} from "../cloud-api/client";
import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./config-scope-header";
import "./page-shell";

@customElement("config-webhooks-page")
export class ConfigWebhooksPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @subscribe(appConfigKey.webhookUrl)
  @state()
  webhookUrl = "";

  @state() private connected = false;
  @state() private loading = false;
  @state() private busy = false;
  @state() private webhooks: WebhookInfo[] = [];
  @state() private events: WebhookEventInfo[] = [];
  @state() private datasets: CloudDatasetInfo[] = [];
  @state() private selectedEvents: string[] = [];
  @state() private datasetId = "";
  @state() private plainSecret: string | null = null;
  @state() private deliveriesFor: string | null = null;
  @state() private deliveries: WebhookDeliveryInfo[] = [];

  connectedCallback() {
    super.connectedCallback();
    const form = read(appConfigKey.path) as AppConfigForm | undefined;
    set(appConfigKey.path, {
      newDatasetName: form?.newDatasetName ?? "",
      p2pReceiveCode: form?.p2pReceiveCode ?? "",
      accountEmail: form?.accountEmail ?? "",
      accountPassword: form?.accountPassword ?? "",
      accountApiBaseUrl: form?.accountApiBaseUrl ?? "",
      newCloudDatasetName: form?.newCloudDatasetName ?? "",
      newAccessTokenName: form?.newAccessTokenName ?? "",
      shareInviteEmail: form?.shareInviteEmail ?? "",
      webhookUrl: form?.webhookUrl ?? "",
      embedName: form?.embedName ?? "",
      embedOrigins: form?.embedOrigins ?? "",
    });
    void this.reload();
  }

  private async reload() {
    this.connected = isAccountConnected();
    if (!this.connected) {
      this.webhooks = [];
      return;
    }
    this.loading = true;
    try {
      const [hooks, catalogue, datasets] = await Promise.all([
        fetchWebhooks(),
        fetchWebhookEvents(),
        fetchCloudDatasets().catch(() => [] as CloudDatasetInfo[]),
      ]);
      this.webhooks = hooks;
      this.events = catalogue;
      this.datasets = datasets;
    } catch (error) {
      await showError(error);
    } finally {
      this.loading = false;
    }
  }

  private toggleEvent(type: string) {
    if (this.selectedEvents.includes(type)) {
      this.selectedEvents = this.selectedEvents.filter((e) => e !== type);
    } else {
      this.selectedEvents = [...this.selectedEvents, type];
    }
  }

  private onCreate = async () => {
    const form = read(appConfigKey.path) as AppConfigForm;
    const url = (form?.webhookUrl ?? this.webhookUrl).trim();
    if (!url) return;
    this.busy = true;
    try {
      const created = await createWebhook({
        url,
        events: this.selectedEvents.length ? this.selectedEvents : undefined,
        datasetId: this.datasetId || null,
      });
      this.plainSecret = created.plainSecret;
      set(appConfigKey.path, {...form, webhookUrl: ""});
      await this.reload();
    } catch (error) {
      await showError(error);
    } finally {
      this.busy = false;
    }
  };

  private onDelete = async (hook: WebhookInfo) => {
    const ok = await confirmDialog({
      title: tx("webhooks.delete_title"),
      message: tf("webhooks.delete_confirm", {url: hook.url}),
      danger: true,
    });
    if (!ok) return;
    try {
      await deleteWebhook(hook.id);
      if (this.deliveriesFor === hook.id) {
        this.deliveriesFor = null;
        this.deliveries = [];
      }
      await this.reload();
    } catch (error) {
      await showError(error);
    }
  };

  private onToggle = async (hook: WebhookInfo) => {
    try {
      await updateWebhook(hook.id, {active: !hook.active});
      await this.reload();
    } catch (error) {
      await showError(error);
    }
  };

  private onPing = async (hook: WebhookInfo) => {
    try {
      await pingWebhook(hook.id);
      if (this.deliveriesFor === hook.id) {
        this.deliveries = await fetchWebhookDeliveries(hook.id);
      }
      await this.reload();
    } catch (error) {
      await showError(error);
    }
  };

  private onDeliveries = async (hook: WebhookInfo) => {
    if (this.deliveriesFor === hook.id) {
      this.deliveriesFor = null;
      this.deliveries = [];
      return;
    }
    try {
      this.deliveries = await fetchWebhookDeliveries(hook.id);
      this.deliveriesFor = hook.id;
    } catch (error) {
      await showError(error);
    }
  };

  private formatDate(value: string | null): string {
    if (!value) return "—";
    try {
      return new Date(value).toLocaleString("fr-FR", {
        dateStyle: "short",
        timeStyle: "short",
      });
    } catch {
      return value;
    }
  }

  render() {
    return html`
      <page-shell>
        <config-scope-header section="webhooks"></config-scope-header>
        ${!this.connected
          ? html`<account-required-cta
              messageKey="webhooks.need_account"
            ></account-required-cta>`
          : html`
              <p class="text-sm opacity-80 mb-4">${t("webhooks.intro")}</p>
              ${this.plainSecret
                ? html`
                    <sonic-alert type="warning" class="mb-4">
                      ${t("webhooks.secret_once")}
                      <code class="block mt-1 break-all">${this.plainSecret}</code>
                    </sonic-alert>
                  `
                : nothing}

              <section class="border-t border-[color:var(--sc-border)] pt-4 mb-6">
                <sonic-form-layout>
                  <label class="form-label">
                    <span>${t("webhooks.url")}</span>
                    <sonic-input
                      name="webhookUrl"
                      formDataProvider=${appConfigKey.path}
                      .value=${this.webhookUrl}
                      placeholder=${tx("webhooks.url_ph")}
                    ></sonic-input>
                  </label>
                  <label class="form-label">
                    <span>${t("webhooks.dataset")}</span>
                    <select
                      class="w-full rounded border border-[color:var(--sc-border)] bg-transparent px-2 py-2"
                      .value=${this.datasetId}
                      @change=${(e: Event) => {
                        this.datasetId = (e.target as HTMLSelectElement).value;
                      }}
                    >
                      <option value="">${tx("webhooks.dataset_all")}</option>
                      ${this.datasets.map(
                        (d) => html`<option value=${d.id}>${d.name}</option>`,
                      )}
                    </select>
                  </label>
                  <div class="form-label">
                    <span>${t("webhooks.events")}</span>
                    <div class="flex flex-wrap gap-2 mt-1">
                      ${this.events.map(
                        (ev) => html`
                          <sonic-button
                            size="sm"
                            type=${this.selectedEvents.includes(ev.type)
                              ? "primary"
                              : "default"}
                            @click=${() => this.toggleEvent(ev.type)}
                          >
                            ${ev.type}
                          </sonic-button>
                        `,
                      )}
                    </div>
                  </div>
                  <sonic-form-actions>
                    <sonic-button
                      type="primary"
                      ?disabled=${this.busy}
                      @click=${this.onCreate}
                    >
                      ${t("webhooks.create")}
                    </sonic-button>
                  </sonic-form-actions>
                </sonic-form-layout>
              </section>

              <section>
                ${this.loading
                  ? html`<p class="opacity-60">…</p>`
                  : this.webhooks.length === 0
                    ? html`<p class="opacity-60">${t("webhooks.none")}</p>`
                    : this.webhooks.map((hook) => this.renderHook(hook))}
              </section>
            `}
      </page-shell>
    `;
  }

  private renderHook(hook: WebhookInfo) {
    return html`
      <div class="border-t border-[color:var(--sc-border)] py-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="font-medium break-all">${hook.url}</div>
            <div class="text-sm opacity-70 mt-1">
              ${hook.secretPrefix}…
              ·
              ${hook.active ? tx("webhooks.active") : tx("webhooks.inactive")}
              ${hook.failureCount > 0
                ? html` · ${tf("webhooks.failures", {n: String(hook.failureCount)})}`
                : nothing}
              · ${this.formatDate(hook.lastDeliveryAt)}
            </div>
            <div class="flex flex-wrap gap-1 mt-2">
              ${(hook.events.length ? hook.events : ["*"]).map(
                (e) => html`<sonic-badge size="sm">${e}</sonic-badge>`,
              )}
            </div>
          </div>
          <div class="flex flex-wrap gap-2">
            <sonic-button size="sm" @click=${() => this.onToggle(hook)}>
              ${t("webhooks.toggle")}
            </sonic-button>
            <sonic-button size="sm" @click=${() => this.onPing(hook)}>
              ${t("webhooks.ping")}
            </sonic-button>
            <sonic-button size="sm" @click=${() => this.onDeliveries(hook)}>
              ${this.deliveriesFor === hook.id
                ? t("webhooks.hide_deliveries")
                : t("webhooks.show_deliveries")}
            </sonic-button>
            <sonic-button size="sm" type="danger" @click=${() => this.onDelete(hook)}>
              ${t("webhooks.delete")}
            </sonic-button>
          </div>
        </div>
        ${this.deliveriesFor === hook.id
          ? html`
              <div class="mt-3 text-sm">
                <div class="font-medium mb-1">${t("webhooks.deliveries")}</div>
                ${this.deliveries.length === 0
                  ? html`<p class="opacity-60">—</p>`
                  : this.deliveries.map(
                      (d) => html`
                        <div
                          class="py-1 border-t border-[color:var(--sc-border)] flex flex-wrap gap-2"
                        >
                          <sonic-badge
                            size="sm"
                            type=${d.status === "success" ? "success" : "danger"}
                          >
                            ${d.status}
                          </sonic-badge>
                          <span>${d.eventType}</span>
                          <span class="opacity-70">${d.httpStatus ?? "—"}</span>
                          <span class="opacity-70">${d.requestBytes} B</span>
                          <span class="opacity-70">${this.formatDate(d.createdAt)}</span>
                          ${d.error
                            ? html`<span class="text-[color:var(--sc-danger)]">${d.error}</span>`
                            : nothing}
                        </div>
                      `,
                    )}
              </div>
            `
          : nothing}
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-webhooks-page": ConfigWebhooksPage;
  }
}
