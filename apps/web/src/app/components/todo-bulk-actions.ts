import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/divider";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {get, post, type ApiResult} from "@supersoniks/concorde/decorators";
import {
  apiResultError,
  endpoints,
  readApiData,
  type ApiData,
  type BulkUpdateBody,
} from "../api/endpoints";
import {buildTodosQuery} from "../api/todos-query";
import type {UpdateTodoPatch, TodosListResponse} from "../api/types";
import type {TodosFilter} from "../dp";
import {dp, set} from "../../utils/dataprovider";
import {bumpTodosRev} from "../init";
import {tf, tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {
  confirmDialog,
  showAlert,
  showError,
} from "../utils/modal-dialog";
import {todosFilterToListParams} from "../utils/todos-filter-params";
import type {TasksViewMode} from "../tasks-ui-prefs";
import tailwind from "../../css/tailwind";

export type {TasksViewMode};

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
 * Menu toolbar : switch liste/calendrier + actions en masse sur les filtres.
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

  @property()
  viewMode: TasksViewMode = "list";

  @property({type: String})
  todosQuery = "";

  @state()
  private busy = false;

  @get(endpoints.todos.dynamic, {
    skipEmptyPlaceholder: true,
    triggerKey: endpoints.keys.refresh.bulkCount,
  })
  @state()
  countPayload: ApiResult<TodosListResponse> | null = null;

  @post(endpoints.todos.bulk, endpoints.keys.submit.bulkUpdate)
  @state()
  bulkPayload: ApiResult<ApiData<{updatedCount: number}>> | null = null;

  private pendingCount: {
    resolve: (n: number) => void;
    reject: (error: Error) => void;
  } | null = null;

  private pendingBulk: {
    action: BulkAction;
  } | null = null;

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("countPayload") && this.pendingCount) {
      const pending = this.pendingCount;
      this.pendingCount = null;
      const total = this.countPayload?.result?.total;
      if (typeof total === "number") {
        pending.resolve(total);
      } else {
        pending.reject(apiResultError(this.countPayload));
      }
    }
    if (changed.has("bulkPayload") && this.pendingBulk) {
      void this.finishBulk();
    }
  }

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

  private setViewMode(mode: TasksViewMode) {
    if (mode === this.viewMode) return;
    this.dispatchEvent(
      new CustomEvent("view-change", {
        detail: {mode},
        bubbles: true,
        composed: true,
      }),
    );
  }

  private printView() {
    window.print();
  }

  private countMatching(): Promise<number> {
    const params = {
      ...todosFilterToListParams(this.filter),
      offset: 0,
      limit: 1,
    };
    this.todosQuery = buildTodosQuery(params);
    return new Promise<number>((resolve, reject) => {
      this.pendingCount = {resolve, reject};
      void this.updateComplete.then(() => {
        dp(endpoints.keys.refresh.bulkCount).invalidate();
      });
    });
  }

  private async runAction(action: BulkAction) {
    if (this.busy || !this.filter) return;

    this.busy = true;
    try {
      const total = await this.countMatching();
      if (total === 0) {
        await showAlert(action.confirmTitle, tx("tasks.empty_filtered"));
        this.busy = false;
        return;
      }

      const ok = await confirmDialog({
        title: action.confirmTitle,
        message: tf("tasks.bulk.confirm_apply", {n: total}),
        confirmLabel: action.confirmLabel,
        danger: action.danger,
      });
      if (!ok) {
        this.busy = false;
        return;
      }

      this.pendingBulk = {action};
      const body: BulkUpdateBody = {
        filter: todosFilterToListParams(this.filter),
        patch: action.patch,
      };
      set(endpoints.keys.submit.bulkUpdate.path, body);
    } catch (error) {
      await showError(error);
      console.error(error);
      this.busy = false;
    }
  }

  private async finishBulk() {
    const pending = this.pendingBulk;
    this.pendingBulk = null;
    set(endpoints.keys.submit.bulkUpdate.path, null);

    const result = readApiData(this.bulkPayload);
    if (!result) {
      await showError(apiResultError(this.bulkPayload));
      this.busy = false;
      return;
    }

    bumpTodosRev();
    await showAlert(
      pending!.action.confirmTitle,
      result.updatedCount === 0
        ? tx("tasks.bulk.result_none")
        : tf("tasks.bulk.result_ok", {n: result.updatedCount}),
    );
    this.busy = false;
  }

  render() {
    const bulkMenuAria = tx("tasks.bulk.menu_aria");

    return html`
      <sonic-pop class="inline-block" placement="bottom-end">
        <sonic-tooltip label=${bulkMenuAria} placement="bottom">
          <sonic-button
            shape="circle"
            size="sm"
            variant="ghost"
            ?disabled=${this.busy}
            ?loading=${this.busy}
            data-aria-label=${bulkMenuAria}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="more-horiz"
              size="sm"
            ></sonic-icon>
          </sonic-button>
        </sonic-tooltip>
        <sonic-menu
          slot="content"
          direction="column"
          align="left"
          size="sm"
          minWidth="14rem"
        >
          <sonic-divider
            label=${tx("tasks.view.aria")}
            align="left"
            size="sm"
          ></sonic-divider>
          <sonic-menu-item
            ?active=${this.viewMode === "list"}
            @click=${() => this.setViewMode("list")}
          >
            ${this.renderMenuIcon("list")} ${tx("tasks.view.list")}
          </sonic-menu-item>
          <sonic-menu-item
            ?active=${this.viewMode === "calendar"}
            @click=${() => this.setViewMode("calendar")}
          >
            ${this.renderMenuIcon("calendar")} ${tx("tasks.view.calendar")}
          </sonic-menu-item>

          <sonic-divider align="left" size="sm"></sonic-divider>
          <sonic-menu-item @click=${() => this.printView()}>
            ${this.renderMenuIcon("printer")}
            ${this.viewMode === "calendar"
              ? tx("tasks.print.calendar")
              : tx("tasks.print.list")}
          </sonic-menu-item>

          <sonic-divider
            label=${tx("tasks.bulk.section")}
            align="left"
            size="sm"
          ></sonic-divider>
          ${bulkActions().map(
            (action) => html`
              <sonic-menu-item
                ?disabled=${this.busy}
                type=${action.danger ? "danger" : "default"}
                @click=${() => void this.runAction(action)}
              >
                ${this.renderMenuIcon(action.icon)} ${action.label}
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
