import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {
  DEFAULT_APP_SETTINGS,
  loadAppSettings,
  saveAppSettings,
  setAppSettingsPreview,
  validateIssuePattern,
  buildIssueUrl,
} from "../settings";
import {loadAccountSettings} from "../account-settings";
import {showError} from "../utils/modal-dialog";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import {rmLinksTemplate} from "./rm-link-text";
import "./config-scope-header";
import "./page-shell";

@customElement("config-issues-page")
export class ConfigIssuesPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @subscribe(appConfigKey.issueUrlTemplate)
  @state()
  issueUrlTemplate = DEFAULT_APP_SETTINGS.issueUrlTemplate;

  @subscribe(appConfigKey.issuePattern)
  @state()
  issuePattern = DEFAULT_APP_SETTINGS.issuePattern;

  @state()
  private busy = false;

  @state()
  private settingsSaved = false;

  connectedCallback() {
    super.connectedCallback();
    const settings = loadAppSettings();
    const account = loadAccountSettings();
    const form = read(appConfigKey.path) as AppConfigForm | undefined;
    set(appConfigKey.path, {
      issueUrlTemplate: settings.issueUrlTemplate,
      issuePattern: settings.issuePattern,
      newDatasetName: form?.newDatasetName ?? "",
      p2pReceiveCode: form?.p2pReceiveCode ?? "",
      accountEmail: account.user?.email ?? form?.accountEmail ?? "",
      accountPassword: "",
      accountApiBaseUrl: account.apiBaseUrl,
      newCloudDatasetName: form?.newCloudDatasetName ?? "",
      newAccessTokenName: form?.newAccessTokenName ?? "",
    });
    setAppSettingsPreview(settings);
  }

  disconnectedCallback() {
    setAppSettingsPreview(null);
    super.disconnectedCallback();
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("issueUrlTemplate") || changed.has("issuePattern")) {
      setAppSettingsPreview({
        issueUrlTemplate:
          this.issueUrlTemplate || DEFAULT_APP_SETTINGS.issueUrlTemplate,
        issuePattern: this.issuePattern || DEFAULT_APP_SETTINGS.issuePattern,
      });
    }
  }

  private get previewUrl(): string {
    try {
      return buildIssueUrl("12345", {
        issueUrlTemplate:
          this.issueUrlTemplate || DEFAULT_APP_SETTINGS.issueUrlTemplate,
        issuePattern: this.issuePattern || DEFAULT_APP_SETTINGS.issuePattern,
      });
    } catch {
      return "";
    }
  }

  private onSave = async () => {
    const form = read(appConfigKey.path) as AppConfigForm;
    const patternError = validateIssuePattern(form.issuePattern);
    if (patternError) {
      await showError(new Error(patternError), "Regexp invalide");
      return;
    }
    if (!form.issueUrlTemplate?.includes("{id}")) {
      await showError(
        new Error("L’URL doit contenir le jeton {id}."),
        "URL invalide",
      );
      return;
    }
    saveAppSettings({
      issueUrlTemplate: form.issueUrlTemplate,
      issuePattern: form.issuePattern,
    });
    this.settingsSaved = true;
    window.setTimeout(() => {
      this.settingsSaved = false;
    }, 2000);
  };

  private onReset = () => {
    set(appConfigKey.path, {
      ...(read(appConfigKey.path) as AppConfigForm),
      issueUrlTemplate: DEFAULT_APP_SETTINGS.issueUrlTemplate,
      issuePattern: DEFAULT_APP_SETTINGS.issuePattern,
    });
  };

  render() {
    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="issues"></config-scope-header>
        </div>

        <div class="mt-6 space-y-3" formDataProvider=${appConfigKey.path}>
          <p class="text-sm text-neutral-600">
            Défaut :
            <code class="text-xs">${DEFAULT_APP_SETTINGS.issueUrlTemplate}</code>
            +
            <code class="text-xs">${DEFAULT_APP_SETTINGS.issuePattern}</code>
          </p>

          <sonic-form-layout>
            <sonic-input
              name="issueUrlTemplate"
              label="URL de base (avec {id})"
              placeholder=${DEFAULT_APP_SETTINGS.issueUrlTemplate}
            ></sonic-input>
            <sonic-input
              name="issuePattern"
              label="Regexp du jeton (1er groupe = id)"
              placeholder=${DEFAULT_APP_SETTINGS.issuePattern}
            ></sonic-input>

            <div class="form-field">
              <label class="form-label">Aperçu</label>
              <div class="form-field-control space-y-1 text-sm">
                <p>${rmLinksTemplate("Suivre RM-12345 demain")}</p>
                <p class="text-neutral-500">
                  URL :
                  <a
                    class="text-blue-600 underline"
                    href=${this.previewUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    >${this.previewUrl}</a
                  >
                </p>
              </div>
            </div>

            <sonic-form-actions justify="flex-end">
              <sonic-button
                variant="outline"
                ?disabled=${this.busy}
                @click=${this.onReset}
              >
                Réinitialiser
              </sonic-button>
              <sonic-button
                type="primary"
                ?disabled=${this.busy}
                @click=${this.onSave}
              >
                Enregistrer
              </sonic-button>
            </sonic-form-actions>
            ${this.settingsSaved
              ? html`<p class="text-sm text-green-700">Réglages enregistrés.</p>`
              : nothing}
          </sonic-form-layout>
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
