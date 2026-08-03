import "@supersoniks/concorde/button";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/alert";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {isAccountConnected} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  connectGoogleCalendar,
  createGoogleCalendarBinding,
  deleteGoogleCalendarBinding,
  disconnectGoogleCalendar,
  fetchCloudDatasets,
  fetchGoogleCalendarStatus,
  fetchGoogleCalendars,
  syncGoogleCalendarNow,
  type CloudDatasetInfo,
  type GoogleCalendarBindingInfo,
  type GoogleCalendarListItem,
  type GoogleCalendarStatus,
} from "../cloud-api/client";
import {pullDatasetSync} from "../sync/cloud-client";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./config-scope-header";
import "./page-shell";

type TagOption = {id: string; name: string};

@customElement("config-calendar-page")
export class ConfigCalendarPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @state() private connected = false;
  @state() private loading = false;
  @state() private busy = false;
  @state() private status: GoogleCalendarStatus | null = null;
  @state() private datasets: CloudDatasetInfo[] = [];
  @state() private calendars: GoogleCalendarListItem[] = [];
  @state() private tags: TagOption[] = [];
  @state() private datasetId = "";
  @state() private calendarId = "";
  @state() private selectedTagIds: string[] = [];
  @state() private isDefault = false;
  @state() private exportEnabled = true;
  @state() private importEnabled = true;
  @state() private oauthFlash: string | null = null;

  connectedCallback() {
    super.connectedCallback();
    const params = new URLSearchParams(window.location.search);
    const google = params.get("google");
    if (google === "connected") {
      this.oauthFlash = tx("calendar.flash.connected");
    } else if (google === "error") {
      this.oauthFlash = tf("calendar.flash.error", {
        error: params.get("error") ?? "unknown",
      });
    }
    if (google) {
      const url = new URL(window.location.href);
      url.searchParams.delete("google");
      url.searchParams.delete("error");
      window.history.replaceState({}, "", url.pathname + url.search);
    }
    void this.reload();
  }

  private async reload() {
    this.connected = isAccountConnected();
    if (!this.connected) {
      this.status = null;
      return;
    }
    this.loading = true;
    try {
      const [status, datasets] = await Promise.all([
        fetchGoogleCalendarStatus(),
        fetchCloudDatasets().catch(() => [] as CloudDatasetInfo[]),
      ]);
      this.status = status;
      this.datasets = datasets;
      if (status.connected) {
        this.calendars = await fetchGoogleCalendars().catch(
          () => [] as GoogleCalendarListItem[],
        );
      } else {
        this.calendars = [];
      }
      if (!this.datasetId && datasets[0]) {
        this.datasetId = datasets[0].id;
        await this.loadTagsForDataset(datasets[0]);
      }
    } catch (error) {
      await showError(error);
    } finally {
      this.loading = false;
    }
  }

  private async loadTagsForDataset(dataset: CloudDatasetInfo) {
    try {
      const pull = await pullDatasetSync(dataset.baseId, null);
      this.tags = pull.tags
        .filter((tag) => !tag.deletedAt)
        .map((tag) => ({id: tag.id, name: tag.name}));
    } catch {
      this.tags = [];
    }
  }

  private async onDatasetChange(ev: Event) {
    const value = (ev.target as HTMLSelectElement).value;
    this.datasetId = value;
    this.selectedTagIds = [];
    const dataset = this.datasets.find((d) => d.id === value);
    if (dataset) {
      await this.loadTagsForDataset(dataset);
    } else {
      this.tags = [];
    }
  }

  private toggleTag(id: string) {
    if (this.selectedTagIds.includes(id)) {
      this.selectedTagIds = this.selectedTagIds.filter((t) => t !== id);
    } else {
      this.selectedTagIds = [...this.selectedTagIds, id];
    }
  }

  private async onConnect() {
    this.busy = true;
    try {
      const url = await connectGoogleCalendar();
      window.location.href = url;
    } catch (error) {
      await showError(error);
      this.busy = false;
    }
  }

  private async onDisconnect() {
    const ok = await confirmDialog({
      title: tx("calendar.disconnect.title"),
      message: tx("calendar.disconnect.body"),
      confirmLabel: tx("calendar.disconnect.confirm"),
    });
    if (!ok) return;
    this.busy = true;
    try {
      await disconnectGoogleCalendar();
      await this.reload();
    } catch (error) {
      await showError(error);
    } finally {
      this.busy = false;
    }
  }

  private async onAddBinding() {
    if (!this.datasetId || !this.calendarId) {
      await showError(new Error(tx("calendar.binding.missing")));
      return;
    }
    if (!this.isDefault && this.selectedTagIds.length === 0) {
      await showError(new Error(tx("calendar.binding.need_tags_or_default")));
      return;
    }
    const cal = this.calendars.find((c) => c.id === this.calendarId);
    this.busy = true;
    try {
      await createGoogleCalendarBinding({
        datasetId: this.datasetId,
        googleCalendarId: this.calendarId,
        googleCalendarSummary: cal?.summary ?? this.calendarId,
        tagIds: this.selectedTagIds,
        isDefault: this.isDefault,
        exportEnabled: this.exportEnabled,
        importEnabled: this.importEnabled,
      });
      this.selectedTagIds = [];
      this.isDefault = false;
      await this.reload();
    } catch (error) {
      await showError(error);
    } finally {
      this.busy = false;
    }
  }

  private async onDeleteBinding(binding: GoogleCalendarBindingInfo) {
    const ok = await confirmDialog({
      title: tx("calendar.binding.delete.title"),
      message: tf("calendar.binding.delete.body", {
        name: binding.googleCalendarSummary,
      }),
      confirmLabel: tx("calendar.binding.delete.confirm"),
    });
    if (!ok) return;
    this.busy = true;
    try {
      await deleteGoogleCalendarBinding(binding.id);
      await this.reload();
    } catch (error) {
      await showError(error);
    } finally {
      this.busy = false;
    }
  }

  private async onSyncNow(bindingId?: string) {
    this.busy = true;
    try {
      const result = await syncGoogleCalendarNow(bindingId);
      this.oauthFlash = tf("calendar.sync.done", {n: String(result.changed)});
      await this.reload();
    } catch (error) {
      await showError(error);
    } finally {
      this.busy = false;
    }
  }

  private tagNames(binding: GoogleCalendarBindingInfo): string {
    if (binding.tagIds.length === 0) {
      return binding.isDefault ? tx("calendar.binding.default_only") : "—";
    }
    return binding.tagIds
      .map((id) => this.tags.find((t) => t.id === id)?.name ?? id)
      .join(", ");
  }

  render() {
    return html`
      <page-shell>
        <config-scope-header section="calendar"></config-scope-header>
        ${!this.connected
          ? html`<account-required-cta
              messageKey="connectivity.need_account"
            ></account-required-cta>`
          : this.renderBody()}
      </page-shell>
    `;
  }

  private renderBody() {
    const status = this.status;
    return html`
      <div class="flex flex-col gap-6 max-w-2xl">
        ${this.oauthFlash
          ? html`<sonic-alert type="info" class="mb-2"
              >${this.oauthFlash}</sonic-alert
            >`
          : nothing}
        <p class="text-sm opacity-80">${t("calendar.intro")}</p>

        ${this.loading
          ? html`<p class="text-sm opacity-60">${t("common.loading")}</p>`
          : nothing}

        ${status && !status.configured
          ? html`<sonic-alert type="warning"
              >${t("calendar.not_configured")}</sonic-alert
            >`
          : nothing}

        ${status?.connected
          ? html`
              <div
                class="flex flex-wrap items-center gap-3 p-4 rounded-lg border border-[color-mix(in_oklab,var(--sc-base-content)_12%,transparent)]"
              >
                <div class="flex-1 min-w-0">
                  <div class="font-medium truncate">
                    ${status.connection?.email}
                  </div>
                  <div class="text-xs opacity-60">
                    ${status.connection?.status}
                  </div>
                </div>
                <sonic-button
                  size="sm"
                  type="button"
                  ?disabled=${this.busy}
                  @click=${() => void this.onSyncNow()}
                  >${t("calendar.sync_now")}</sonic-button
                >
                <sonic-button
                  size="sm"
                  type="button"
                  variant="outline"
                  ?disabled=${this.busy}
                  @click=${() => void this.onDisconnect()}
                  >${t("calendar.disconnect")}</sonic-button
                >
              </div>
            `
          : html`
              <sonic-button
                type="button"
                ?disabled=${this.busy || !status?.configured}
                @click=${() => void this.onConnect()}
                >${t("calendar.connect")}</sonic-button
              >
            `}

        ${status?.connected ? this.renderBindings(status.bindings) : nothing}
      </div>
    `;
  }

  private renderBindings(bindings: GoogleCalendarBindingInfo[]) {
    return html`
      <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">${t("calendar.bindings.title")}</h2>
        <p class="text-sm opacity-70">${t("calendar.bindings.help")}</p>

        ${bindings.length === 0
          ? html`<p class="text-sm opacity-60">${t("calendar.bindings.empty")}</p>`
          : bindings.map(
              (b) => html`
                <div
                  class="flex flex-col gap-2 p-3 rounded-lg border border-[color-mix(in_oklab,var(--sc-base-content)_12%,transparent)]"
                >
                  <div class="flex flex-wrap items-start gap-2">
                    <div class="flex-1 min-w-0">
                      <div class="font-medium truncate">
                        ${b.googleCalendarSummary}
                      </div>
                      <div class="text-xs opacity-60 truncate">
                        ${b.datasetName} · ${this.tagNames(b)}
                        ${b.isDefault
                          ? html` ·
                            <sonic-badge size="xs"
                              >${t("calendar.binding.default")}</sonic-badge
                            >`
                          : nothing}
                      </div>
                    </div>
                    <sonic-button
                      size="xs"
                      type="button"
                      ?disabled=${this.busy}
                      @click=${() => void this.onSyncNow(b.id)}
                      >${t("calendar.sync_now")}</sonic-button
                    >
                    <sonic-button
                      size="xs"
                      type="button"
                      variant="outline"
                      ?disabled=${this.busy}
                      @click=${() => void this.onDeleteBinding(b)}
                      >${t("calendar.binding.delete.confirm")}</sonic-button
                    >
                  </div>
                  <div class="text-xs opacity-50">
                    ${b.exportEnabled
                      ? t("calendar.flag.export")
                      : t("calendar.flag.export_off")}
                    ·
                    ${b.importEnabled
                      ? t("calendar.flag.import")
                      : t("calendar.flag.import_off")}
                  </div>
                </div>
              `,
            )}

        <div
          class="flex flex-col gap-3 p-4 rounded-lg border border-dashed border-[color-mix(in_oklab,var(--sc-base-content)_20%,transparent)]"
        >
          <h3 class="font-medium">${t("calendar.binding.add")}</h3>
          <label class="form-label">
            <span>${t("calendar.field.dataset")}</span>
            <select
              class="w-full mt-1 rounded border px-2 py-2 bg-transparent"
              .value=${this.datasetId}
              @change=${(e: Event) => void this.onDatasetChange(e)}
            >
              ${this.datasets.map(
                (d) =>
                  html`<option value=${d.id} ?selected=${d.id === this.datasetId}>
                    ${d.name}
                  </option>`,
              )}
            </select>
          </label>
          <label class="form-label">
            <span>${t("calendar.field.calendar")}</span>
            <select
              class="w-full mt-1 rounded border px-2 py-2 bg-transparent"
              .value=${this.calendarId}
              @change=${(e: Event) => {
                this.calendarId = (e.target as HTMLSelectElement).value;
              }}
            >
              <option value="">${t("calendar.field.calendar_ph")}</option>
              ${this.calendars.map(
                (c) =>
                  html`<option value=${c.id} ?selected=${c.id === this.calendarId}>
                    ${c.summary}${c.primary ? " ★" : ""}
                  </option>`,
              )}
            </select>
          </label>
          <div class="form-label">
            <span>${t("calendar.field.tags")}</span>
            <p class="text-xs opacity-60 mb-2">${t("calendar.field.tags_help")}</p>
            <div class="flex flex-wrap gap-2">
              ${this.tags.length === 0
                ? html`<span class="text-xs opacity-50"
                    >${t("calendar.field.tags_empty")}</span
                  >`
                : this.tags.map(
                    (tag) => html`
                      <sonic-button
                        size="xs"
                        type="button"
                        variant=${this.selectedTagIds.includes(tag.id)
                          ? "default"
                          : "outline"}
                        @click=${() => this.toggleTag(tag.id)}
                        >${tag.name}</sonic-button
                      >
                    `,
                  )}
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              .checked=${this.isDefault}
              @change=${(e: Event) => {
                this.isDefault = (e.target as HTMLInputElement).checked;
              }}
            />
            ${t("calendar.field.default")}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              .checked=${this.exportEnabled}
              @change=${(e: Event) => {
                this.exportEnabled = (e.target as HTMLInputElement).checked;
              }}
            />
            ${t("calendar.field.export")}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              .checked=${this.importEnabled}
              @change=${(e: Event) => {
                this.importEnabled = (e.target as HTMLInputElement).checked;
              }}
            />
            ${t("calendar.field.import")}
          </label>
          <sonic-button
            type="button"
            ?disabled=${this.busy}
            @click=${() => void this.onAddBinding()}
            >${t("calendar.binding.add_submit")}</sonic-button
          >
        </div>
      </section>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-calendar-page": ConfigCalendarPage;
  }
}
