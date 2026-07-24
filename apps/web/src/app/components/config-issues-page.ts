import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/form-actions";
import "@supersoniks/concorde/tooltip";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  DEFAULT_LINK_DETECTOR,
  applyCloudLinkDetectors,
  createLinkDetector,
  loadAppSettings,
  saveAppSettings,
  setAppSettingsPreview,
  validateLinkDetector,
  buildDetectorUrl,
  type LinkDetector,
} from "../settings";
import {
  isAccountConnected,
  loadAccountSettings,
} from "../account-settings";
import {
  fetchLinkDetectors,
  replaceLinkDetectors,
} from "../cloud-api/client";
import {tx, tf} from "../i18n";
import {showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import {rmLinksTemplate, richTextTemplate} from "./rm-link-text";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import "./config-scope-header";
import "./page-shell";

@customElement("config-issues-page")
export class ConfigIssuesPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @state()
  private detectors: LinkDetector[] = loadAppSettings().linkDetectors;

  @state()
  private busy = false;

  @state()
  private settingsSaved = false;

  @state()
  private cloudSynced = false;

  connectedCallback() {
    super.connectedCallback();
    this.detectors = loadAppSettings().linkDetectors.map((d) => ({...d}));
    setAppSettingsPreview({
      ...loadAppSettings(),
      linkDetectors: this.detectors,
    });
    void this.pullFromCloud();
  }

  disconnectedCallback() {
    setAppSettingsPreview(null);
    super.disconnectedCallback();
  }

  private async pullFromCloud() {
    if (!isAccountConnected()) return;
    try {
      const remote = await fetchLinkDetectors();
      this.detectors = remote.map((d) => ({...d}));
      applyCloudLinkDetectors(remote);
      this.cloudSynced = true;
      this.refreshPreview();
    } catch {
      // Keep local detectors if API unreachable.
    }
  }

  private refreshPreview() {
    setAppSettingsPreview({
      ...loadAppSettings(),
      linkDetectors: this.detectors,
    });
    this.requestUpdate();
  }

  private updateDetector(id: string, patch: Partial<LinkDetector>) {
    this.detectors = this.detectors.map((detector) =>
      detector.id === id ? {...detector, ...patch} : detector,
    );
    this.refreshPreview();
  }

  private onAdd = () => {
    this.detectors = [
      ...this.detectors,
      createLinkDetector({
        name: tx("issues.detector_new_name"),
        pattern: DEFAULT_LINK_DETECTOR.pattern,
        urlTemplate: DEFAULT_LINK_DETECTOR.urlTemplate,
      }),
    ];
    this.refreshPreview();
  };

  private onRemove = (id: string) => {
    this.detectors = this.detectors.filter((detector) => detector.id !== id);
    this.refreshPreview();
  };

  private onSave = async () => {
    for (const detector of this.detectors) {
      const error = validateLinkDetector(detector);
      if (error) {
        await showError(new Error(error), tx("dialogs.error"));
        return;
      }
    }

    this.busy = true;
    try {
      let saved = this.detectors.map((d) =>
        createLinkDetector({
          id: d.id,
          name: d.name,
          pattern: d.pattern,
          urlTemplate: d.urlTemplate,
        }),
      );

      if (isAccountConnected()) {
        saved = await replaceLinkDetectors(saved);
        this.cloudSynced = true;
      }

      saveAppSettings({
        ...loadAppSettings(),
        linkDetectors: saved,
      });
      this.detectors = saved.map((d) => ({...d}));
      this.refreshPreview();
      this.settingsSaved = true;
      window.setTimeout(() => {
        this.settingsSaved = false;
      }, 2000);
    } catch (error) {
      await showError(error, tx("dialogs.error"));
    } finally {
      this.busy = false;
    }
  };

  private onReset = () => {
    this.detectors = [{...DEFAULT_LINK_DETECTOR}];
    this.refreshPreview();
  };

  private previewSample(detector: LinkDetector): string {
    try {
      return buildDetectorUrl(detector, "12345");
    } catch {
      return "";
    }
  }

  private renderDetector(detector: LinkDetector, index: number) {
    return html`
      <div
        class="space-y-3 rounded-lg border border-neutral-200 p-3 sm:p-4"
      >
        <div class="flex items-center justify-between gap-2">
          <p class="text-sm font-medium text-neutral-800">
            ${tf("issues.detector_n", {n: index + 1})}
          </p>
          <sonic-tooltip
            label=${tx("issues.detector_remove")}
            placement="bottom"
          >
            <sonic-button
              variant="ghost"
              size="sm"
              shape="circle"
              ?disabled=${this.busy}
              data-aria-label=${tx("issues.detector_remove")}
              @click=${() => this.onRemove(detector.id)}
            >
              <sonic-icon
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="trash"
                size="sm"
              ></sonic-icon>
            </sonic-button>
          </sonic-tooltip>
        </div>

        <div class="space-y-3">
          <div class="form-field">
            <label class="form-label" for=${`ld-name-${detector.id}`}
              >${tx("issues.name_label")}</label
            >
            <input
              id=${`ld-name-${detector.id}`}
              class="form-field-control w-full rounded-md border border-neutral-300 bg-transparent px-3 py-2 text-sm"
              .value=${detector.name}
              @input=${(event: Event) => {
                const value = (event.target as HTMLInputElement).value;
                this.updateDetector(detector.id, {name: value});
              }}
            />
          </div>
          <div class="form-field">
            <label class="form-label" for=${`ld-pattern-${detector.id}`}
              >${tx("issues.pattern_label")}</label
            >
            <input
              id=${`ld-pattern-${detector.id}`}
              class="form-field-control w-full rounded-md border border-neutral-300 bg-transparent px-3 py-2 font-mono text-sm"
              placeholder=${DEFAULT_LINK_DETECTOR.pattern}
              .value=${detector.pattern}
              @input=${(event: Event) => {
                const value = (event.target as HTMLInputElement).value;
                this.updateDetector(detector.id, {pattern: value});
              }}
            />
          </div>
          <div class="form-field">
            <label class="form-label" for=${`ld-url-${detector.id}`}
              >${tx("issues.url_label")}</label
            >
            <input
              id=${`ld-url-${detector.id}`}
              class="form-field-control w-full rounded-md border border-neutral-300 bg-transparent px-3 py-2 font-mono text-sm"
              placeholder=${DEFAULT_LINK_DETECTOR.urlTemplate}
              .value=${detector.urlTemplate}
              @input=${(event: Event) => {
                const value = (event.target as HTMLInputElement).value;
                this.updateDetector(detector.id, {urlTemplate: value});
              }}
            />
          </div>
          <p class="text-xs text-neutral-500">
            ${t("issues.url_preview")}
            <a
              class="text-blue-600 underline"
              href=${this.previewSample(detector)}
              target="_blank"
              rel="noopener noreferrer"
              >${this.previewSample(detector)}</a
            >
          </p>
        </div>
      </div>
    `;
  }

  render() {
    const account = loadAccountSettings();
    const connected = isAccountConnected(account);

    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="issues"></config-scope-header>
        </div>

        <div class="mt-8 space-y-6">
          <p class="text-sm text-neutral-600">${t("issues.intro")}</p>
          ${connected
            ? html`
                <p class="text-xs text-neutral-500">
                  ${this.cloudSynced
                    ? t("issues.cloud_synced")
                    : t("issues.cloud_hint")}
                </p>
              `
            : html`
                <p class="text-xs text-neutral-500">${t("issues.local_hint")}</p>
              `}

          <div class="space-y-4">
            ${this.detectors.length === 0
              ? html`<p class="text-sm text-neutral-500">${t("issues.empty")}</p>`
              : this.detectors.map((detector, index) =>
                  this.renderDetector(detector, index),
                )}
          </div>

          <sonic-button
            variant="outline"
            ?disabled=${this.busy}
            @click=${this.onAdd}
          >
            <sonic-icon
              slot="prefix"
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="plus"
              size="sm"
            ></sonic-icon>
            ${t("issues.detector_add")}
          </sonic-button>

          <div class="space-y-2 rounded-lg border border-dashed border-neutral-300 p-3">
            <label class="form-label">${t("issues.preview")}</label>
            <p class="text-sm">${rmLinksTemplate(tx("tasks.form.name_ph"))}</p>
            <div class="rich-preview text-sm text-neutral-600">
              ${richTextTemplate(tx("issues.markdown_preview_sample"))}
            </div>
          </div>

          <p class="text-xs text-neutral-500">${t("issues.markdown_hint")}</p>

          <sonic-form-actions justify="flex-end">
            <sonic-button
              variant="outline"
              ?disabled=${this.busy}
              @click=${this.onReset}
            >
              ${t("common.reset")}
            </sonic-button>
            <sonic-button
              type="primary"
              ?disabled=${this.busy}
              @click=${this.onSave}
            >
              ${t("common.save")}
            </sonic-button>
          </sonic-form-actions>
          ${this.settingsSaved
            ? html`<p class="text-sm text-green-700">${t("issues.saved")}</p>`
            : nothing}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-issues-page": ConfigIssuesPage;
  }
}
