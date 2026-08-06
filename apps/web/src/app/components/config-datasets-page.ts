import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import {html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {get, post, subscribe, type ApiResult} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {deleteDataset, renameDataset} from "../api/client";
import {
  apiResultError,
  endpoints,
  readApiData,
  type ApiData,
} from "../api/endpoints";
import type {CreateDatasetInput, DatasetInfo} from "../api/store";
import {dp, read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {tf, tx} from "../i18n";
import {refreshConfigAppData} from "../utils/config-refresh";
import {isEnterSubmitEvent} from "../utils/form-enter-submit";
import {confirmDialog, promptTextDialog, showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./dataset-row";
import "./page-shell";

@customElement("config-datasets-page")
export class ConfigDatasetsPage extends LitElement {
  static styles = [tailwind];

  @subscribe(appConfigKey.newDatasetName)
  @state()
  newDatasetName = "";

  @state()
  private datasets: DatasetInfo[] = [];

  @state()
  private busy = false;

  @property({type: String})
  datasetId = "";

  @get(endpoints.datasets.list, {triggerKey: endpoints.keys.refresh.datasets})
  @state()
  datasetsPayload: ApiResult<ApiData<DatasetInfo[]>> | null = null;

  @post(endpoints.datasets.create, endpoints.keys.submit.datasetCreate)
  @state()
  createPayload: ApiResult<ApiData<DatasetInfo>> | null = null;

  @post(endpoints.datasets.activate, endpoints.keys.submit.datasetActivate, {
    skipEmptyPlaceholder: true,
    autoPostOnBodyMutation: false,
    triggerKey: endpoints.keys.refresh.datasetActivate,
  })
  @state()
  activatePayload: ApiResult<ApiData<DatasetInfo>> | null = null;

  private pendingCreate = false;
  private pendingActivate = false;

  connectedCallback() {
    super.connectedCallback();
    dp(endpoints.keys.refresh.datasets).invalidate();
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("datasetsPayload")) {
      this.datasets = readApiData(this.datasetsPayload) ?? [];
    }
    if (changed.has("createPayload") && this.pendingCreate) {
      void this.finishCreate();
    }
    if (changed.has("activatePayload") && this.pendingActivate) {
      void this.finishActivate();
    }
  }

  private onFormKeyDown = (event: KeyboardEvent) => {
    if (!isEnterSubmitEvent(event)) return;
    event.preventDefault();
    this.onCreateDataset("empty");
  };

  private onCreateDataset = (source: "empty" | "seed" | "current") => {
    if (this.busy) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const name = form.newDatasetName?.trim() || tx("datasets.new");
    this.busy = true;
    this.pendingCreate = true;
    const body: CreateDatasetInput = {name, source};
    set(endpoints.keys.submit.datasetCreate.path, body);
  };

  private async finishCreate() {
    this.pendingCreate = false;
    set(endpoints.keys.submit.datasetCreate.path, null);
    const created = readApiData(this.createPayload);
    if (!created) {
      await showError(apiResultError(this.createPayload), tx("dialogs.error"));
      this.busy = false;
      return;
    }
    const form = read(appConfigKey.path) as AppConfigForm;
    set(appConfigKey.path, {...form, newDatasetName: ""});
    dp(endpoints.keys.refresh.datasets).invalidate();
    this.busy = false;
  }

  private onActivateDataset = async (
    event: CustomEvent<{dataset: DatasetInfo}>,
  ) => {
    const dataset = event.detail.dataset;
    if (this.busy || dataset.active) return;
    this.busy = true;
    this.pendingActivate = true;
    this.datasetId = dataset.id;
    set(endpoints.keys.submit.datasetActivate.path, {});
    await this.updateComplete;
    dp(endpoints.keys.refresh.datasetActivate).invalidate();
  };

  private async finishActivate() {
    this.pendingActivate = false;
    set(endpoints.keys.submit.datasetActivate.path, null);
    const activated = readApiData(this.activatePayload);
    if (!activated) {
      await showError(
        apiResultError(this.activatePayload),
        tx("dialogs.error"),
      );
      this.busy = false;
      return;
    }
    await refreshConfigAppData();
    dp(endpoints.keys.refresh.datasets).invalidate();
    this.busy = false;
  }

  private onDeleteDataset = async (
    event: CustomEvent<{dataset: DatasetInfo}>,
  ) => {
    const dataset = event.detail.dataset;
    if (this.busy) return;
    const ok = await confirmDialog({
      title: tx("datasets.delete_title"),
      message: tf("datasets.delete_confirm", {name: dataset.name}),
      confirmLabel: tx("cloud.delete"),
      danger: true,
    });
    if (!ok) return;

    this.busy = true;
    try {
      await deleteDataset(dataset.id);
      await refreshConfigAppData();
      dp(endpoints.keys.refresh.datasets).invalidate();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onRenameDataset = async (
    event: CustomEvent<{dataset: DatasetInfo}>,
  ) => {
    const dataset = event.detail.dataset;
    if (this.busy) return;
    const nextName = await promptTextDialog({
      title: tx("datasets.rename_title"),
      label: tx("datasets.rename_label"),
      initialValue: dataset.name,
      confirmLabel: tx("datasets.rename_save"),
    });
    if (!nextName || nextName === dataset.name) return;
    this.busy = true;
    try {
      await renameDataset(dataset.id, nextName);
      dp(endpoints.keys.refresh.datasets).invalidate();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private datasetSeparator = () =>
    html`<div
      class="w-full bg-neutral-100"
      style="min-height: 2px"
      role="separator"
    ></div>`;

  render() {
    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="local"></config-scope-header>
        </div>

        <div class="mt-8 space-y-6" formDataProvider=${appConfigKey.path}>
          <p class="text-sm text-neutral-600">${t("datasets.intro")}</p>

          ${this.datasets.length === 0
            ? html`
                <p class="py-8 text-sm italic text-neutral-500">
                  ${t("datasets.empty")}
                </p>
              `
            : html`
                <ul class="m-0 list-none p-0">
                  ${this.datasets.map(
                    (dataset, index) => html`
                      <li>
                        ${index > 0 ? this.datasetSeparator() : nothing}
                        <dataset-row
                          .datasetInfo=${dataset}
                          ?disabled=${this.busy}
                          ?canDelete=${this.datasets.length > 1}
                          ?canRename=${true}
                          @dataset-activate=${this.onActivateDataset}
                          @dataset-delete=${this.onDeleteDataset}
                          @dataset-rename=${this.onRenameDataset}
                        ></dataset-row>
                      </li>
                    `,
                  )}
                </ul>
              `}

          <div
            class="flex flex-wrap items-end gap-2 pt-1"
            @keydown=${this.onFormKeyDown}
          >
            <sonic-input
              name="newDatasetName"
              label=${tx("datasets.new")}
              size="sm"
              placeholder=${tx("datasets.new_ph")}
              class="min-w-[12rem] flex-1"
            ></sonic-input>
            <sonic-button
              size="sm"
              variant="outline"
              ?disabled=${this.busy}
              @click=${() => this.onCreateDataset("empty")}
            >
              ${t("datasets.create_empty")}
            </sonic-button>
            <sonic-button
              size="sm"
              variant="outline"
              ?disabled=${this.busy}
              @click=${() => this.onCreateDataset("seed")}
            >
              ${t("datasets.create_seed")}
            </sonic-button>
            <sonic-button
              size="sm"
              type="primary"
              ?disabled=${this.busy}
              @click=${() => this.onCreateDataset("current")}
            >
              ${t("datasets.create_clone")}
            </sonic-button>
          </div>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-datasets-page": ConfigDatasetsPage;
  }
}
