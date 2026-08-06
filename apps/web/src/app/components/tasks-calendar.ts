import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing, PropertyValues, TemplateResult} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {get, patch, post, type ApiResult} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  apiResultError,
  endpoints,
  readApiData,
  type ApiData,
} from "../api/endpoints";
import {buildTodosQuery, todoCopyInput} from "../api/todos-query";
import type {
  Todo,
  TodoPriority,
  TodosListResponse,
  TodoStatusFilter,
  UpdateTodoPatch,
} from "../api/types";
import type {TodoCreateForm, TodosFilter} from "../dp";
import {dp, set} from "../../utils/dataprovider";
import {bumpTodosRev} from "../init";
import {tf, tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {isActiveDatasetReadonly} from "../sync/cloud-access";
import {
  tacheItemEditPath,
  tacheItemMovePath,
  tacheItemNewPath,
  tacheItemPath,
  tacheNewPath,
} from "../utils/tache-paths";
import {
  type CalendarMode,
  filterCalendarTodos,
  moveTimedTodoToMinutes,
  partitionDayTodos,
  previewTimedDragMinutes,
  resizeTimedTodoEdge,
  resizeTodoDates,
  shiftTodoDates,
  todosForDay,
  todosForRange,
} from "../utils/calendar";
import {
  addDays,
  dayOfMonth,
  daysBetween,
  formatDayTitle,
  formatMinutesLabel,
  formatMonthTitle,
  formatWeekTitle,
  formatYearTitle,
  minutesToTime,
  monthGridDays,
  monthRangeContaining,
  monthShortName,
  sameMonth,
  shiftAnchor,
  snapMinutes,
  toDateOnly,
  todayDateOnly,
  todoDateSpan,
  weekRangeContaining,
  weekdayLabels,
  yearRangeContaining,
} from "../utils/dates";
import {
  clearCalendarDragToast,
  toastCalendarDrag,
} from "../notifications/sonic-toasts";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {navigateTo} from "../utils/navigate";
import {stashTodoCreateDraft} from "../utils/todo-create-draft";
import {tasksUiPrefs} from "../tasks-ui-prefs";
import tailwind from "../../css/tailwind";

const DOUBLE_ACTIVATE_MS = 350;

type SonicPop = HTMLElement & {show: () => void};

const MONTH_CHIP_LIMIT = 3;
const DAY_HOURS = 24;

type DragKind = "move" | "resize-start" | "resize-end";

type DragState = {
  kind: DragKind;
  todoId: string;
  originDay: string;
  currentDay: string;
  /** Day-view timed drag: minutes from midnight (snapped). */
  originMinutes: number | null;
  currentMinutes: number | null;
  /** Timed block bounds at drag start (for move/resize preview). */
  originStartMin: number | null;
  originEndMin: number | null;
  moved: boolean;
  pointerX: number;
  pointerY: number;
};

type DateSpan = {start: string; end: string};

function priorityTone(priority: TodoPriority): string {
  if (priority === "high") return "bg-danger-100 text-danger-900 border-danger-300";
  if (priority === "low") return "bg-neutral-100 text-neutral-700 border-neutral-300";
  return "bg-warning-100 text-warning-900 border-warning-300";
}

function calendarStatus(status: TodosFilter["status"]): TodoStatusFilter {
  if (status === "archived") return "all";
  return status;
}

/**
 * Vue calendaire embarquée dans la page tâches.
 * Hérite du scope / filtres `TodosFilter` (pas de page ni filtres propres).
 */
@customElement("tasks-calendar")
export class TasksCalendar extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
        min-height: 0;
      }

      .cal-grid-week,
      .cal-grid-month {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        gap: 0.25rem;
      }

      .cal-grid-year {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
      }

      @media (min-width: 640px) {
        .cal-grid-year {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }
      }

      @media (min-width: 1024px) {
        .cal-grid-year {
          grid-template-columns: repeat(4, minmax(0, 1fr));
        }
      }

      .cal-cell {
        min-height: 5.5rem;
        border: 1px solid color-mix(in srgb, currentColor 14%, transparent);
        border-radius: 0.35rem;
        padding: 0.25rem;
        background: var(--sc-base, #fff);
        cursor: pointer;
      }

      .cal-cell[data-muted="true"] {
        opacity: 0.45;
      }

      .cal-cell[data-today="true"] {
        outline: 2px solid currentColor;
        outline-offset: -2px;
      }

      .cal-cell[data-anchor="true"] {
        outline: 2px solid var(--sc-primary, #2563eb);
        outline-offset: -2px;
      }

      .cal-year-month[data-anchor="true"] {
        outline: 2px solid var(--sc-primary, #2563eb);
        outline-offset: -2px;
      }

      .cal-cell[data-preview="true"] {
        background: color-mix(in srgb, var(--sc-primary, #2563eb) 10%, transparent);
        border-color: color-mix(in srgb, var(--sc-primary, #2563eb) 45%, transparent);
      }

      .cal-cell[data-drop="true"] {
        background: color-mix(in srgb, var(--sc-primary, #2563eb) 18%, transparent);
        border-color: var(--sc-primary, #2563eb);
        box-shadow: inset 0 0 0 1px var(--sc-primary, #2563eb);
      }

      .cal-cell[data-drop="true"] .cal-day-num {
        color: var(--sc-primary, #2563eb);
      }

      :host([data-dragging="true"]) {
        cursor: grabbing;
        user-select: none;
      }

      :host([data-dragging="true"]) .cal-chip {
        cursor: grabbing;
      }

      .cal-chip {
        display: flex;
        align-items: center;
        gap: 0.15rem;
        width: 100%;
        margin-top: 0.15rem;
        padding: 0.1rem 0.2rem;
        border: 1px solid;
        border-radius: 0.25rem;
        font-size: 0.65rem;
        line-height: 1.2;
        cursor: grab;
        text-align: left;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        user-select: none;
        touch-action: none;
      }

      .cal-chip[data-done="true"] {
        opacity: 0.55;
        text-decoration: line-through;
      }

      .cal-chip[data-source="true"] {
        opacity: 0.35;
        outline: 1px dashed color-mix(in srgb, currentColor 50%, transparent);
      }

      .cal-chip[data-preview-chip="true"] {
        opacity: 0.95;
        pointer-events: none;
        box-shadow: 0 0 0 1px var(--sc-primary, #2563eb);
      }

      .cal-grip,
      .cal-handle {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        color: color-mix(in srgb, currentColor 70%, transparent);
        touch-action: none;
      }

      .cal-grip {
        cursor: grab;
      }

      .cal-handle {
        cursor: ew-resize;
        border-radius: 0.15rem;
        padding: 0 0.05rem;
        background: color-mix(in srgb, currentColor 12%, transparent);
      }

      .cal-chip-label {
        min-width: 0;
        flex: 1 1 auto;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .cal-chip-pop,
      .cal-chip-tooltip {
        display: block;
        width: 100%;
        min-width: 0;
      }

      .cal-day-event-pop {
        position: absolute;
        left: 0.2rem;
        right: 0.2rem;
        z-index: 2;
        display: block;
      }

      .cal-drag-ghost {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 40;
        display: flex;
        max-width: 14rem;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.45rem;
        border: 1px solid;
        border-radius: 0.3rem;
        font-size: 0.7rem;
        line-height: 1.2;
        pointer-events: none;
        box-shadow: 0 8px 20px color-mix(in srgb, #000 18%, transparent);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .cal-day {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        min-height: 0;
      }

      .cal-allday {
        border: 1px solid color-mix(in srgb, currentColor 14%, transparent);
        border-radius: 0.35rem;
        padding: 0.35rem 0.5rem;
        background: var(--sc-base, #fff);
      }

      .cal-allday-label {
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: color-mix(in srgb, currentColor 55%, transparent);
        margin-bottom: 0.2rem;
      }

      .cal-day-scroll {
        overflow: auto;
        max-height: min(70vh, 36rem);
        border: 1px solid color-mix(in srgb, currentColor 14%, transparent);
        border-radius: 0.35rem;
        background: var(--sc-base, #fff);
      }

      .cal-day-grid {
        position: relative;
        display: grid;
        grid-template-columns: 3.25rem 1fr;
        --cal-hour-h: 48px;
      }

      .cal-day-hours {
        grid-column: 1;
        position: relative;
        height: calc(var(--cal-hour-h) * 24);
      }

      .cal-day-hour {
        height: var(--cal-hour-h);
        padding-right: 0.35rem;
        font-size: 0.65rem;
        line-height: 1;
        text-align: right;
        color: color-mix(in srgb, currentColor 50%, transparent);
        transform: translateY(-0.35em);
      }

      .cal-day-hour:first-child {
        transform: none;
        padding-top: 0.15rem;
      }

      .cal-day-lanes {
        grid-column: 2;
        position: relative;
        height: calc(var(--cal-hour-h) * 24);
        border-left: 1px solid color-mix(in srgb, currentColor 12%, transparent);
      }

      .cal-day-slot {
        position: absolute;
        left: 0;
        right: 0;
        z-index: 1;
        margin: 0;
        padding: 0;
        border: 0;
        background: transparent;
        cursor: pointer;
      }

      .cal-day-slot:hover,
      .cal-day-slot:focus-visible {
        background: color-mix(in srgb, var(--sc-primary, #2563eb) 7%, transparent);
      }

      .cal-day-line {
        position: absolute;
        left: 0;
        right: 0;
        border-top: 1px solid color-mix(in srgb, currentColor 10%, transparent);
        pointer-events: none;
      }

      .cal-day-now {
        position: absolute;
        left: 0;
        right: 0;
        z-index: 3;
        border-top: 2px solid var(--sc-danger, #dc2626);
        pointer-events: none;
      }

      .cal-day-now::before {
        content: "";
        position: absolute;
        left: -0.3rem;
        top: -0.3rem;
        width: 0.55rem;
        height: 0.55rem;
        border-radius: 999px;
        background: var(--sc-danger, #dc2626);
      }

      .cal-day-event {
        box-sizing: border-box;
        display: flex;
        width: 100%;
        height: 100%;
        flex-direction: column;
        gap: 0.1rem;
        padding: 0.45rem 0.35rem 0.45rem;
        border: 1px solid;
        border-radius: 0.3rem;
        font-size: 0.7rem;
        line-height: 1.25;
        cursor: grab;
        overflow: hidden;
        text-align: left;
        touch-action: none;
        user-select: none;
      }

      .cal-day-event[data-done="true"] {
        opacity: 0.55;
        text-decoration: line-through;
      }

      .cal-day-event[data-preview="true"] {
        opacity: 0.85;
        pointer-events: none;
        box-shadow: 0 0 0 1px var(--sc-primary, #2563eb);
      }

      .cal-day-event[data-source="true"] {
        opacity: 0.35;
        outline: 1px dashed color-mix(in srgb, currentColor 50%, transparent);
      }

      .cal-day-event-time {
        font-size: 0.62rem;
        font-weight: 600;
        opacity: 0.8;
      }

      .cal-day-event-title {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-weight: 500;
      }

      .cal-day-handle {
        position: absolute;
        left: 0.35rem;
        right: 0.35rem;
        z-index: 3;
        height: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: ns-resize;
        color: color-mix(in srgb, currentColor 65%, transparent);
        touch-action: none;
        border-radius: 0.15rem;
      }

      .cal-day-handle:hover,
      .cal-day-handle:focus-visible {
        background: color-mix(in srgb, currentColor 14%, transparent);
        color: currentColor;
      }

      .cal-day-handle-start {
        top: 0;
      }

      .cal-day-handle-end {
        bottom: 0;
      }

      .cal-day-handle-bar {
        width: 1.25rem;
        height: 0.15rem;
        border-radius: 999px;
        background: currentColor;
      }

      @media print {
        :host {
          color: #111 !important;
        }

        .cal-no-print,
        .cal-handle,
        .cal-grip,
        .cal-day-handle {
          display: none !important;
        }

        .cal-day-scroll {
          overflow: visible !important;
        }

        h2 {
          color: #111 !important;
          font-weight: 600 !important;
        }

        .cal-cell {
          break-inside: avoid;
          border-color: #999 !important;
          background: #fff !important;
          opacity: 1 !important;
        }

        .cal-cell[data-muted="true"] {
          opacity: 0.85 !important;
        }

        .cal-cell[data-today="true"],
        .cal-cell[data-anchor="true"] {
          outline: 1.5px solid #333 !important;
          outline-offset: -1.5px;
        }

        .cal-day-num {
          color: #111 !important;
          font-weight: 600 !important;
        }

        .cal-chip,
        .cal-day-event {
          cursor: default;
          opacity: 1 !important;
          color: #111 !important;
          border-color: #666 !important;
          background: #fff !important;
        }

        .cal-chip[data-done="true"],
        .cal-day-event[data-done="true"] {
          opacity: 0.75 !important;
        }

        .cal-chip-label,
        .cal-day-event-title,
        .cal-day-event-time {
          color: #111 !important;
        }

        .cal-day-hour,
        .cal-day-line {
          border-color: #bbb !important;
          color: #333 !important;
        }
      }
    `,
  ];

  /** Filtres / scope partagés avec la liste (todosFilter). */
  @property({attribute: false})
  filter: TodosFilter = {
    q: "",
    status: "all",
    tags: [],
    sort: "createdAt:desc",
    sortBy: "createdAt",
    sortDir: "desc",
    parentId: "",
    recursive: false,
    _rev: 0,
  };

  @state() private mode: CalendarMode = "month";
  @state() private anchor = todayDateOnly();
  @state() private todos: Todo[] = [];
  @state() private loading = true;
  @state() private isReadonly = false;
  @state() private drag: DragState | null = null;
  @state() private busyId: string | null = null;

  /** Cible du `@patch` / `@post` copy calendrier. */
  @property({type: String})
  todoId = "";

  @property({type: String})
  todosQuery = "";

  @get(endpoints.todos.dynamic, {
    skipEmptyPlaceholder: true,
    triggerKey: endpoints.keys.refresh.calendarTodos,
  })
  @state()
  todosPayload: ApiResult<TodosListResponse> | null = null;

  @patch(endpoints.todos.patch, endpoints.keys.submit.calendarTodoPatch, {
    skipEmptyPlaceholder: true,
    autoPostOnBodyMutation: false,
    triggerKey: endpoints.keys.refresh.calendarTodoPatch,
  })
  @state()
  patchPayload: ApiResult<ApiData<Todo>> | null = null;

  @post(endpoints.todos.collection, endpoints.keys.submit.calendarTodoCopy, {
    autoPostOnBodyMutation: false,
    triggerKey: endpoints.keys.refresh.calendarTodoCopy,
  })
  @state()
  copyPayload: ApiResult<ApiData<Todo>> | null = null;

  private pendingPatch:
    | {kind: "reload"}
    | {kind: "dates"; todoId: string}
    | null = null;

  private pendingCopy = false;
  private pendingReload = false;

  /** Pop à ouvrir après un clic sans drag (évite le conflit click/toggle). */
  private dragTriggerPop: SonicPop | null = null;
  /** Double-clic / double-tap unifié (souris + tactile). */
  private lastActivate: {key: string; at: number} | null = null;

  connectedCallback() {
    super.connectedCallback();
    const stored = tasksUiPrefs.loadCalendarPrefs();
    this.mode = stored.mode;
    this.anchor = stored.anchor;
    void this.reload();
    window.addEventListener("pointermove", this.onPointerMove);
    window.addEventListener("pointerup", this.onPointerUp);
    window.addEventListener("keydown", this.onKeyDown);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    window.removeEventListener("pointermove", this.onPointerMove);
    window.removeEventListener("pointerup", this.onPointerUp);
    window.removeEventListener("keydown", this.onKeyDown);
    this.clearDrag();
  }

  protected updated(changed: PropertyValues) {
    if (changed.has("filter")) {
      void this.reload();
    }
    if (changed.has("todosPayload") && this.pendingReload) {
      void this.finishReload();
    }
    if (changed.has("patchPayload") && this.pendingPatch) {
      void this.finishPatch();
    }
    if (changed.has("copyPayload") && this.pendingCopy) {
      void this.finishCopy();
    }
  }

  private async queueTodoPatch(
    todoId: string,
    patchBody: UpdateTodoPatch,
    pending: NonNullable<typeof this.pendingPatch>,
  ) {
    this.busyId = todoId;
    this.todoId = todoId;
    this.pendingPatch = pending;
    set(endpoints.keys.submit.calendarTodoPatch.path, patchBody);
    await this.updateComplete;
    dp(endpoints.keys.refresh.calendarTodoPatch).invalidate();
  }

  private async finishPatch() {
    const pending = this.pendingPatch;
    this.pendingPatch = null;
    set(endpoints.keys.submit.calendarTodoPatch.path, null);

    const updated = readApiData(this.patchPayload);
    if (!updated) {
      await showError(apiResultError(this.patchPayload));
      this.busyId = null;
      return;
    }

    if (pending?.kind === "dates") {
      this.todos = this.todos.map((item) =>
        item.id === updated.id ? {...item, ...updated} : item,
      );
      this.busyId = null;
      return;
    }

    bumpTodosRev();
    await this.reload();
    this.busyId = null;
  }

  private persist() {
    tasksUiPrefs.saveCalendarPrefs({mode: this.mode, anchor: this.anchor});
  }

  private async reload() {
    this.loading = true;
    this.pendingReload = true;
    const tagIds = Array.isArray(this.filter.tags)
      ? this.filter.tags.filter(Boolean)
      : [];
    this.todosQuery = buildTodosQuery({
      status: calendarStatus(this.filter.status),
      q: this.filter.q?.trim() || null,
      tagIds: tagIds.length > 0 ? tagIds : null,
      parentId: this.filter.parentId || "",
      recursive: Boolean(this.filter.recursive),
      sortBy: "startAt",
      sortDir: "asc",
      limit: 5000,
    });
    await this.updateComplete;
    dp(endpoints.keys.refresh.calendarTodos).invalidate();
    void isActiveDatasetReadonly().then((readonly) => {
      this.isReadonly = readonly;
    });
  }

  private async finishReload() {
    if (this.todosPayload == null) return;
    this.pendingReload = false;
    const data = this.todosPayload.result?.data;
    if (!Array.isArray(data)) {
      await showError(apiResultError(this.todosPayload));
      this.loading = false;
      return;
    }
    this.todos = data;
    this.loading = false;
  }

  private get filtered(): Todo[] {
    return filterCalendarTodos(this.todos, {
      q: "",
      status:
        this.filter.status === "done"
          ? "done"
          : this.filter.status === "active"
            ? "active"
            : "all",
      tags: [],
      priorities: [],
      datePresence: "either",
    });
  }

  private get periodTitle(): string {
    if (this.mode === "day") return formatDayTitle(this.anchor);
    if (this.mode === "week") {
      const {start, end} = weekRangeContaining(this.anchor);
      return formatWeekTitle(start, end);
    }
    if (this.mode === "year") return formatYearTitle(this.anchor);
    return formatMonthTitle(this.anchor);
  }

  private setMode(mode: CalendarMode) {
    this.mode = mode;
    this.persist();
  }

  private goToday() {
    this.anchor = todayDateOnly();
    this.persist();
  }

  private goPrev() {
    this.anchor = shiftAnchor(this.anchor, this.mode, -1);
    this.persist();
  }

  private goNext() {
    this.anchor = shiftAnchor(this.anchor, this.mode, 1);
    this.persist();
  }

  private focusDay(day: string) {
    this.anchor = day;
    this.persist();
  }

  private focusMonth(year: number, monthIndex0: number) {
    this.anchor = `${year}-${String(monthIndex0 + 1).padStart(2, "0")}-01`;
    this.persist();
  }

  private openDay(day: string) {
    this.anchor = day;
    this.setMode("day");
  }

  private openMonth(year: number, monthIndex0: number) {
    this.anchor = `${year}-${String(monthIndex0 + 1).padStart(2, "0")}-01`;
    this.setMode("month");
  }

  /**
   * Premier clic → action simple (focus) ; second clic/tap rapide → double.
   * Couvre souris et tactile (pas de dépendance à `dblclick`).
   */
  private onActivate(
    key: string,
    onSingle: () => void,
    onDouble: () => void,
  ) {
    const now = performance.now();
    if (
      this.lastActivate &&
      this.lastActivate.key === key &&
      now - this.lastActivate.at < DOUBLE_ACTIVATE_MS
    ) {
      this.lastActivate = null;
      onDouble();
      return;
    }
    this.lastActivate = {key, at: now};
    onSingle();
  }

  private openCreate(draft: Partial<TodoCreateForm>) {
    if (this.isReadonly) return;
    stashTodoCreateDraft(draft);
    const parent = this.filter.parentId?.trim();
    navigateTo(parent ? tacheItemNewPath(parent) : tacheNewPath());
  }

  private createTodoForDay(day: string) {
    this.openCreate({startAt: day, endAt: day});
  }

  private createTodoAtHour(hour: number) {
    const startMin = Math.max(0, Math.min(23, hour)) * 60;
    const endMin = Math.min(startMin + 60, 24 * 60 - 1);
    this.openCreate({
      startAt: this.anchor,
      startTime: minutesToTime(startMin),
      endAt: this.anchor,
      endTime: minutesToTime(endMin),
    });
  }

  private renderMenuItemIcon(name: string) {
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

  private renderTodoActionsMenu(todo: Todo): TemplateResult {
    const childCount = todo.childCount ?? 0;
    const busy = this.busyId === todo.id;
    return html`
      <sonic-menu
        slot="content"
        direction="column"
        align="left"
        size="sm"
        minWidth="12rem"
      >
        ${!todo.archived
          ? html`
              <sonic-menu-item
                href=${tacheItemPath(todo.id)}
                pushstate
                ?disabled=${busy}
              >
                ${this.renderMenuItemIcon("eye")}
                ${t("tasks.see")}
                ${childCount > 0
                  ? html`
                      <sonic-badge slot="suffix" type="neutral" size="xs"
                        >${childCount}</sonic-badge
                      >
                    `
                  : nothing}
              </sonic-menu-item>
              <sonic-menu-item
                ?disabled=${busy || this.isReadonly}
                @click=${() => this.onToggleDoneTodo(todo)}
              >
                ${this.renderMenuItemIcon(
                  todo.done ? "undo" : "check-circle",
                )}
                ${t(todo.done ? "tasks.mark_undone" : "tasks.mark_done")}
              </sonic-menu-item>
              <sonic-menu-item
                href=${tacheItemEditPath(todo.id)}
                pushstate
                ?disabled=${busy || this.isReadonly}
              >
                ${this.renderMenuItemIcon("edit-pencil")} ${t("tasks.edit")}
              </sonic-menu-item>
              <sonic-menu-item
                href=${tacheItemMovePath(todo.id)}
                pushstate
                ?disabled=${busy || this.isReadonly}
              >
                ${this.renderMenuItemIcon("data-transfer-both")}
                ${t("tasks.move")}
              </sonic-menu-item>
              <sonic-menu-item
                ?disabled=${busy || this.isReadonly}
                @click=${() => this.onCopyTodo(todo)}
              >
                ${this.renderMenuItemIcon("copy")} ${t("tasks.copy")}
              </sonic-menu-item>
              <sonic-menu-item
                type="danger"
                ?disabled=${busy || this.isReadonly}
                @click=${() => this.onDeleteToggleTodo(todo)}
              >
                ${this.renderMenuItemIcon("trash")} ${t("tasks.delete")}
              </sonic-menu-item>
            `
          : html`
              <sonic-menu-item
                ?disabled=${busy || this.isReadonly}
                @click=${() => this.onDeleteToggleTodo(todo)}
              >
                ${this.renderMenuItemIcon("undo")} ${t("tasks.restore")}
              </sonic-menu-item>
            `}
      </sonic-menu>
    `;
  }

  private async onToggleDoneTodo(todo: Todo) {
    if (todo.archived || this.isReadonly || this.busyId) return;
    await this.queueTodoPatch(todo.id, {done: !todo.done}, {kind: "reload"});
  }

  private async onCopyTodo(todo: Todo) {
    if (todo.archived || this.isReadonly || this.busyId) return;
    this.busyId = todo.id;
    this.pendingCopy = true;
    set(endpoints.keys.submit.calendarTodoCopy.path, todoCopyInput(todo));
    await this.updateComplete;
    dp(endpoints.keys.refresh.calendarTodoCopy).invalidate();
  }

  private async finishCopy() {
    this.pendingCopy = false;
    set(endpoints.keys.submit.calendarTodoCopy.path, null);
    const created = readApiData(this.copyPayload);
    if (!created) {
      await showError(apiResultError(this.copyPayload));
      this.busyId = null;
      return;
    }
    bumpTodosRev();
    await this.reload();
    this.busyId = null;
  }

  private async onDeleteToggleTodo(todo: Todo) {
    if (this.isReadonly || this.busyId) return;
    const deleting = !todo.archived;
    if (deleting) {
      const ok = await confirmDialog({
        title: tx("tasks.delete_title"),
        message: tx("tasks.delete_confirm"),
        confirmLabel: tx("tasks.delete"),
        danger: true,
      });
      if (!ok) return;
    }
    await this.queueTodoPatch(
      todo.id,
      {archived: !todo.archived},
      {kind: "reload"},
    );
  }

  private onKeyDown = (event: KeyboardEvent) => {
    if (event.key === "Escape" && this.drag) {
      this.clearDrag();
    }
  };

  private dayFromPoint(clientX: number, clientY: number): string | null {
    const el = this.shadowRoot?.elementFromPoint(clientX, clientY) as
      | HTMLElement
      | null;
    const cell = el?.closest?.("[data-day]") as HTMLElement | null;
    return cell?.dataset.day ?? null;
  }

  private minutesFromPoint(clientY: number): number | null {
    const lanes = this.shadowRoot?.querySelector(
      ".cal-day-lanes",
    ) as HTMLElement | null;
    if (!lanes) return null;
    const rect = lanes.getBoundingClientRect();
    if (rect.height <= 0) return null;
    const ratio = (clientY - rect.top) / rect.height;
    return snapMinutes(ratio * DAY_HOURS * 60);
  }

  private clearDrag() {
    this.drag = null;
    this.dragTriggerPop = null;
    this.removeAttribute("data-dragging");
    clearCalendarDragToast();
  }

  private syncDragToast(drag: DragState) {
    if (!drag.moved) return;
    const todo = this.todos.find((item) => item.id === drag.todoId);
    if (!todo) return;
    const detail =
      drag.currentMinutes !== null
        ? tf("calendar.drag.drop_at", {
            time: formatMinutesLabel(drag.currentMinutes),
          })
        : tf("calendar.drag.drop_on", {
            day: formatDayTitle(drag.currentDay),
          });
    toastCalendarDrag({
      title: this.dragKindLabel(drag.kind),
      text: `${todo.text} — ${detail}`,
    });
  }

  private syncGhostPosition(x: number, y: number) {
    const ghost = this.shadowRoot?.querySelector(
      ".cal-drag-ghost",
    ) as HTMLElement | null;
    if (!ghost) return;
    ghost.style.transform = `translate(${x + 12}px, ${y + 12}px)`;
  }

  private beginDrag(
    kind: DragKind,
    todo: Todo,
    originDay: string,
    event: PointerEvent,
    originMinutes: number | null = null,
    originBounds: {startMin: number; endMin: number} | null = null,
  ) {
    if (this.isReadonly || this.busyId) return;
    event.preventDefault();
    event.stopPropagation();
    this.dragTriggerPop = (event.currentTarget as HTMLElement | null)?.closest(
      "sonic-pop",
    ) as SonicPop | null;
    this.setAttribute("data-dragging", "true");
    this.drag = {
      kind,
      todoId: todo.id,
      originDay,
      currentDay: originDay,
      originMinutes,
      currentMinutes: originMinutes,
      originStartMin: originBounds?.startMin ?? null,
      originEndMin: originBounds?.endMin ?? null,
      moved: false,
      pointerX: event.clientX,
      pointerY: event.clientY,
    };
    requestAnimationFrame(() =>
      this.syncGhostPosition(event.clientX, event.clientY),
    );
  }

  private onPointerMove = (event: PointerEvent) => {
    if (!this.drag) return;
    this.syncGhostPosition(event.clientX, event.clientY);
    let moved = this.drag.moved;
    if (!moved) {
      const dx = event.clientX - this.drag.pointerX;
      const dy = event.clientY - this.drag.pointerY;
      moved = dx * dx + dy * dy > 36;
    }

    if (this.drag.originMinutes !== null) {
      const minutes = this.minutesFromPoint(event.clientY);
      if (minutes !== null && minutes !== this.drag.currentMinutes) {
        const next = {
          ...this.drag,
          currentMinutes: minutes,
          moved: true,
          pointerX: event.clientX,
          pointerY: event.clientY,
        };
        this.drag = next;
        this.syncDragToast(next);
        return;
      }
      if (moved !== this.drag.moved) {
        const next = {
          ...this.drag,
          moved,
          pointerX: event.clientX,
          pointerY: event.clientY,
        };
        this.drag = next;
        this.syncDragToast(next);
      }
      return;
    }

    const day = this.dayFromPoint(event.clientX, event.clientY);
    if (day && day !== this.drag.currentDay) {
      const next = {
        ...this.drag,
        currentDay: day,
        moved: true,
        pointerX: event.clientX,
        pointerY: event.clientY,
      };
      this.drag = next;
      this.syncDragToast(next);
      return;
    }
    if (moved !== this.drag.moved) {
      const next = {
        ...this.drag,
        moved,
        pointerX: event.clientX,
        pointerY: event.clientY,
      };
      this.drag = next;
      this.syncDragToast(next);
    }
  };

  private onPointerUp = () => {
    if (!this.drag) return;
    const drag = this.drag;
    const pop = this.dragTriggerPop;
    this.clearDrag();
    if (!drag.moved) {
      if (drag.kind === "move") pop?.show();
      return;
    }
    void this.applyDrag(drag);
  };

  private previewSpanForDrag(drag: DragState): DateSpan | null {
    const todo = this.todos.find((item) => item.id === drag.todoId);
    if (!todo) return null;
    if (drag.kind === "move") {
      const delta = daysBetween(drag.originDay, drag.currentDay);
      if (delta === 0) return todoDateSpan(todo);
      const next = shiftTodoDates(todo, delta);
      return next
        ? {
            start: toDateOnly(next.startAt) ?? next.startAt,
            end: toDateOnly(next.endAt) ?? next.endAt,
          }
        : null;
    }
    const next = resizeTodoDates(
      todo,
      drag.kind === "resize-start" ? "start" : "end",
      drag.currentDay,
    );
    return next
      ? {
          start: toDateOnly(next.startAt) ?? next.startAt,
          end: toDateOnly(next.endAt) ?? next.endAt,
        }
      : null;
  }

  private dayInSpan(day: string, span: DateSpan | null): boolean {
    if (!span) return false;
    const start = toDateOnly(span.start) ?? span.start;
    const end = toDateOnly(span.end) ?? span.end;
    return day >= start && day <= end;
  }

  private dragKindIcon(kind: DragKind): string {
    if (kind === "resize-start") return "nav-arrow-left";
    if (kind === "resize-end") return "nav-arrow-right";
    return "more-vert";
  }

  private dragKindLabel(kind: DragKind): string {
    if (kind === "resize-start") return tx("calendar.drag.resize_start");
    if (kind === "resize-end") return tx("calendar.drag.resize_end");
    return tx("calendar.drag.move");
  }

  private renderIcon(name: string, size: "xs" | "sm" = "xs") {
    return html`
      <sonic-icon
        library=${ICON_LIBRARY}
        prefix=${ICON_PREFIX}
        name=${name}
        size=${size}
      ></sonic-icon>
    `;
  }

  private async applyDrag(drag: DragState) {
    const todo = this.todos.find((item) => item.id === drag.todoId);
    if (!todo) return;

    let next: {startAt: string; endAt: string} | null = null;
    if (drag.originMinutes !== null && drag.currentMinutes !== null) {
      if (drag.currentMinutes === drag.originMinutes) return;
      if (drag.kind === "move") {
        next = moveTimedTodoToMinutes(
          todo,
          drag.originDay,
          drag.currentMinutes,
        );
      } else {
        next = resizeTimedTodoEdge(
          todo,
          drag.originDay,
          drag.kind === "resize-start" ? "start" : "end",
          drag.currentMinutes,
        );
      }
    } else {
      if (drag.currentDay === drag.originDay) return;
      if (drag.kind === "move") {
        next = shiftTodoDates(
          todo,
          daysBetween(drag.originDay, drag.currentDay),
        );
      } else if (drag.kind === "resize-start") {
        next = resizeTodoDates(todo, "start", drag.currentDay);
      } else {
        next = resizeTodoDates(todo, "end", drag.currentDay);
      }
    }
    if (!next) return;

    await this.queueTodoPatch(
      todo.id,
      {startAt: next.startAt, endAt: next.endAt},
      {kind: "dates", todoId: todo.id},
    );
  }

  private renderModeButtons() {
    const modes: CalendarMode[] = ["day", "week", "month", "year"];
    const labels: Record<CalendarMode, string> = {
      day: tx("calendar.mode.day"),
      week: tx("calendar.mode.week"),
      month: tx("calendar.mode.month"),
      year: tx("calendar.mode.year"),
    };
    return html`
      <div class="flex flex-wrap gap-1">
        ${modes.map(
          (mode) => html`
            <sonic-button
              size="sm"
              type=${this.mode === mode ? "primary" : "default"}
              variant=${this.mode === mode ? "default" : "outline"}
              @click=${() => this.setMode(mode)}
            >
              ${labels[mode]}
            </sonic-button>
          `,
        )}
      </div>
    `;
  }

  private renderToolbar() {
    return html`
      <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="min-w-0 text-left text-base font-semibold sm:text-lg">
            ${this.periodTitle}
          </h2>
          <div class="cal-no-print flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1">
              <sonic-tooltip label=${tx("calendar.prev")} placement="bottom">
                <sonic-button
                  variant="outline"
                  size="sm"
                  data-aria-label=${tx("calendar.prev")}
                  @click=${this.goPrev}
                >
                  <sonic-icon
                    library=${ICON_LIBRARY}
                    prefix=${ICON_PREFIX}
                    name="nav-arrow-left"
                    size="sm"
                  ></sonic-icon>
                </sonic-button>
              </sonic-tooltip>
              <sonic-button variant="outline" size="sm" @click=${this.goToday}>
                ${t("calendar.today")}
              </sonic-button>
              <sonic-tooltip label=${tx("calendar.next")} placement="bottom">
                <sonic-button
                  variant="outline"
                  size="sm"
                  data-aria-label=${tx("calendar.next")}
                  @click=${this.goNext}
                >
                  <sonic-icon
                    library=${ICON_LIBRARY}
                    prefix=${ICON_PREFIX}
                    name="nav-arrow-right"
                    size="sm"
                  ></sonic-icon>
                </sonic-button>
              </sonic-tooltip>
            </div>
            ${this.renderModeButtons()}
          </div>
        </div>
        ${this.isReadonly
          ? html`<p class="text-xs text-neutral-500">${t("calendar.readonly")}</p>`
          : nothing}
      </div>
    `;
  }

  private renderChip(
    todo: Todo,
    day: string,
    showHandles = false,
    opts: {preview?: boolean; inPreviewSpan?: boolean} = {},
  ) {
    const span = todoDateSpan(todo);
    const isStart = span?.start === day;
    const isEnd = span?.end === day;
    const multi = Boolean(span && span.start !== span.end);
    const canDrag = showHandles && !this.isReadonly && !opts.preview;
    const isDragged = Boolean(
      this.drag?.moved && this.drag.todoId === todo.id && !opts.preview,
    );
    const isLeaving = isDragged && !opts.inPreviewSpan;
    const isLanding = opts.preview || (isDragged && Boolean(opts.inPreviewSpan));
    const chip = html`
      <sonic-tooltip
        class="cal-chip-tooltip"
        label=${todo.text}
        placement="top"
      >
        <button
          type="button"
          class="cal-chip ${priorityTone(todo.priority)}"
          data-done=${todo.done ? "true" : "false"}
          data-source=${isLeaving ? "true" : "false"}
          data-preview-chip=${isLanding ? "true" : "false"}
          @pointerdown=${(event: PointerEvent) => {
            if (!canDrag) return;
            this.beginDrag("move", todo, day, event);
          }}
          @click=${(event: Event) => {
            if (opts.preview || canDrag) {
              // Preview: inerte. Drag: ouverture via pointerup→show()
              // pour ne pas toggler/fermer le pop juste après.
              event.preventDefault();
              event.stopPropagation();
            }
          }}
        >
          ${canDrag && multi && isStart
            ? html`
                <span
                  class="cal-handle"
                  title=${tx("calendar.drag.resize_start")}
                  @pointerdown=${(event: PointerEvent) =>
                    this.beginDrag("resize-start", todo, day, event)}
                >
                  ${this.renderIcon("nav-arrow-left")}
                </span>
              `
            : nothing}
          ${canDrag
            ? html`
                <span class="cal-grip" aria-hidden="true">
                  ${this.renderIcon("more-vert")}
                </span>
              `
            : nothing}
          ${opts.preview && this.drag
            ? html`
                <span class="cal-grip" aria-hidden="true">
                  ${this.renderIcon(this.dragKindIcon(this.drag.kind))}
                </span>
              `
            : nothing}
          <span class="cal-chip-label">${todo.text}</span>
          ${canDrag && multi && isEnd
            ? html`
                <span
                  class="cal-handle"
                  title=${tx("calendar.drag.resize_end")}
                  @pointerdown=${(event: PointerEvent) =>
                    this.beginDrag("resize-end", todo, day, event)}
                >
                  ${this.renderIcon("nav-arrow-right")}
                </span>
              `
            : nothing}
        </button>
      </sonic-tooltip>
    `;
    if (opts.preview) return chip;
    return html`
      <sonic-pop class="cal-chip-pop" placement="bottom">
        ${chip} ${this.renderTodoActionsMenu(todo)}
      </sonic-pop>
    `;
  }

  private renderDragGhost() {
    if (!this.drag?.moved) return nothing;
    const todo = this.todos.find((item) => item.id === this.drag!.todoId);
    if (!todo) return nothing;
    return html`
      <div
        class="cal-drag-ghost ${priorityTone(todo.priority)}"
        style=${`transform: translate(${this.drag.pointerX + 12}px, ${this.drag.pointerY + 12}px)`}
      >
        ${this.renderIcon(this.dragKindIcon(this.drag.kind))}
        <span class="cal-chip-label">${todo.text}</span>
      </div>
    `;
  }

  private renderDayTimedEvent(
    todo: Todo,
    startMin: number,
    endMin: number,
    opts: {preview?: boolean; source?: boolean} = {},
  ) {
    const top = (startMin / (DAY_HOURS * 60)) * 100;
    const height = Math.max(
      ((endMin - startMin) / (DAY_HOURS * 60)) * 100,
      (30 / (DAY_HOURS * 60)) * 100,
    );
    const canDrag = !this.isReadonly && !opts.preview;
    const bounds = {startMin, endMin};
    const timeLabel = `${formatMinutesLabel(startMin)} – ${formatMinutesLabel(endMin)}`;
    const eventButton = html`
      <button
        type="button"
        class="cal-day-event ${priorityTone(todo.priority)}"
        data-done=${todo.done ? "true" : "false"}
        data-preview=${opts.preview ? "true" : "false"}
        data-source=${opts.source ? "true" : "false"}
        title=${`${todo.text} (${timeLabel})`}
        @pointerdown=${(event: PointerEvent) => {
          if (!canDrag) return;
          this.beginDrag("move", todo, this.anchor, event, startMin, bounds);
        }}
        @click=${(event: Event) => {
          if (opts.preview || canDrag) {
            event.preventDefault();
            event.stopPropagation();
          }
        }}
      >
        ${canDrag
          ? html`
              <span
                class="cal-day-handle cal-day-handle-start"
                title=${tx("calendar.drag.resize_start")}
                @pointerdown=${(event: PointerEvent) =>
                  this.beginDrag(
                    "resize-start",
                    todo,
                    this.anchor,
                    event,
                    startMin,
                    bounds,
                  )}
              >
                <span class="cal-day-handle-bar" aria-hidden="true"></span>
              </span>
            `
          : nothing}
        <span class="cal-day-event-time">${timeLabel}</span>
        <span class="cal-day-event-title">${todo.text}</span>
        ${canDrag
          ? html`
              <span
                class="cal-day-handle cal-day-handle-end"
                title=${tx("calendar.drag.resize_end")}
                @pointerdown=${(event: PointerEvent) =>
                  this.beginDrag(
                    "resize-end",
                    todo,
                    this.anchor,
                    event,
                    endMin,
                    bounds,
                  )}
              >
                <span class="cal-day-handle-bar" aria-hidden="true"></span>
              </span>
            `
          : nothing}
      </button>
    `;
    if (opts.preview) {
      return html`
        <div
          class="cal-day-event-pop"
          style=${`top:${top}%;height:${height}%`}
        >
          ${eventButton}
        </div>
      `;
    }
    return html`
      <sonic-pop
        class="cal-day-event-pop"
        placement="bottom"
        style=${`top:${top}%;height:${height}%`}
      >
        ${eventButton} ${this.renderTodoActionsMenu(todo)}
      </sonic-pop>
    `;
  }

  private renderDayView() {
    const {allDay, timed} = partitionDayTodos(this.filtered, this.anchor);
    const now = new Date();
    const isToday = this.anchor === todayDateOnly(now);
    const nowMin = isToday ? now.getHours() * 60 + now.getMinutes() : null;

    const timeDrag = this.drag;
    const dragTimed =
      timeDrag?.moved &&
      timeDrag.originMinutes !== null &&
      timeDrag.currentMinutes !== null &&
      timeDrag.originStartMin !== null &&
      timeDrag.originEndMin !== null
        ? this.todos.find((item) => item.id === timeDrag.todoId)
        : null;
    const previewBounds =
      dragTimed &&
      timeDrag &&
      timeDrag.currentMinutes !== null &&
      timeDrag.originStartMin !== null &&
      timeDrag.originEndMin !== null
        ? previewTimedDragMinutes(
            timeDrag.kind,
            timeDrag.originStartMin,
            timeDrag.originEndMin,
            timeDrag.currentMinutes,
          )
        : null;

    const isEmpty = allDay.length === 0 && timed.length === 0;

    return html`
      <div class="cal-day" data-day=${this.anchor}>
        ${isEmpty
          ? html`
              <div class="rounded border border-dashed border-neutral-300 px-3 py-2">
                <p class="text-sm text-neutral-600">${t("calendar.empty")}</p>
                <p class="mt-0.5 text-xs text-neutral-500">
                  ${t("calendar.empty_hint")}
                </p>
              </div>
            `
          : nothing}
        ${allDay.length > 0
          ? html`
              <div class="cal-allday">
                <div class="cal-allday-label">${t("calendar.all_day")}</div>
                ${allDay.map((item) =>
                  this.renderChip(item.todo, this.anchor, true),
                )}
              </div>
            `
          : nothing}
        <div class="cal-day-scroll">
          <div class="cal-day-grid">
            <div class="cal-day-hours" aria-hidden="true">
              ${Array.from({length: DAY_HOURS}, (_, hour) => {
                return html`
                  <div class="cal-day-hour">
                    ${hour === 0 ? "" : formatMinutesLabel(hour * 60)}
                  </div>
                `;
              })}
            </div>
            <div class="cal-day-lanes">
              ${Array.from(
                {length: DAY_HOURS},
                (_, hour) => html`
                  <div
                    class="cal-day-line"
                    style=${`top: ${(hour / DAY_HOURS) * 100}%`}
                  ></div>
                  <button
                    type="button"
                    class="cal-day-slot"
                    style=${`top: ${(hour / DAY_HOURS) * 100}%; height: ${100 / DAY_HOURS}%`}
                    title=${this.isReadonly
                      ? ""
                      : tf("calendar.create_hour_hint", {
                          time: formatMinutesLabel(hour * 60),
                        })}
                    ?disabled=${this.isReadonly}
                    @click=${(event: Event) => {
                      event.stopPropagation();
                      this.onActivate(
                        `hour:${this.anchor}:${hour}`,
                        () => undefined,
                        () => this.createTodoAtHour(hour),
                      );
                    }}
                  ></button>
                `,
              )}
              ${nowMin !== null
                ? html`<div
                    class="cal-day-now"
                    style=${`top: ${(nowMin / (DAY_HOURS * 60)) * 100}%`}
                  ></div>`
                : nothing}
              ${timed.map((item) => {
                const isDragged = Boolean(
                  this.drag?.moved &&
                    this.drag.todoId === item.todo.id &&
                    this.drag.originMinutes !== null,
                );
                return this.renderDayTimedEvent(
                  item.todo,
                  item.startMin,
                  item.endMin,
                  {source: isDragged},
                );
              })}
              ${dragTimed && previewBounds
                ? this.renderDayTimedEvent(
                    dragTimed,
                    previewBounds.startMin,
                    previewBounds.endMin,
                    {preview: true},
                  )
                : nothing}
            </div>
          </div>
        </div>
      </div>
    `;
  }

  private renderWeekOrMonth(kind: "week" | "month") {
    const days =
      kind === "week"
        ? (() => {
            const {start} = weekRangeContaining(this.anchor);
            return Array.from({length: 7}, (_, i) => addDays(start, i));
          })()
        : monthGridDays(this.anchor);
    const month = monthRangeContaining(this.anchor);
    const today = todayDateOnly();
    const dropDay = this.drag?.moved ? this.drag.currentDay : null;
    const previewSpan =
      this.drag?.moved && this.drag
        ? this.previewSpanForDrag(this.drag)
        : null;
    const dragTodo =
      this.drag?.moved && this.drag
        ? this.todos.find((item) => item.id === this.drag!.todoId)
        : null;
    const labels = weekdayLabels(true);

    return html`
      <div class=${kind === "week" ? "cal-grid-week" : "cal-grid-month"}>
        ${labels.map(
          (label) => html`
            <div
              class="px-1 pb-1 text-[0.65rem] font-medium uppercase tracking-wide text-neutral-500"
            >
              ${label}
            </div>
          `,
        )}
        ${days.map((day) => {
          const inMonth = kind === "week" || sameMonth(day, month.start);
          const items = todosForDay(this.filtered, day);
          const visible =
            kind === "month" ? items.slice(0, MONTH_CHIP_LIMIT) : items;
          const overflow =
            kind === "month" ? Math.max(0, items.length - MONTH_CHIP_LIMIT) : 0;
          const isDrop = dropDay === day;
          const isPreview = this.dayInSpan(day, previewSpan);
          const showPreviewChip =
            Boolean(dragTodo && isPreview) &&
            !items.some((item) => item.id === dragTodo!.id);
          return html`
            <div
              class="cal-cell"
              data-day=${day}
              data-muted=${inMonth ? "false" : "true"}
              data-today=${day === today ? "true" : "false"}
              data-anchor=${day === this.anchor ? "true" : "false"}
              data-preview=${isPreview ? "true" : "false"}
              data-drop=${isDrop ? "true" : "false"}
              title=${this.isReadonly ? "" : tx("calendar.create_day_hint")}
              @click=${() =>
                this.onActivate(
                  `day:${day}`,
                  () => this.focusDay(day),
                  () => this.createTodoForDay(day),
                )}
            >
              <div class="flex items-center justify-between px-0.5">
                <span class="cal-day-num text-xs font-semibold"
                  >${dayOfMonth(day)}</span
                >
                ${isDrop
                  ? html`<span class="text-[0.6rem] font-medium text-[var(--sc-primary,#2563eb)]"
                      >${this.renderIcon(this.dragKindIcon(this.drag!.kind))}</span
                    >`
                  : items.length > 0
                    ? html`<span class="text-[0.6rem] text-neutral-400"
                        >${items.length}</span
                      >`
                    : nothing}
              </div>
              ${visible.map((todo) =>
                this.renderChip(todo, day, true, {inPreviewSpan: isPreview}),
              )}
              ${showPreviewChip && dragTodo
                ? this.renderChip(dragTodo, day, false, {
                    preview: true,
                    inPreviewSpan: true,
                  })
                : nothing}
              ${overflow > 0
                ? html`
                    <button
                      type="button"
                      class="mt-0.5 w-full text-left text-[0.65rem] text-neutral-500"
                      @click=${(event: Event) => {
                        event.stopPropagation();
                        this.openDay(day);
                      }}
                    >
                      ${tf("calendar.more", {n: overflow})}
                    </button>
                  `
                : nothing}
            </div>
          `;
        })}
      </div>
    `;
  }

  private renderYearView() {
    const year = Number(formatYearTitle(this.anchor));
    const {start, end} = yearRangeContaining(this.anchor);
    const yearTodos = todosForRange(this.filtered, start, end);
    const today = todayDateOnly();

    return html`
      <div class="cal-grid-year">
        ${Array.from({length: 12}, (_, monthIndex0) => {
          const monthStart = `${year}-${String(monthIndex0 + 1).padStart(2, "0")}-01`;
          const range = monthRangeContaining(monthStart);
          const count = todosForRange(yearTodos, range.start, range.end).length;
          const isCurrent = today.startsWith(
            `${year}-${String(monthIndex0 + 1).padStart(2, "0")}`,
          );
          const monthKey = `${year}-${String(monthIndex0 + 1).padStart(2, "0")}`;
          const isAnchor = this.anchor.startsWith(monthKey);
          return html`
            <button
              type="button"
              class="cal-year-month rounded border border-neutral-200 p-3 text-left hover:bg-neutral-50 ${isCurrent
                ? "outline outline-2 outline-offset-[-2px]"
                : ""}"
              data-anchor=${isAnchor ? "true" : "false"}
              title=${tx("calendar.open_month_hint")}
              @click=${() =>
                this.onActivate(
                  `month:${year}:${monthIndex0}`,
                  () => this.focusMonth(year, monthIndex0),
                  () => this.openMonth(year, monthIndex0),
                )}
            >
              <div class="text-sm font-semibold">${monthShortName(monthIndex0)}</div>
              <div class="mt-1 text-xs text-neutral-500">
                ${tf("calendar.task_count", {n: count})}
              </div>
            </button>
          `;
        })}
      </div>
    `;
  }

  private renderBody() {
    if (this.loading) {
      return html`<p class="text-sm text-neutral-500">${t("common.loading")}</p>`;
    }
    if (this.mode === "day") return this.renderDayView();
    if (this.mode === "week") return this.renderWeekOrMonth("week");
    if (this.mode === "year") return this.renderYearView();
    return this.renderWeekOrMonth("month");
  }

  render() {
    return html`
      <div class="flex flex-col gap-3">
        ${this.renderToolbar()}
        ${this.renderBody()}
        ${this.renderDragGhost()}
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tasks-calendar": TasksCalendar;
  }
}
