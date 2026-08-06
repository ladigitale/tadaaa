import "@supersoniks/concorde/checkbox";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {
  patch,
  post,
  subscribe,
  type ApiResult,
} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  apiResultError,
  endpoints,
  readApiData,
  type ApiData,
} from "../api/endpoints";
import {todoCopyInput} from "../api/todos-query";
import type {Tag, Todo, TodoPriority, UpdateTodoPatch} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {tagsListKey, todosDoneKey} from "../dp";
import {bumpTodosRev} from "../init";
import {tf, tx} from "../i18n";
import {rmLinksLabelHtml, richTextTemplate} from "./rm-link-text";
import tailwind from "../../css/tailwind";
import {confirmDialog, showError} from "../utils/modal-dialog";
import "./tag-badge";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tacheItemEditPath, tacheItemMovePath, tacheItemPath} from "../utils/tache-paths";
import {formatTodoDateLabel} from "../utils/dates";
import {parseRecurrence} from "../utils/recurrence";

const PRIORITY_TYPE: Record<
  TodoPriority,
  "default" | "warning" | "danger"
> = {
  low: "default",
  medium: "warning",
  high: "danger",
};

function priorityLabel(priority: TodoPriority): string {
  switch (priority) {
    case "low":
      return tx("tasks.priority.low");
    case "high":
      return tx("tasks.priority.high");
    default:
      return tx("tasks.priority.medium");
  }
}

function normalizeIds(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.map(String).filter(Boolean);
}

function readDoneIds(): string[] {
  return normalizeIds(read(`${todosDoneKey.path}.ids`));
}

function writeDoneIds(ids: string[]) {
  set(`${todosDoneKey.path}.ids`, ids);
}

/**
 * FormCheckable (multi) à l’init : `updateDataValue()` tourne avec `checked=null`
 * et retire sa `value` du tableau partagé — d’où les cases qui se décochent
 * les unes les autres. On ré-aligne *après* le mount, et on ignore les
 * @handle pendant cette fenêtre (connect / hydrate).
 */
@customElement("todo-row")
export class TodoRow extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }

      .rich-text p {
        margin: 0;
      }

      .rich-text p + p,
      .rich-text ul + p,
      .rich-text ol + p,
      .rich-text p + ul,
      .rich-text p + ol {
        margin-top: 0.35em;
      }

      .rich-text ul,
      .rich-text ol {
        margin: 0.25em 0 0;
        padding-left: 1.1rem;
      }

      .rich-text code {
        font-size: 0.9em;
        padding: 0.05em 0.3em;
        border-radius: 0.25rem;
        background: color-mix(in srgb, currentColor 12%, transparent);
      }

      .rich-text a {
        color: rgb(37 99 235);
        text-decoration: underline;
        text-underline-offset: 2px;
      }
    `,
  ];

  @property({attribute: false})
  todo!: Todo;

  /** Résolu pour `@patch` / DataProviderKey dynamique. */
  @property({type: String})
  todoId = "";

  @property({attribute: false})
  tags: Tag[] = [];

  @subscribe(tagsListKey)
  @state()
  tagsList: Tag[] = [];

  /** Ids cochés (multi FormCheckable partagé — `todosDone.ids`). */
  @subscribe(todosDoneKey.ids)
  @state()
  doneIds: string[] = [];

  @state()
  private busy = false;

  @patch(endpoints.todos.patch, endpoints.keys.submit.todoItemPatch, {
    skipEmptyPlaceholder: true,
  })
  @state()
  patchPayload: ApiResult<ApiData<Todo>> | null = null;

  @post(endpoints.todos.collection, endpoints.keys.submit.todoCopy, {
    skipEmptyPlaceholder: true,
  })
  @state()
  copyPayload: ApiResult<ApiData<Todo>> | null = null;

  private pendingSubmit: "done" | "archive" | null = null;
  private pendingCopy = false;
  private doneSnapshot: Todo | null = null;

  private get isDone(): boolean {
    return normalizeIds(this.doneIds).includes(this.todo?.id);
  }

  private get allTags(): Tag[] {
    return this.tagsList.length > 0 ? this.tagsList : this.tags;
  }

  private get assignedTags(): Tag[] {
    return this.allTags.filter((tag) => this.todo.tagIds.includes(tag.id));
  }

  private get priorityMeta() {
    const priority = this.todo.priority ?? "medium";
    return {
      label: priorityLabel(priority),
      type: PRIORITY_TYPE[priority],
    };
  }

  private get childrenHref(): string {
    return tacheItemPath(this.todo.id);
  }

  private get childCount(): number {
    return this.todo.childCount ?? 0;
  }

  private get subtasksLabel(): string {
    return tf("tasks.subtasks", {n: this.childCount});
  }

  private renderChildCountBadge(options?: {slot?: string; absolute?: boolean}) {
    if (this.childCount <= 0) return nothing;
    return html`
      <sonic-tooltip
        label=${this.subtasksLabel}
        placement="bottom"
        slot=${options?.slot ?? nothing}
        class=${options?.absolute
          ? "absolute right-1 bottom-1 translate-x-1/2 translate-y-1/2 transform"
          : "inline-block"}
      >
        <sonic-badge type="neutral" size="xs"
          >${this.childCount}</sonic-badge
        >
      </sonic-tooltip>
    `;
  }

  connectedCallback() {
    // Garantit un tableau : sinon le 1er initPublisher FormCheckable écrit `[]`
    // et efface toutes les autres values déjà présentes.
    if (!Array.isArray(read(`${todosDoneKey.path}.ids`))) {
      set(todosDoneKey.path, {ids: []});
    }
    super.connectedCallback();
  }

  protected willUpdate(_changed: Map<string, unknown>) {
    if (this.todo?.id && this.todoId !== this.todo.id) {
      this.todoId = this.todo.id;
    }
  }

  protected updated(changed: Map<string, unknown>) {
    if (this.todo?.id && changed.has("todo")) {
      // FormCheckable initPublisher tourne avec checked=null et retire sa value
      // du tableau partagé. On ré-aligne *après* le mount de la checkbox.
      this.hydrateDoneForm();
      queueMicrotask(() => this.hydrateDoneForm());
    }

    if (changed.has("patchPayload") && this.pendingSubmit) {
      void this.finishPatch();
    }
    if (changed.has("copyPayload") && this.pendingCopy) {
      void this.finishCopy();
    }
  }

  private async finishPatch() {
    const kind = this.pendingSubmit;
    const todoId = this.todoId;
    this.pendingSubmit = null;
    if (todoId) set(endpoints.keys.paths.todoItemPatch(todoId), null);

    const updated = readApiData(this.patchPayload);
    if (!updated) {
      if (kind === "done" && this.doneSnapshot) {
        this.todo = this.doneSnapshot;
        writeDoneIds(
          this.doneSnapshot.done
            ? [
                ...readDoneIds().filter((id) => id !== this.doneSnapshot!.id),
                this.doneSnapshot.id,
              ]
            : readDoneIds().filter((id) => id !== this.doneSnapshot!.id),
        );
      }
      this.doneSnapshot = null;
      await showError(apiResultError(this.patchPayload));
      this.busy = false;
      return;
    }

    this.doneSnapshot = null;
    this.todo = {...this.todo, ...updated};
    if (kind === "archive") {
      bumpTodosRev();
    }
    this.busy = false;
  }

  private queuePatch(kind: "done" | "archive", patch: UpdateTodoPatch) {
    if (!this.todoId || this.busy) return;
    this.busy = true;
    this.pendingSubmit = kind;
    set(endpoints.keys.paths.todoItemPatch(this.todoId), patch);
  }

  /**
   * Aligne `todosDone.ids` sur `todo.done` (merge, sans écraser les autres ids).
   */
  private hydrateDoneForm() {
    if (!this.todo?.id) return;
    const ids = readDoneIds();
    const has = ids.includes(this.todo.id);
    if (this.todo.done === has) return;

    writeDoneIds(
      this.todo.done
        ? [...ids, this.todo.id]
        : ids.filter((id) => id !== this.todo.id),
    );
  }

  /**
   * Persist sur geste utilisateur uniquement (`change`), pas sur le churn
   * d’init / disconnect FormCheckable (qui retire puis remet les ids).
   */
  private onDoneCheckboxChange = () => {
    if (!this.todo || this.busy) return;
    const nextDone = readDoneIds().includes(this.todo.id);
    if (this.todo.done === nextDone) return;
    void this.persistDone(nextDone);
  };

  private persistDone(nextDone: boolean) {
    if (!this.todoId) return;
    this.doneSnapshot = this.todo;
    this.todo = {...this.todo, done: nextDone};
    // Pas de bump liste : évite de perdre le scroll (filtre inchangé).
    this.queuePatch("done", {done: nextDone});
  }

  private onCopy = () => {
    if (!this.todo || !this.todoId || this.todo.archived || this.busy) return;
    this.busy = true;
    this.pendingCopy = true;
    set(endpoints.keys.paths.todoCopy(this.todoId), todoCopyInput(this.todo));
  };

  private async finishCopy() {
    this.pendingCopy = false;
    if (this.todoId) set(endpoints.keys.paths.todoCopy(this.todoId), null);
    const created = readApiData(this.copyPayload);
    if (!created) {
      await showError(apiResultError(this.copyPayload));
      this.busy = false;
      return;
    }
    bumpTodosRev();
    this.busy = false;
  }

  private onDeleteToggle = async () => {
    if (!this.todo || this.busy) return;
    const deleting = !this.todo.archived;
    if (deleting) {
      const ok = await confirmDialog({
        title: tx("tasks.delete_title"),
        message: tx("tasks.delete_confirm"),
        confirmLabel: tx("tasks.delete"),
        danger: true,
      });
      if (!ok) return;
    }

    this.queuePatch("archive", {archived: !this.todo.archived});
  };

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

  private renderSeeButton() {
    if (this.todo.archived) return nothing;

    return html`
      <div class="relative inline-block">
        <sonic-button
          href=${this.childrenHref}
          pushstate
          shape="circle"
          size="sm"
          ?disabled=${this.busy}
          data-aria-label=${tx("tasks.see")}
        >
          <sonic-icon
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="eye"
            size="lg"
          ></sonic-icon>
        </sonic-button>
        ${this.renderChildCountBadge({absolute: true})}
      </div>
    `;
  }

  private renderActionsMenu() {
    return html`
      <sonic-pop class="inline-block" placement="bottom">
        <sonic-tooltip label=${tx("common.actions")} placement="bottom">
          <sonic-button
            shape="circle"
            size="sm"
            variant="ghost"
            ?disabled=${this.busy}
            data-aria-label=${tx("common.actions")}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="more-vert"
              size="lg"
            ></sonic-icon>
          </sonic-button>
        </sonic-tooltip>

        <sonic-menu
          slot="content"
          direction="column"
          align="left"
          size="sm"
          minWidth="12rem"
        >
          ${!this.todo.archived
            ? html`
                <sonic-menu-item
                  href=${this.childrenHref}
                  pushstate
                  ?disabled=${this.busy}
                >
                  ${this.renderMenuItemIcon("eye")}
                  ${t("tasks.see")}
                  ${this.renderChildCountBadge({slot: "suffix"})}
                </sonic-menu-item>
                <sonic-menu-item
                  href=${tacheItemEditPath(this.todo.id)}
                  pushstate
                  ?disabled=${this.busy}
                >
                  ${this.renderMenuItemIcon("edit-pencil")} ${t("tasks.edit")}
                </sonic-menu-item>
                <sonic-menu-item
                  href=${tacheItemMovePath(this.todo.id)}
                  pushstate
                  ?disabled=${this.busy}
                >
                  ${this.renderMenuItemIcon("data-transfer-both")}
                  ${t("tasks.move")}
                </sonic-menu-item>
                <sonic-menu-item
                  ?disabled=${this.busy}
                  @click=${this.onCopy}
                >
                  ${this.renderMenuItemIcon("copy")} ${t("tasks.copy")}
                </sonic-menu-item>
                <sonic-menu-item
                  type="danger"
                  ?disabled=${this.busy}
                  @click=${this.onDeleteToggle}
                >
                  ${this.renderMenuItemIcon("trash")} ${t("tasks.delete")}
                </sonic-menu-item>
              `
            : html`
                <sonic-menu-item
                  ?disabled=${this.busy}
                  @click=${this.onDeleteToggle}
                >
                  ${this.renderMenuItemIcon("undo")} ${t("tasks.restore")}
                </sonic-menu-item>
              `}
        </sonic-menu>
      </sonic-pop>
    `;
  }

  private get description(): string {
    return this.todo.description?.trim() ?? "";
  }

  private renderMeta() {
    const priority = this.priorityMeta;
    const startLabel = this.todo.startAt
      ? formatTodoDateLabel(this.todo.startAt)
      : "";
    const endLabel = this.todo.endAt
      ? formatTodoDateLabel(this.todo.endAt)
      : "";
    const dateLabel =
      startLabel && endLabel && startLabel !== endLabel
        ? `${startLabel} → ${endLabel}`
        : startLabel || endLabel || null;
    const recurrence = parseRecurrence(this.todo.recurrence);
    const recurrenceLabel =
      recurrence === "none"
        ? null
        : tx(`tasks.recurrence.${recurrence}`);
    return html`
      <div class="flex flex-wrap items-center gap-1.5">
        <sonic-badge type=${priority.type} size="2xs">
          ${priority.label}
        </sonic-badge>
        ${recurrenceLabel
          ? html`<sonic-badge type="info" size="2xs"
              >${recurrenceLabel}</sonic-badge
            >`
          : nothing}
        ${dateLabel
          ? html`<span class="text-[0.7rem] text-neutral-500">${dateLabel}</span>`
          : nothing}
        ${this.assignedTags.map(
          (tag) => html`
            <tag-badge
              .tag=${tag}
              size="2xs"
              ?disabled=${this.busy || this.todo.archived}
            ></tag-badge>
          `,
        )}
      </div>
    `;
  }

  private renderBody() {
    const struck = this.todo.archived || this.isDone;
    return html`
      <div class="min-w-0 flex-1" formDataProvider=${todosDoneKey.path}>
        <sonic-checkbox
          class="mt-0.5 min-w-0 text-sm leading-snug sm:text-base ${struck
            ? "text-neutral-400 line-through"
            : "text-neutral-900"}"
          name="ids"
          value=${this.todo.id}
          .label=${rmLinksLabelHtml(this.todo.text)}
          ?disabled=${this.busy || this.todo.archived}
          @change=${this.onDoneCheckboxChange}
        ></sonic-checkbox>
        ${this.description
          ? html`
              <div
                class="rich-text mt-0.5 pl-7 text-xs leading-snug text-neutral-500 sm:text-sm"
              >
                ${richTextTemplate(this.description)}
              </div>
            `
          : nothing}
        <div class="mt-0.5 pl-7">${this.renderMeta()}</div>
      </div>
    `;
  }

  render() {
    if (!this.todo) return nothing;

    return html`
      <article
        class="py-4 sm:py-5 ${this.todo.archived ? "opacity-60" : ""}"
      >
        <div class="flex items-start gap-2 sm:gap-3">
          ${this.renderBody()}

          <div class="flex shrink-0 items-center gap-0.5">
            ${this.renderSeeButton()} ${this.renderActionsMenu()}
          </div>
        </div>
      </article>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "todo-row": TodoRow;
  }
}
