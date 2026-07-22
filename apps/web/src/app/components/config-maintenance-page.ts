import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {purgeArchivedTodos} from "../api/client";
import {refreshConfigAppData} from "../utils/config-refresh";
import {confirmDialog, showAlert, showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import "./config-scope-header";
import "./page-shell";

@customElement("config-maintenance-page")
export class ConfigMaintenancePage extends LitElement {
  static styles = [tailwind];

  @state()
  private busy = false;

  private onPurgeArchived = async () => {
    if (this.busy) return;

    const ok = await confirmDialog({
      title: "Purger les tâches supprimées",
      message:
        "Supprimer définitivement toutes les tâches du filtre « Supprimés » ? Action irréversible.",
      confirmLabel: "Purger",
      danger: true,
    });
    if (!ok) return;

    this.busy = true;
    try {
      const {purgedCount} = await purgeArchivedTodos();
      await refreshConfigAppData();
      await showAlert(
        purgedCount === 0 ? "Rien à purger" : "Purge terminée",
        purgedCount === 0
          ? "Aucune tâche archivée à purger."
          : `${purgedCount} tâche(s) purgée(s).`,
      );
    } catch (error) {
      await showError(error, "Impossible de purger");
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
          <config-scope-header section="maintenance"></config-scope-header>
        </div>

        <div class="mt-6 space-y-3">
          <p class="text-sm text-neutral-600">
            Purge définitive des tâches du filtre « Supprimés » (jeu actif).
          </p>
          <sonic-button
            type="danger"
            size="sm"
            ?disabled=${this.busy}
            @click=${this.onPurgeArchived}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="trash"
              size="sm"
            ></sonic-icon>
            Purger les tâches supprimées
          </sonic-button>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-maintenance-page": ConfigMaintenancePage;
  }
}
