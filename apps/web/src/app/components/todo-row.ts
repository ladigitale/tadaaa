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
import {subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {patchTodo} from "../api/client";
import type {Tag, Todo, TodoPriority} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {tagsListKey, todosDoneKey} from "../dp";
import {tf, tx} from "../i18n";
import {rmLinksLabelHtml, richTextTemplate} from "./rm-link-text";
import tailwind from "../../css/tailwind";
import {confirmDialog, showError} from "../utils/modal-dialog";
import "./tag-badge";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tacheItemEditPath, tacheItemMovePath, tacheItemPath} from "../utils/tache-paths";
import {todoDateSpan, localeTag} from "../utils/dates";

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

function formatShortDate(dateOnly: string): string {
  const [y, m, d] = dateOnly.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString(localeTag(), {
    day: "numeric",
    month: "short",
  });
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

  connectedCallback() {
    // Garantit un tableau : sinon le 1er initPublisher FormCheckable écrit `[]`
    // et efface toutes les autres values déjà présentes.
    if (!Array.isArray(read(`${todosDoneKey.path}.ids`))) {
      set(todosDoneKey.path, {ids: []});
    }
    super.connectedCallback();
  }

  protected updated(changed: Map<string, unknown>) {
    if (!this.todo?.id || !changed.has("todo")) return;

    // FormCheckable initPublisher tourne avec checked=null et retire sa value
    // du tableau partagé. On ré-aligne *après* le mount de la checkbox.
    this.hydrateDoneForm();
    queueMicrotask(() => this.hydrateDoneForm());
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

  private async persistDone(nextDone: boolean) {
    const previous = this.todo;
    this.busy = true;
    this.todo = {...previous, done: nextDone};
    try {
      // Pas de refresh liste : évite de perdre le scroll (filtre inchangé).
      await patchTodo(previous.id, {done: nextDone}, {refreshList: false});
    } catch (error) {
      this.todo = previous;
      writeDoneIds(
        previous.done
          ? [...readDoneIds().filter((id) => id !== previous.id), previous.id]
          : readDoneIds().filter((id) => id !== previous.id),
      );
      await showError(error);
      console.error(error);
    } finally {
      this.busy = false;
    }
  }

  private async runAction(action: () => Promise<void>) {
    if (this.busy) return;
    this.busy = true;
    try {
      await action();
    } catch (error) {
      await showError(error);
      console.error(error);
    } finally {
      this.busy = false;
    }
  }

  private onDeleteToggle = async () => {
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

    void this.runAction(async () => {
      await patchTodo(this.todo.id, {archived: !this.todo.archived});
    });
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

  private get childrenLabel(): string {
    return tf("tasks.subtasks", {n: this.childCount});
  }

  private renderChildrenButton() {
    if (this.todo.archived) return nothing;

    return html`
      <sonic-button
        href=${this.childrenHref}
        pushstate
        size="sm"
        variant="ghost"
        ?disabled=${this.busy}
      >
        <sonic-icon
          library=${ICON_LIBRARY}
          prefix=${ICON_PREFIX}
          name="list"
          size="sm"
        ></sonic-icon>
        ${this.childrenLabel}
      </sonic-button>
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
                  ${this.renderMenuItemIcon("list")}
                  ${this.childrenLabel}
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
    const span = todoDateSpan(this.todo);
    const dateLabel = span
      ? span.start === span.end
        ? formatShortDate(span.start)
        : `${formatShortDate(span.start)} → ${formatShortDate(span.end)}`
      : null;
    return html`
      <div class="flex flex-wrap items-center gap-1.5">
        <sonic-badge type=${priority.type} size="2xs">
          ${priority.label}
        </sonic-badge>
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
            ${this.renderChildrenButton()} ${this.renderActionsMenu()}
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
