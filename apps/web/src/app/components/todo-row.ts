import "@supersoniks/concorde/checkbox";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {patchTodo} from "../api/client";
import type {Tag, Todo, TodoPriority} from "../api/types";
import {tagsListKey} from "../dp";
import {rmLinksLabelHtml, rmLinksTemplate} from "./rm-link-text";
import tailwind from "../../css/tailwind";
import {confirmDialog, showError} from "../utils/modal-dialog";
import "./tag-badge";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tacheItemEditPath, tacheItemMovePath, tacheItemPath} from "../utils/tache-paths";

const PRIORITY_META: Record<
  TodoPriority,
  {label: string; type: "default" | "warning" | "danger"}
> = {
  low: {label: "Basse", type: "default"},
  medium: {label: "Moyenne", type: "warning"},
  high: {label: "Haute", type: "danger"},
};

@customElement("todo-row")
export class TodoRow extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
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

  @state()
  private busy = false;

  private get allTags(): Tag[] {
    return this.tagsList.length > 0 ? this.tagsList : this.tags;
  }

  private get assignedTags(): Tag[] {
    return this.allTags.filter((tag) => this.todo.tagIds.includes(tag.id));
  }

  private get priorityMeta() {
    return PRIORITY_META[this.todo.priority ?? "medium"];
  }

  private get childrenHref(): string {
    return tacheItemPath(this.todo.id);
  }

  private get childCount(): number {
    return this.todo.childCount ?? 0;
  }

  private async runAction(action: () => Promise<void>) {
    if (this.busy) return;
    this.busy = true;
    try {
      await action();
    } catch (error) {
      await showError(error, "Impossible de modifier la tâche");
      console.error(error);
    } finally {
      this.busy = false;
    }
  }

  private onToggleDone = () => {
    void this.runAction(async () => {
      const previous = this.todo;
      const nextDone = !previous.done;
      this.todo = {...previous, done: nextDone};
      try {
        // Pas de refresh liste : évite de perdre le scroll (filtre inchangé).
        await patchTodo(previous.id, {done: nextDone}, {refreshList: false});
      } catch (error) {
        this.todo = previous;
        throw error;
      }
    });
  };

  private onDeleteToggle = async () => {
    const deleting = !this.todo.archived;
    if (deleting) {
      const ok = await confirmDialog({
        title: "Supprimer la tâche",
        message:
          "Supprimer cette tâche ? Elle restera visible dans le filtre « Supprimés ».",
        confirmLabel: "Supprimer",
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
    const count = this.childCount;
    return count <= 1 ? `${count} sous-tâche` : `${count} sous-tâches`;
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
        <sonic-button
          shape="circle"
          size="sm"
          variant="ghost"
          ?disabled=${this.busy}
          data-aria-label="Actions"
          title="Actions"
        >
          <sonic-icon
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="more-vert"
            size="lg"
          ></sonic-icon>
        </sonic-button>

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
                  ${this.renderMenuItemIcon("edit-pencil")} Modifier
                </sonic-menu-item>
                <sonic-menu-item
                  href=${tacheItemMovePath(this.todo.id)}
                  pushstate
                  ?disabled=${this.busy}
                >
                  ${this.renderMenuItemIcon("data-transfer-both")} Déplacer dans…
                </sonic-menu-item>
                <sonic-menu-item
                  type="danger"
                  ?disabled=${this.busy}
                  @click=${this.onDeleteToggle}
                >
                  ${this.renderMenuItemIcon("trash")} Supprimer
                </sonic-menu-item>
              `
            : html`
                <sonic-menu-item
                  ?disabled=${this.busy}
                  @click=${this.onDeleteToggle}
                >
                  ${this.renderMenuItemIcon("undo")} Restaurer
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
    return html`
      <div class="flex flex-wrap items-center gap-1.5">
        <sonic-badge type=${priority.type} size="2xs">
          ${priority.label}
        </sonic-badge>
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
    return html`
      <div class="min-w-0 flex-1">
        <sonic-checkbox
          class="mt-0.5 min-w-0 text-sm leading-snug sm:text-base ${this.todo
            .archived || this.todo.done
            ? "text-neutral-400 line-through"
            : "text-neutral-900"}"
          .label=${rmLinksLabelHtml(this.todo.text)}
          ?checked=${this.todo.done}
          ?disabled=${this.busy || this.todo.archived}
          @click=${this.onToggleDone}
        ></sonic-checkbox>
        ${this.description
          ? html`
              <p
                class="mt-0.5 pl-7 text-xs leading-snug text-neutral-500 sm:text-sm"
              >
                ${rmLinksTemplate(this.description)}
              </p>
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
