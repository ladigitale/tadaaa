import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {css, html, LitElement} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {bulkUpdateTodos, fetchTodos} from "../api/client";
import type {UpdateTodoPatch} from "../api/types";
import type {TodosFilter} from "../dp";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {
  confirmDialog,
  showAlert,
  showError,
} from "../utils/modal-dialog";
import {todosFilterToListParams} from "../utils/todos-filter-params";
import tailwind from "../../css/tailwind";

type BulkActionId = "done" | "undone" | "archive" | "restore";

type BulkAction = {
  id: BulkActionId;
  label: string;
  icon: string;
  patch: UpdateTodoPatch;
  danger?: boolean;
  confirmTitle: string;
  confirmLabel: string;
};

const BULK_ACTIONS: BulkAction[] = [
  {
    id: "done",
    label: "Marquer comme faites",
    icon: "check",
    patch: {done: true},
    confirmTitle: "Marquer comme faites",
    confirmLabel: "Marquer faites",
  },
  {
    id: "undone",
    label: "Marquer comme à faire",
    icon: "circle",
    patch: {done: false},
    confirmTitle: "Marquer comme à faire",
    confirmLabel: "Marquer à faire",
  },
  {
    id: "archive",
    label: "Supprimer",
    icon: "trash",
    patch: {archived: true},
    danger: true,
    confirmTitle: "Supprimer les tâches",
    confirmLabel: "Supprimer",
  },
  {
    id: "restore",
    label: "Restaurer",
    icon: "undo",
    patch: {archived: false},
    confirmTitle: "Restaurer les tâches",
    confirmLabel: "Restaurer",
  },
];

/**
 * Actions en masse sur toutes les tâches correspondant aux filtres courants.
 */
@customElement("todo-bulk-actions")
export class TodoBulkActions extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: inline-flex;
      }
    `,
  ];

  @property({attribute: false})
  filter!: TodosFilter;

  @state()
  private busy = false;

  private renderMenuIcon(name: string) {
    return html`
      <sonic-icon
        slot="prefix"
        library=${ICON_LIBRARY}
        prefix=${ICON_PREFIX}
        name=${name}
        size="sm"
      ></sonic-icon>
    `;
  }

  private async countMatching(): Promise<number> {
    const params = todosFilterToListParams(this.filter);
    const result = await fetchTodos({...params, offset: 0, limit: 1});
    return result.total;
  }

  private async runAction(action: BulkAction) {
    if (this.busy || !this.filter) return;

    this.busy = true;
    try {
      const total = await this.countMatching();
      if (total === 0) {
        await showAlert(
          action.confirmTitle,
          "Aucune tâche ne correspond aux filtres actuels.",
        );
        return;
      }

      const noun = total === 1 ? "tâche" : "tâches";
      const archiveHint =
        action.id === "archive"
          ? " Elles resteront visibles dans le filtre « Supprimés »."
          : "";
      const ok = await confirmDialog({
        title: action.confirmTitle,
        message: `Appliquer à ${total} ${noun} (sélection filtrée) ?${archiveHint}`,
        confirmLabel: action.confirmLabel,
        danger: action.danger,
      });
      if (!ok) return;

      const result = await bulkUpdateTodos(
        todosFilterToListParams(this.filter),
        action.patch,
      );
      await showAlert(
        action.confirmTitle,
        result.updatedCount === 0
          ? "Aucune tâche mise à jour."
          : result.updatedCount === 1
            ? "1 tâche mise à jour."
            : `${result.updatedCount} tâches mises à jour.`,
      );
    } catch (error) {
      await showError(error, "Impossible d’appliquer l’action");
      console.error(error);
    } finally {
      this.busy = false;
    }
  }

  render() {
    return html`
      <sonic-pop class="inline-block" placement="bottom-end">
        <sonic-button
          size="sm"
          variant="outline"
          ?disabled=${this.busy}
          ?loading=${this.busy}
        >
          <sonic-icon
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="list"
            size="sm"
          ></sonic-icon>
          Actions
          <sonic-icon
            slot="suffix"
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="nav-arrow-down"
            size="sm"
          ></sonic-icon>
        </sonic-button>
        <sonic-menu
          slot="content"
          direction="column"
          align="left"
          size="sm"
          minWidth="14rem"
        >
          ${BULK_ACTIONS.map(
            (action) => html`
              <sonic-menu-item
                type=${action.danger ? "danger" : "default"}
                ?disabled=${this.busy}
                @click=${() => void this.runAction(action)}
              >
                ${this.renderMenuIcon(action.icon)}
                ${action.label}
              </sonic-menu-item>
            `,
          )}
        </sonic-menu>
      </sonic-pop>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "todo-bulk-actions": TodoBulkActions;
  }
}
