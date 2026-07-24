import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {css, html, LitElement} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {bulkUpdateTodos, fetchTodos} from "../api/client";
import type {UpdateTodoPatch} from "../api/types";
import type {TodosFilter} from "../dp";
import {tf, tx} from "../i18n";
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

function bulkActions(): BulkAction[] {
  return [
    {
      id: "done",
      label: tx("tasks.bulk.mark_done"),
      icon: "check",
      patch: {done: true},
      confirmTitle: tx("tasks.bulk.confirm_done_title"),
      confirmLabel: tx("tasks.bulk.mark_done"),
    },
    {
      id: "undone",
      label: tx("tasks.bulk.mark_undone"),
      icon: "circle",
      patch: {done: false},
      confirmTitle: tx("tasks.bulk.confirm_undone_title"),
      confirmLabel: tx("tasks.bulk.mark_undone"),
    },
    {
      id: "archive",
      label: tx("tasks.bulk.delete"),
      icon: "trash",
      patch: {archived: true},
      danger: true,
      confirmTitle: tx("tasks.bulk.confirm_delete_title"),
      confirmLabel: tx("tasks.bulk.delete"),
    },
    {
      id: "restore",
      label: tx("tasks.bulk.restore"),
      icon: "undo",
      patch: {archived: false},
      confirmTitle: tx("tasks.bulk.confirm_restore_title"),
      confirmLabel: tx("tasks.bulk.restore"),
    },
  ];
}

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
        await showAlert(action.confirmTitle, tx("tasks.empty_filtered"));
        return;
      }

      const ok = await confirmDialog({
        title: action.confirmTitle,
        message: tf("tasks.bulk.confirm_apply", {n: total}),
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
          ? tx("tasks.bulk.result_none")
          : tf("tasks.bulk.result_ok", {n: result.updatedCount}),
      );
    } catch (error) {
      await showError(error);
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
          ${t("common.actions")}
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
          ${bulkActions().map(
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
