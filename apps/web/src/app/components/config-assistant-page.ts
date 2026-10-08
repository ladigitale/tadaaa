import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/alert";
import "@supersoniks/concorde/checkbox";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing, type PropertyValues} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {isAccountConnected, loadAccountSettings} from "../account-settings";
import {tf, tx} from "../i18n";
import {
  fetchAgentSettings,
  testAgentSettings,
  updateAgentSettings,
  type AgentProvider,
  type AgentSettingsPatch,
  type AgentSettingsView,
} from "../cloud-api/client";
import {read, set} from "../../utils/dataprovider";
import {agentSettingsFormKey, type AgentSettingsForm} from "../dp";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import "./account-required-cta";
import "./config-scope-header";
import "./page-shell";
import "./pop-select";

/** Libellé traduit si l'interface en a un (`assistant.provider.<id>`), sinon celui de l'API. */
function providerLabel(provider: AgentProvider): string {
  const key = `assistant.provider.${provider.id}`;
  const label = tx(key);
  return label === key ? provider.label : label;
}

type Feedback = {type: "success" | "error" | "info"; message: string} | null;

/**
 * Réglages « Assistant IA » : fournisseur (liste fixe), modèle, clé API, et en option
 * une URL de base libre (case à cocher). La clé saisie n'est jamais réaffichée : l'API
 * ne renvoie que `hasKey` et ses 4 derniers caractères.
 */
@customElement("config-assistant-page")
export class ConfigAssistantPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @subscribe(agentSettingsFormKey.provider)
  @state()
  provider = "";

  @state() private view: AgentSettingsView | null = null;
  @state() private customUrl = false;
  @state() private busy = false;
  @state() private feedback: Feedback = null;
  @state() private loadError = "";

  /** Fournisseur affiché au chargement : sert à détecter un changement de fournisseur. */
  private lastProvider = "";

  connectedCallback() {
    super.connectedCallback();
    set(agentSettingsFormKey.path, {provider: "", model: "", apiKey: "", customBaseUrl: ""});
    void this.load();
  }

  private async load() {
    if (!isAccountConnected(loadAccountSettings())) return;
    try {
      this.apply(await fetchAgentSettings());
    } catch (error) {
      this.loadError = error instanceof Error ? error.message : tx("dialogs.unknown_error");
    }
  }

  private apply(view: AgentSettingsView) {
    this.view = view;
    this.customUrl = view.customBaseUrlEnabled;
    const model = view.model || this.providerInfo(view.provider)?.defaultModel || "";
    this.lastProvider = view.provider;
    set(agentSettingsFormKey.path, {
      provider: view.provider,
      model,
      apiKey: "",
      customBaseUrl: view.customBaseUrl ?? "",
    });
  }

  private providerInfo(id = this.provider): AgentProvider | undefined {
    return this.view?.providers.find((p) => p.id === id);
  }

  private form(): AgentSettingsForm {
    return read(agentSettingsFormKey.path) as AgentSettingsForm;
  }

  /** Nouveau fournisseur : son modèle par défaut remplace un modèle suggéré par l'ancien. */
  protected willUpdate(changed: PropertyValues) {
    if (!changed.has("provider") || !this.view || !this.provider || this.provider === this.lastProvider) return;
    const previous = this.providerInfo(this.lastProvider);
    const next = this.providerInfo(this.provider);
    this.lastProvider = this.provider;
    const model = this.form().model;
    if (next && (!model || previous?.models.includes(model) || model === previous?.defaultModel)) {
      set(agentSettingsFormKey.model, next.defaultModel);
    }
    if (next && next.baseUrl === "") this.customUrl = true;
  }

  private async save(extra: AgentSettingsPatch = {}, done = tx("assistant.saved")) {
    const form = this.form();
    const patch: AgentSettingsPatch = {
      provider: form.provider,
      model: form.model.trim(),
      customBaseUrlEnabled: this.customUrl,
      customBaseUrl: form.customBaseUrl.trim() || null,
      ...extra,
    };
    if (!("apiKey" in extra) && form.apiKey.trim() !== "") patch.apiKey = form.apiKey.trim();
    this.busy = true;
    this.feedback = null;
    try {
      this.apply(await updateAgentSettings(patch));
      this.feedback = {type: "success", message: done};
    } catch (error) {
      this.feedback = {type: "error", message: error instanceof Error ? error.message : tx("dialogs.unknown_error")};
    } finally {
      this.busy = false;
    }
  }

  private onSave = () => void this.save();

  private onDeleteKey = () => void this.save({apiKey: ""}, tx("assistant.key_removed"));

  private onTest = async () => {
    this.busy = true;
    this.feedback = {type: "info", message: tx("assistant.testing")};
    try {
      const result = await testAgentSettings();
      this.feedback = {type: result.ok ? "success" : "error", message: result.message};
    } catch (error) {
      this.feedback = {type: "error", message: error instanceof Error ? error.message : tx("dialogs.unknown_error")};
    } finally {
      this.busy = false;
    }
  };

  private onToggleCustom = (e: Event) => {
    this.customUrl = (e.target as HTMLInputElement & {checked: unknown}).checked === true;
  };

  private renderStatus(view: AgentSettingsView) {
    const model = view.model || "?";
    const [type, message] = view.configured
      ? (["success", tf("assistant.status.ready_user", {model})] as const)
      : view.serverKeyAvailable
        ? (["info", tf("assistant.status.ready_server", {model: "Claude"})] as const)
        : (["warning", tx("assistant.status.none")] as const);
    return html`<sonic-alert status=${type} size="sm" data-assistant-status>${message}</sonic-alert>`;
  }

  private renderForm(view: AgentSettingsView) {
    const provider = this.providerInfo();
    const keyRequired = (provider?.keyRequired ?? true) && !this.customUrl;
    const keyLabel = keyRequired ? tx("assistant.api_key") : tx("assistant.api_key_optional");
    const keyPlaceholder = view.hasKey && view.keyHint
      ? tf("assistant.api_key_saved", {hint: view.keyHint})
      : tx("assistant.api_key_ph");
    const options = view.providers.map((p) => ({value: p.id, label: providerLabel(p)}));
    const customForced = provider?.baseUrl === "";

    return html`
      <div formDataProvider=${agentSettingsFormKey.path} class="space-y-5">
        <sonic-form-layout>
          <pop-select
            showLabel
            label=${tx("assistant.provider")}
            name="provider"
            mode="radio"
            variant="outline"
            minWidth="18rem"
            .value=${this.provider}
            .options=${options}
          ></pop-select>
          <sonic-input
            formDataProvider=${agentSettingsFormKey.path}
            name="model"
            label=${tx("assistant.model")}
            placeholder=${tx("assistant.model_ph")}
            autocomplete="off"
          ></sonic-input>
        </sonic-form-layout>

        ${provider?.models.length
          ? html`<div class="flex flex-wrap items-center gap-1 text-sm" data-model-suggestions>
              <span class="opacity-70">${tx("assistant.model_suggestions")}</span>
              ${provider.models.map(
                (m) => html`<sonic-button
                  size="xs"
                  variant="ghost"
                  @click=${() => set(agentSettingsFormKey.model, m)}
                  >${m}</sonic-button
                >`,
              )}
            </div>`
          : nothing}

        <div class="flex flex-wrap items-end gap-2">
          <sonic-input
            class="min-w-[18rem] flex-1"
            formDataProvider=${agentSettingsFormKey.path}
            name="apiKey"
            type="password"
            autocomplete="off"
            label=${keyLabel}
            placeholder=${keyPlaceholder}
          ></sonic-input>
          ${view.hasKey
            ? html`<sonic-button size="sm" variant="outline" type="danger" ?disabled=${this.busy} @click=${this.onDeleteKey}
                >${tx("assistant.delete_key")}</sonic-button
              >`
            : nothing}
        </div>

        <div class="space-y-2">
          <sonic-checkbox
            label=${tx("assistant.custom_url")}
            .checked=${this.customUrl ? true : null}
            ?disabled=${customForced}
            @change=${this.onToggleCustom}
            data-custom-url
          ></sonic-checkbox>
          ${this.customUrl
            ? html`
                <p class="text-sm opacity-70 m-0">${tx("assistant.custom_url_help")}</p>
                <sonic-input
                  formDataProvider=${agentSettingsFormKey.path}
                  name="customBaseUrl"
                  type="url"
                  label=${tx("assistant.custom_url_label")}
                  placeholder=${tx("assistant.custom_url_ph")}
                ></sonic-input>
                ${view.allowPrivateUrls
                  ? nothing
                  : html`<p class="text-xs opacity-70 m-0">${tx("assistant.private_blocked")}</p>`}
              `
            : provider?.baseUrl
              ? html`<p class="text-xs opacity-70 m-0">${tf("assistant.default_url", {url: provider.baseUrl})}</p>`
              : nothing}
        </div>

        <sonic-form-actions>
          <sonic-button type="primary" ?disabled=${this.busy} @click=${this.onSave} data-save
            >${tx("assistant.save")}</sonic-button
          >
          <sonic-button
            variant="outline"
            ?disabled=${this.busy || !(view.configured || view.serverKeyAvailable)}
            @click=${this.onTest}
            data-test
            >${tx("assistant.test")}</sonic-button
          >
        </sonic-form-actions>

        ${this.feedback
          ? html`<sonic-alert
              status=${this.feedback.type}
              size="sm"
              role=${this.feedback.type === "error" ? "alert" : "status"}
              data-feedback
              >${this.feedback.message}</sonic-alert
            >`
          : nothing}
      </div>
    `;
  }

  render() {
    const connected = isAccountConnected(loadAccountSettings());
    return html`
      <page-shell>
        <div class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4">
          <config-scope-header section="assistant"></config-scope-header>
        </div>
        <div class="space-y-6 pt-8">
          ${!connected
            ? html`<account-required-cta messageKey="connectivity.need_account"></account-required-cta>`
            : this.loadError
              ? html`<sonic-alert status="error" size="sm">${this.loadError}</sonic-alert>`
              : !this.view
                ? html`<p class="text-sm text-neutral-500">…</p>`
                : html`
                    <p class="text-sm text-neutral-500">${tx("assistant.help")}</p>
                    ${this.renderStatus(this.view)} ${this.renderForm(this.view)}
                  `}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-assistant-page": ConfigAssistantPage;
  }
}
