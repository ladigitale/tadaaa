import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  exportTodosSnapshot,
  importTodosSnapshot,
} from "../api/client";
import {
  exportFileName,
  parseDataPackage,
  TADA_DATA_VERSION,
} from "../api/data-package";
import {tx} from "../i18n";
import {refreshConfigAppData} from "../utils/config-refresh";
import {confirmDialog, showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import "./config-scope-header";
import "./page-shell";

@customElement("config-data-page")
export class ConfigDataPage extends LitElement {
  static styles = [tailwind];

  @state()
  private busy = false;

  private onExport = async () => {
    if (this.busy) return;
    this.busy = true;
    try {
      const pkg = await exportTodosSnapshot();
      const blob = new Blob([JSON.stringify(pkg, null, 2)], {
        type: "application/json",
      });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = exportFileName(pkg);
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onImportClick = () => {
    const input = this.renderRoot.querySelector(
      "#import-file",
    ) as HTMLInputElement | null;
    input?.click();
  };

  private onImportFile = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = "";
    if (!file || this.busy) return;

    let parsed: unknown;
    try {
      parsed = JSON.parse(await file.text());
    } catch {
      await showError(
        new Error(tx("dialogs.unknown_error")),
        tx("dialogs.error"),
      );
      return;
    }

    let pkg;
    try {
      pkg = parseDataPackage(parsed);
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      return;
    }

    const ok = await confirmDialog({
      title: tx("data.import_title"),
      message: [
        `« ${pkg.name} »`,
        `id ${pkg.id}`,
        `format tada v${pkg.version} (app v${TADA_DATA_VERSION})`,
        "",
        tx("data.import_confirm"),
      ].join("\n"),
      confirmLabel: tx("data.import"),
      danger: true,
    });
    if (!ok) return;

    this.busy = true;
    try {
      await importTodosSnapshot(parsed);
      await refreshConfigAppData();
    } catch (error) {
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  render() {
    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="data"></config-scope-header>
        </div>

        <div class="mt-8 space-y-3">
          <p class="text-sm text-neutral-600">${t("data.intro")}</p>
          <div class="flex flex-wrap gap-2">
            <sonic-button
              type="primary"
              size="sm"
              ?disabled=${this.busy}
              @click=${this.onExport}
            >
              <sonic-icon
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="page"
                size="sm"
              ></sonic-icon>
              ${t("data.export")}
            </sonic-button>
            <sonic-button
              variant="outline"
              size="sm"
              ?disabled=${this.busy}
              @click=${this.onImportClick}
            >
              <sonic-icon
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="page-edit"
                size="sm"
              ></sonic-icon>
              ${t("data.import")}
            </sonic-button>
            <input
              id="import-file"
              type="file"
              accept="application/json,.json"
              class="hidden"
              @change=${this.onImportFile}
            />
          </div>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-data-page": ConfigDataPage;
  }
}
