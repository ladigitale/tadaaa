import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing, PropertyValues} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {fetchTodos, patchTodo} from "../api/client";
import type {Todo, TodoPriority, TodoStatusFilter} from "../api/types";
import type {TodosFilter} from "../dp";
import {tf, tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {isActiveDatasetReadonly} from "../sync/cloud-access";
import {navigateTo} from "../utils/navigate";
import {tacheItemPath} from "../utils/tache-paths";
import {
  type CalendarMode,
  filterCalendarTodos,
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
  formatMonthTitle,
  formatWeekTitle,
  formatYearTitle,
  monthGridDays,
  monthRangeContaining,
  monthShortName,
  sameMonth,
  shiftAnchor,
  todayDateOnly,
  todoDateSpan,
  weekRangeContaining,
  weekdayLabels,
  yearRangeContaining,
} from "../utils/dates";
import {showError} from "../utils/modal-dialog";
import tailwind from "../../css/tailwind";

const STORAGE_KEY = "tada-tasks-calendar-view";
const MONTH_CHIP_LIMIT = 3;

type StoredState = {
  mode: CalendarMode;
  anchor: string;
};

type DragKind = "move" | "resize-start" | "resize-end";

type DragState = {
  kind: DragKind;
  todoId: string;
  originDay: string;
  currentDay: string;
  moved: boolean;
};

function loadState(): StoredState {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return {mode: "month", anchor: todayDateOnly()};
    const parsed = JSON.parse(raw) as Partial<StoredState>;
    return {
      mode:
        parsed.mode === "day" ||
        parsed.mode === "week" ||
        parsed.mode === "month" ||
        parsed.mode === "year"
          ? parsed.mode
          : "month",
      anchor: parsed.anchor || todayDateOnly(),
    };
  } catch {
    return {mode: "month", anchor: todayDateOnly()};
  }
}

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
      }

      .cal-cell[data-muted="true"] {
        opacity: 0.45;
      }

      .cal-cell[data-today="true"] {
        outline: 2px solid currentColor;
        outline-offset: -2px;
      }

      .cal-cell[data-drop="true"] {
        background: color-mix(in srgb, currentColor 8%, transparent);
      }

      .cal-chip {
        display: flex;
        align-items: center;
        gap: 0.15rem;
        width: 100%;
        margin-top: 0.15rem;
        padding: 0.1rem 0.25rem;
        border: 1px solid;
        border-radius: 0.25rem;
        font-size: 0.65rem;
        line-height: 1.2;
        cursor: pointer;
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

      .cal-handle {
        flex: 0 0 0.35rem;
        align-self: stretch;
        border-radius: 0.1rem;
        background: color-mix(in srgb, currentColor 35%, transparent);
        cursor: ew-resize;
        touch-action: none;
      }

      .cal-chip-label {
        min-width: 0;
        flex: 1 1 auto;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .cal-chip-tooltip {
        display: block;
        width: 100%;
        min-width: 0;
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

  connectedCallback() {
    super.connectedCallback();
    const stored = loadState();
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
  }

  protected updated(changed: PropertyValues) {
    if (changed.has("filter")) {
      void this.reload();
    }
  }

  private persist() {
    try {
      sessionStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({mode: this.mode, anchor: this.anchor} satisfies StoredState),
      );
    } catch {
      /* ignore */
    }
  }

  private async reload() {
    this.loading = true;
    try {
      const tagIds = Array.isArray(this.filter.tags)
        ? this.filter.tags.filter(Boolean)
        : [];
      const [list, readonly] = await Promise.all([
        fetchTodos({
          status: calendarStatus(this.filter.status),
          q: this.filter.q?.trim() || null,
          tagIds: tagIds.length > 0 ? tagIds : null,
          parentId: this.filter.parentId || "",
          recursive: Boolean(this.filter.recursive),
          sortBy: "startAt",
          sortDir: "asc",
          limit: 5000,
        }),
        isActiveDatasetReadonly(),
      ]);
      this.todos = list.data;
      this.isReadonly = readonly;
    } catch (error) {
      await showError(error);
    } finally {
      this.loading = false;
    }
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

  private openDay(day: string) {
    this.anchor = day;
    this.setMode("day");
  }

  private openMonth(year: number, monthIndex0: number) {
    this.anchor = `${year}-${String(monthIndex0 + 1).padStart(2, "0")}-01`;
    this.setMode("month");
  }

  private openTodo(todo: Todo, event?: Event) {
    event?.stopPropagation();
    if (this.drag) return;
    navigateTo(tacheItemPath(todo.id));
  }

  private onKeyDown = (event: KeyboardEvent) => {
    if (event.key === "Escape" && this.drag) {
      this.drag = null;
    }
  };

  private dayFromPoint(clientX: number, clientY: number): string | null {
    const el = this.shadowRoot?.elementFromPoint(clientX, clientY) as
      | HTMLElement
      | null;
    const cell = el?.closest?.("[data-day]") as HTMLElement | null;
    return cell?.dataset.day ?? null;
  }

  private beginDrag(
    kind: DragKind,
    todo: Todo,
    originDay: string,
    event: PointerEvent,
  ) {
    if (this.isReadonly || this.busyId) return;
    event.preventDefault();
    event.stopPropagation();
    this.drag = {
      kind,
      todoId: todo.id,
      originDay,
      currentDay: originDay,
      moved: false,
    };
  }

  private onPointerMove = (event: PointerEvent) => {
    if (!this.drag) return;
    const day = this.dayFromPoint(event.clientX, event.clientY);
    if (day && day !== this.drag.currentDay) {
      this.drag = {...this.drag, currentDay: day, moved: true};
    }
  };

  private onPointerUp = () => {
    if (!this.drag) return;
    const drag = this.drag;
    this.drag = null;
    if (!drag.moved) {
      if (drag.kind === "move") {
        const todo = this.todos.find((item) => item.id === drag.todoId);
        if (todo) navigateTo(tacheItemPath(todo.id));
      }
      return;
    }
    void this.applyDrag(drag);
  };

  private async applyDrag(drag: DragState) {
    const todo = this.todos.find((item) => item.id === drag.todoId);
    if (!todo || drag.currentDay === drag.originDay) return;

    let next: {startAt: string; endAt: string} | null = null;
    if (drag.kind === "move") {
      next = shiftTodoDates(todo, daysBetween(drag.originDay, drag.currentDay));
    } else if (drag.kind === "resize-start") {
      next = resizeTodoDates(todo, "start", drag.currentDay);
    } else {
      next = resizeTodoDates(todo, "end", drag.currentDay);
    }
    if (!next) return;

    this.busyId = todo.id;
    try {
      const updated = await patchTodo(todo.id, {
        startAt: next.startAt,
        endAt: next.endAt,
      });
      this.todos = this.todos.map((item) =>
        item.id === updated.id ? {...item, ...updated} : item,
      );
    } catch (error) {
      await showError(error);
    } finally {
      this.busyId = null;
    }
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
          <div class="flex flex-wrap items-center gap-2">
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

  private renderChip(todo: Todo, day: string, showHandles = false) {
    const span = todoDateSpan(todo);
    const isStart = span?.start === day;
    const isEnd = span?.end === day;
    const multi = Boolean(span && span.start !== span.end);
    return html`
      <sonic-tooltip
        class="cal-chip-tooltip"
        label=${todo.text}
        placement="top"
      >
        <button
          type="button"
          class="cal-chip ${priorityTone(todo.priority)}"
          data-done=${todo.done ? "true" : "false"}
          @pointerdown=${(event: PointerEvent) => {
            if (!showHandles || this.isReadonly) return;
            this.beginDrag("move", todo, day, event);
          }}
          @click=${(event: Event) => {
            if (showHandles && !this.isReadonly) {
              event.preventDefault();
              event.stopPropagation();
              return;
            }
            this.openTodo(todo, event);
          }}
        >
          ${showHandles && multi && isStart && !this.isReadonly
            ? html`
                <span
                  class="cal-handle"
                  @pointerdown=${(event: PointerEvent) =>
                    this.beginDrag("resize-start", todo, day, event)}
                ></span>
              `
            : nothing}
          <span class="cal-chip-label">${todo.text}</span>
          ${showHandles && multi && isEnd && !this.isReadonly
            ? html`
                <span
                  class="cal-handle"
                  @pointerdown=${(event: PointerEvent) =>
                    this.beginDrag("resize-end", todo, day, event)}
                ></span>
              `
            : nothing}
        </button>
      </sonic-tooltip>
    `;
  }

  private renderDayView() {
    const items = todosForDay(this.filtered, this.anchor);
    if (items.length === 0) {
      return html`
        <div class="rounded border border-dashed border-neutral-300 p-6 text-center">
          <p class="text-sm text-neutral-600">${t("calendar.empty")}</p>
          <p class="mt-1 text-xs text-neutral-500">${t("calendar.empty_hint")}</p>
        </div>
      `;
    }
    return html`
      <ul class="divide-y divide-neutral-200">
        ${items.map(
          (todo) => html`
            <li class="py-3">
              <button
                type="button"
                class="flex w-full items-start gap-2 text-left"
                @click=${() => this.openTodo(todo)}
              >
                <span
                  class="mt-0.5 inline-block h-2.5 w-2.5 shrink-0 rounded-full border ${priorityTone(
                    todo.priority,
                  )}"
                ></span>
                <span class="min-w-0 flex-1">
                  <span
                    class="block text-sm font-medium ${todo.done
                      ? "text-neutral-400 line-through"
                      : "text-neutral-900"}"
                    >${todo.text}</span
                  >
                </span>
              </button>
            </li>
          `,
        )}
      </ul>
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
    const dropDay = this.drag?.currentDay ?? null;
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
          return html`
            <div
              class="cal-cell"
              data-day=${day}
              data-muted=${inMonth ? "false" : "true"}
              data-today=${day === today ? "true" : "false"}
              data-drop=${dropDay === day ? "true" : "false"}
              @click=${() => this.openDay(day)}
            >
              <div class="flex items-center justify-between px-0.5">
                <span class="text-xs font-semibold">${dayOfMonth(day)}</span>
                ${items.length > 0
                  ? html`<span class="text-[0.6rem] text-neutral-400"
                      >${items.length}</span
                    >`
                  : nothing}
              </div>
              ${visible.map((todo) => this.renderChip(todo, day, true))}
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
          return html`
            <button
              type="button"
              class="rounded border border-neutral-200 p-3 text-left hover:bg-neutral-50 ${isCurrent
                ? "outline outline-2 outline-offset-[-2px]"
                : ""}"
              @click=${() => this.openMonth(year, monthIndex0)}
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
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tasks-calendar": TasksCalendar;
  }
}
