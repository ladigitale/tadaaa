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
import {getCloudApiRoot, loadAccountSettings} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  createEmbedKey,
  fetchCloudDatasets,
  fetchEmbedKeys,
  revokeEmbedKey,
  rotateEmbedKey,
  updateEmbedKey,
  type CloudDatasetInfo,
  type EmbedKeyInfo,
} from "../cloud-api/client";
import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {
  buildEmbedSnippet,
  EMBED_PRESETS,
  type EmbedPresetId,
} from "../utils/embed-presets";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./config-scope-header";
import "./page-shell";

@customElement("config-embeds-page")
export class ConfigEmbedsPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @subscribe(appConfigKey.embedName)
  @state()
  embedName = "";

  @subscribe(appConfigKey.embedOrigins)
  @state()
  embedOrigins = "";

  @state() private connected = false;
  @state() private loading = false;
  @state() private busy = false;
  @state() private embeds: EmbedKeyInfo[] = [];
  @state() private datasets: CloudDatasetInfo[] = [];
  @state() private datasetId = "";
  @state() private includeDone = false;
  @state() private includeDescription = false;
  @state() private plainToken: string | null = null;
  @state() private activePreset: EmbedPresetId = "list";
  @state() private copiedPreset: EmbedPresetId | null = null;
  @state() private docsOpen = true;

  connectedCallback() {
    super.connectedCallback();
    const form = read(appConfigKey.path) as AppConfigForm | undefined;
    set(appConfigKey.path, {
      newDatasetName: form?.newDatasetName ?? "",
      p2pReceiveCode: form?.p2pReceiveCode ?? "",
      accountEmail: form?.accountEmail ?? "",
      accountPassword: form?.accountPassword ?? "",
      accountWebsite: form?.accountWebsite ?? "",
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
      this.embeds = [];
      return;
    }
    this.loading = true;
    try {
      const [embeds, datasets] = await Promise.all([
        fetchEmbedKeys(),
        fetchCloudDatasets().catch(() => [] as CloudDatasetInfo[]),
      ]);
      this.embeds = embeds;
      this.datasets = datasets;
      if (!this.datasetId && datasets[0]) {
        this.datasetId = datasets[0].id;
      }
    } catch (error) {
      await showError(error);
    } finally {
      this.loading = false;
    }
  }

  private parseOrigins(raw: string): string[] {
    return raw
      .split(/[\n,]+/)
      .map((s) => s.trim())
      .filter(Boolean);
  }

  private scriptSrc(): string {
    return `${window.location.origin}/embed/embed.js`;
  }

  private apiBase(): string {
    return getCloudApiRoot(loadAccountSettings());
  }

  private snippetKey(): string {
    return this.plainToken ?? "YOUR_EMBED_KEY";
  }

  private snippet(preset: EmbedPresetId = this.activePreset): string {
    return buildEmbedSnippet({
      key: this.snippetKey(),
      apiBase: this.apiBase(),
      scriptSrc: this.scriptSrc(),
      preset,
    });
  }

  private async copyPreset(preset: EmbedPresetId) {
    this.activePreset = preset;
    try {
      await navigator.clipboard.writeText(this.snippet(preset));
      this.copiedPreset = preset;
      window.setTimeout(() => {
        if (this.copiedPreset === preset) this.copiedPreset = null;
      }, 2000);
    } catch (error) {
      await showError(error);
    }
  }

  private onCreate = async () => {
    const form = read(appConfigKey.path) as AppConfigForm;
    const name = (form?.embedName ?? this.embedName).trim();
    if (!this.datasetId) return;
    this.busy = true;
    try {
      const created = await createEmbedKey({
        name: name || "Embed",
        datasetId: this.datasetId,
        allowedOrigins: this.parseOrigins(form?.embedOrigins ?? this.embedOrigins),
        includeDone: this.includeDone,
        includeDescription: this.includeDescription,
      });
      this.plainToken = created.plainToken;
      this.activePreset = "list";
      set(appConfigKey.path, {...form, embedName: "", embedOrigins: form?.embedOrigins ?? ""});
      await this.reload();
    } catch (error) {
      await showError(error);
    } finally {
      this.busy = false;
    }
  };

  private onToggle = async (row: EmbedKeyInfo) => {
    try {
      await updateEmbedKey(row.id, {active: !row.active});
      await this.reload();
    } catch (error) {
      await showError(error);
    }
  };

  private onRotate = async (row: EmbedKeyInfo) => {
    const ok = await confirmDialog({
      title: tx("embeds.rotate_title"),
      message: tf("embeds.rotate_confirm", {name: row.name}),
      danger: true,
    });
    if (!ok) return;
    try {
      const rotated = await rotateEmbedKey(row.id);
      this.plainToken = rotated.plainToken;
      await this.reload();
    } catch (error) {
      await showError(error);
    }
  };

  private onRevoke = async (row: EmbedKeyInfo) => {
    const ok = await confirmDialog({
      title: tx("embeds.revoke_title"),
      message: tf("embeds.revoke_confirm", {name: row.name}),
      danger: true,
    });
    if (!ok) return;
    try {
      await revokeEmbedKey(row.id);
      if (this.plainToken?.startsWith(row.tokenPrefix)) {
        this.plainToken = null;
      }
      await this.reload();
    } catch (error) {
      await showError(error);
    }
  };

  private formatDate(value: string | null): string {
    if (!value) return "—";
    try {
      return new Date(value).toLocaleString(undefined, {
        dateStyle: "short",
        timeStyle: "short",
      });
    } catch {
      return value;
    }
  }

  private formatBytes(n: number): string {
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KiB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MiB`;
  }

  render() {
    return html`
      <page-shell>
        <config-scope-header section="embeds"></config-scope-header>
        ${!this.connected
          ? html`<account-required-cta
              messageKey="embeds.need_account"
            ></account-required-cta>`
          : html`
              <p class="text-sm opacity-80 mb-4">${t("embeds.intro")}</p>
              ${this.renderDocs()}
              ${this.plainToken
                ? html`
                    <sonic-alert type="warning" class="mb-4">
                      ${t("embeds.token_once")}
                      <code class="block mt-1 break-all">${this.plainToken}</code>
                    </sonic-alert>
                  `
                : nothing}
              ${this.renderPresets()}

              <section class="border-t border-[color:var(--sc-border)] pt-4 mb-6">
                <h2 class="text-base font-medium mb-3">${t("embeds.create_title")}</h2>
                <sonic-form-layout>
                  <label class="form-label">
                    <span>${t("embeds.name")}</span>
                    <sonic-input
                      name="embedName"
                      formDataProvider=${appConfigKey.path}
                      .value=${this.embedName}
                      placeholder=${tx("embeds.name_ph")}
                    ></sonic-input>
                  </label>
                  <label class="form-label">
                    <span>${t("embeds.dataset")}</span>
                    <select
                      class="w-full rounded border border-[color:var(--sc-border)] bg-transparent px-2 py-2"
                      .value=${this.datasetId}
                      @change=${(e: Event) => {
                        this.datasetId = (e.target as HTMLSelectElement).value;
                      }}
                    >
                      ${this.datasets.map(
                        (d) => html`<option value=${d.id}>${d.name}</option>`,
                      )}
                    </select>
                  </label>
                  <label class="form-label">
                    <span>${t("embeds.origins")}</span>
                    <sonic-input
                      name="embedOrigins"
                      formDataProvider=${appConfigKey.path}
                      .value=${this.embedOrigins}
                      placeholder=${tx("embeds.origins_ph")}
                    ></sonic-input>
                  </label>
                  <label class="form-label flex items-center gap-2">
                    <input
                      type="checkbox"
                      .checked=${this.includeDone}
                      @change=${(e: Event) => {
                        this.includeDone = (e.target as HTMLInputElement).checked;
                      }}
                    />
                    <span>${t("embeds.include_done")}</span>
                  </label>
                  <label class="form-label flex items-center gap-2">
                    <input
                      type="checkbox"
                      .checked=${this.includeDescription}
                      @change=${(e: Event) => {
                        this.includeDescription = (
                          e.target as HTMLInputElement
                        ).checked;
                      }}
                    />
                    <span>${t("embeds.include_description")}</span>
                  </label>
                  <sonic-form-actions>
                    <sonic-button
                      type="primary"
                      ?disabled=${this.busy || !this.datasetId}
                      @click=${this.onCreate}
                    >
                      ${t("embeds.create")}
                    </sonic-button>
                  </sonic-form-actions>
                </sonic-form-layout>
              </section>

              <section>
                <h2 class="text-base font-medium mb-3">${t("embeds.keys_title")}</h2>
                ${this.loading
                  ? html`<p class="opacity-60">…</p>`
                  : this.embeds.length === 0
                    ? html`<p class="opacity-60">${t("embeds.none")}</p>`
                    : this.embeds.map((row) => this.renderRow(row))}
              </section>
            `}
      </page-shell>
    `;
  }

  private renderDocs() {
    return html`
      <section class="border border-[color:var(--sc-border)] rounded-lg p-4 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <h2 class="text-base font-medium m-0">${t("embeds.docs.title")}</h2>
          <sonic-button size="sm" @click=${() => (this.docsOpen = !this.docsOpen)}>
            ${this.docsOpen ? t("embeds.docs.hide") : t("embeds.docs.show")}
          </sonic-button>
        </div>
        ${this.docsOpen
          ? html`
              <div class="text-sm space-y-3 opacity-90">
                <p>${t("embeds.docs.p1")}</p>
                <p>${t("embeds.docs.p2")}</p>
                <ul class="list-disc pl-5 space-y-1">
                  <li><code>view="list"</code> — ${tx("embeds.preset.list.help")}</li>
                  <li><code>view="agenda"</code> — ${tx("embeds.preset.agenda.help")}</li>
                  <li><code>view="kpi"</code> — ${tx("embeds.preset.kpi.help")}</li>
                  <li>
                    ${t("embeds.docs.children")}
                    <code>&lt;tadaaa-filter&gt;</code>,
                    <code>&lt;tadaaa-list&gt;</code>,
                    <code>&lt;tadaaa-agenda&gt;</code>,
                    <code>&lt;tadaaa-kpi&gt;</code>
                  </li>
                </ul>
                <p>${t("embeds.docs.attrs")}</p>
                <p class="font-mono text-xs overflow-auto rounded bg-[color:var(--sc-base-100,#f8fafc)] p-2">
                  key · api-base · theme · accent · font · radius · density · poll · view
                </p>
                <p>${t("embeds.docs.cors")}</p>
                <p>${t("embeds.docs.feed")}</p>
                <code class="block text-xs break-all"
                  >GET ${this.apiBase()}/api/public/embeds/YOUR_EMBED_KEY</code
                >
              </div>
            `
          : nothing}
      </section>
    `;
  }

  private renderPresets() {
    return html`
      <section class="border-t border-[color:var(--sc-border)] pt-4 mb-6">
        <h2 class="text-base font-medium mb-1">${t("embeds.presets.title")}</h2>
        <p class="text-sm opacity-80 mb-3">
          ${this.plainToken
            ? t("embeds.presets.with_key")
            : t("embeds.presets.placeholder")}
        </p>
        <div class="flex flex-wrap gap-2 mb-3">
          ${EMBED_PRESETS.map(
            (p) => html`
              <sonic-button
                size="sm"
                type=${this.activePreset === p.id ? "primary" : "default"}
                @click=${() => {
                  this.activePreset = p.id;
                }}
              >
                ${t(p.labelKey)}
              </sonic-button>
            `,
          )}
        </div>
        <p class="text-sm opacity-70 mb-2">
          ${t(
            EMBED_PRESETS.find((p) => p.id === this.activePreset)?.helpKey ??
              "embeds.preset.list.help",
          )}
        </p>
        <pre
          class="mb-3 text-xs overflow-auto rounded border border-[color:var(--sc-border)] p-3"
        >${this.snippet(this.activePreset)}</pre>
        <sonic-button
          type="primary"
          size="sm"
          @click=${() => this.copyPreset(this.activePreset)}
        >
          ${this.copiedPreset === this.activePreset
            ? t("embeds.presets.copied")
            : t("embeds.presets.copy")}
        </sonic-button>
      </section>
    `;
  }

  private renderRow(row: EmbedKeyInfo) {
    return html`
      <div class="border-t border-[color:var(--sc-border)] py-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="font-medium">${row.name}</div>
            <div class="text-sm opacity-70 mt-1">
              ${row.tokenPrefix}…
              · ${row.datasetName}
              ·
              ${row.active ? tx("embeds.active") : tx("embeds.inactive")}
              · ${tf("embeds.requests", {n: String(row.requestCount)})}
              · ${this.formatBytes(row.bytesServed)}
              · ${this.formatDate(row.lastUsedAt)}
              ${row.lastOrigin
                ? html` · <span class="break-all">${row.lastOrigin}</span>`
                : nothing}
            </div>
            <div class="flex flex-wrap gap-1 mt-2">
              ${(row.allowedOrigins.length ? row.allowedOrigins : ["—"]).map(
                (o) => html`<sonic-badge size="sm">${o}</sonic-badge>`,
              )}
            </div>
          </div>
          <div class="flex flex-wrap gap-2">
            <sonic-button size="sm" @click=${() => this.onToggle(row)}>
              ${t("embeds.toggle")}
            </sonic-button>
            <sonic-button size="sm" @click=${() => this.onRotate(row)}>
              ${t("embeds.rotate")}
            </sonic-button>
            <sonic-button size="sm" type="danger" @click=${() => this.onRevoke(row)}>
              ${t("embeds.revoke")}
            </sonic-button>
          </div>
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-embeds-page": ConfigEmbedsPage;
  }
}
