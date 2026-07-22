import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {
  exportTodosSnapshot,
  importTodosSnapshot,
} from "../api/client";
import {
  exportFileName,
  parseDataPackage,
  TADA_DATA_VERSION,
} from "../api/data-package";
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
      await showError(error, "Impossible d’exporter les données");
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
        new Error("Le fichier n’est pas un JSON valide."),
        "Import impossible",
      );
      return;
    }

    let pkg;
    try {
      pkg = parseDataPackage(parsed);
    } catch (error) {
      await showError(error, "Fichier non compatible");
      return;
    }

    const ok = await confirmDialog({
      title: "Importer des données",
      message: [
        `« ${pkg.name} »`,
        `id ${pkg.id}`,
        `format tada v${pkg.version} (app v${TADA_DATA_VERSION})`,
        "",
        "Remplacer le jeu actif ? Action irréversible.",
      ].join("\n"),
      confirmLabel: "Importer",
      danger: true,
    });
    if (!ok) return;

    this.busy = true;
    try {
      await importTodosSnapshot(parsed);
      await refreshConfigAppData();
    } catch (error) {
      await showError(error, "Impossible d’importer les données");
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

        <div class="mt-6 space-y-3">
          <p class="text-sm text-neutral-600">
            Fichier JSON versionné
            <code class="text-xs">tada v${TADA_DATA_VERSION}</code>
            : id unique de base, nom, tâches et étiquettes.
          </p>
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
              Exporter
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
              Importer…
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
