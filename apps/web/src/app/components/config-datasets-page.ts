import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  activateDataset,
  createDataset,
  deleteDataset,
  fetchDatasets,
  renameDataset,
} from "../api/client";
import type {DatasetInfo} from "../api/store";
import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {tf, tx} from "../i18n";
import {refreshConfigAppData} from "../utils/config-refresh";
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

  connectedCallback() {
    super.connectedCallback();
    void this.reloadDatasets();
  }

  private async reloadDatasets() {
    try {
      this.datasets = await fetchDatasets();
    } catch {
      this.datasets = [];
    }
  }

  private onCreateDataset = async (source: "empty" | "seed" | "current") => {
    if (this.busy) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const name = form.newDatasetName?.trim() || tx("datasets.new");
    this.busy = true;
    try {
      await createDataset({name, source});
      set(appConfigKey.path, {...form, newDatasetName: ""});
      await this.reloadDatasets();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onActivateDataset = async (event: CustomEvent<{dataset: DatasetInfo}>) => {
    const dataset = event.detail.dataset;
    if (this.busy || dataset.active) return;
    this.busy = true;
    try {
      await activateDataset(dataset.id);
      await refreshConfigAppData();
      await this.reloadDatasets();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onDeleteDataset = async (event: CustomEvent<{dataset: DatasetInfo}>) => {
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
      await this.reloadDatasets();
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
      await this.reloadDatasets();
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
          <config-scope-header section="datasets"></config-scope-header>
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

          <div class="flex flex-wrap items-end gap-2 pt-1">
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
