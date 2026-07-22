import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {fetchTodo, patchTodo} from "../api/client";
import type {Todo, TodoAncestor} from "../api/types";
import {
  TACHE_ROOT,
  tacheItemEditPath,
  tacheItemMovePath,
  tacheItemNewPath,
  tacheItemPath,
  tacheNewPath,
} from "../utils/tache-paths";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {navigateTo} from "../utils/navigate";
import tailwind from "../../css/tailwind";
import "./task-breadcrumb";
import {rmLinksTemplate} from "./rm-link-text";

/** Action affichée sous le titre (contexte de la page). */
export type TaskScopeAction = "" | "children" | "create" | "edit" | "move";

type ActionChoice = {
  id: TaskScopeAction;
  label: string;
  icon: string;
  href: string;
};

/**
 * En-tête de contexte tâche : fil d’Ariane, titre, menu d’action, description.
 */
@customElement("task-scope-header")
export class TaskScopeHeader extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }

      .scope-heading {
        font-size: 1.75rem;
        font-weight: 600;
        line-height: 1.15;
        letter-spacing: -0.02em;
        color: var(--sc-base-content);
      }

      @media (min-width: 640px) {
        .scope-heading {
          font-size: 2.25rem;
        }
      }

      .scope-action-trigger {
        font-style: italic;
        letter-spacing: 0.01em;
      }

      .scope-description {
        max-width: 40rem;
        font-size: 0.875rem;
        line-height: 1.45;
        color: var(--sc-base-600);
      }
    `,
  ];

  /**
   * Id de la tâche « courante », ou vide = racine.
   * Liste / créer : parent (ou vide). Modifier / déplacer : id de la tâche.
   */
  @property({type: String})
  scopeId = "";

  /**
   * Action de page :
   * - children → « X sous-tâche(s) »
   * - create → « Nouvelle tâche » / « Nouvelle sous-tâche »
   * - edit → « Modifier »
   * - move → « Déplacer dans… »
   */
  @property()
  action: TaskScopeAction = "";

  @state()
  private scopeTodo: Todo | null = null;

  @state()
  private ancestors: TodoAncestor[] = [];

  @state()
  private busy = false;

  connectedCallback() {
    super.connectedCallback();
    void this.syncScope();
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("scopeId")) {
      void this.syncScope();
    }
  }

  private get isScoped(): boolean {
    return Boolean(this.scopeId?.trim());
  }

  private get heading(): string {
    if (!this.isScoped) return "Tâches principales";
    return this.scopeTodo?.text?.trim() || "…";
  }

  private get description(): string {
    return this.scopeTodo?.description?.trim() || "";
  }

  private get isArchived(): boolean {
    return Boolean(this.scopeTodo?.archived);
  }

  private get actionChoices(): ActionChoice[] {
    const id = this.scopeId?.trim();
    if (!id) {
      if (this.action !== "create") return [];
      return [
        {
          id: "create",
          label: "Nouvelle tâche",
          icon: "plus",
          href: tacheNewPath(),
        },
      ];
    }

    return [
      {
        id: "children",
        label: this.childrenLabel,
        icon: "list",
        href: tacheItemPath(id),
      },
      {
        id: "create",
        label: "Nouvelle sous-tâche",
        icon: "plus",
        href: tacheItemNewPath(id),
      },
      {
        id: "edit",
        label: "Modifier",
        icon: "edit-pencil",
        href: tacheItemEditPath(id),
      },
      {
        id: "move",
        label: "Déplacer dans…",
        icon: "data-transfer-both",
        href: tacheItemMovePath(id),
      },
    ];
  }

  private get childrenLabel(): string {
    const count = this.scopeTodo?.childCount ?? 0;
    return count <= 1 ? `${count} sous-tâche` : `${count} sous-tâches`;
  }

  private get currentAction(): ActionChoice | null {
    const choices = this.actionChoices;
    if (choices.length === 0) return null;
    return choices.find((choice) => choice.id === this.action) ?? choices[0];
  }

  private async syncScope() {
    const scopeId = this.scopeId?.trim() || "";
    if (!scopeId) {
      this.scopeTodo = null;
      this.ancestors = [];
      return;
    }

    try {
      const todo = await fetchTodo(scopeId);
      this.scopeTodo = todo;
      this.ancestors = todo.ancestors ?? [];
    } catch {
      this.scopeTodo = null;
      this.ancestors = [];
    }
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

  private onDeleteToggle = async () => {
    const todo = this.scopeTodo;
    if (!todo || this.busy) return;

    const deleting = !todo.archived;
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

    this.busy = true;
    try {
      await patchTodo(todo.id, {archived: !todo.archived});
      const parentId = todo.parentId?.trim();
      navigateTo(parentId ? tacheItemPath(parentId) : TACHE_ROOT, true);
    } catch (error) {
      await showError(error, "Impossible de modifier la tâche");
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private renderActionMenu() {
    const current = this.currentAction;
    const choices = this.actionChoices;
    if (!current || choices.length === 0) return nothing;

    if (choices.length === 1) {
      return html`
        <p class="flex items-center gap-1 text-sm italic text-neutral-500">
          <sonic-icon
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name=${current.icon}
            size="sm"
          ></sonic-icon>
          ${current.label}
        </p>
      `;
    }

    return html`
      <sonic-pop class="inline-block" placement="bottom-start">
        <sonic-button
          size="xs"
          variant="ghost"
          class="scope-action-trigger text-neutral-500"
          ?disabled=${this.busy}
          data-aria-label="Changer d’action"
        >
          <sonic-icon
            slot="prefix"
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name=${current.icon}
            size="sm"
          ></sonic-icon>
          ${current.label}
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
          minWidth="12rem"
        >
          ${choices.map(
            (choice) => html`
              <sonic-menu-item
                href=${choice.href}
                pushstate
                ?active=${choice.id === current.id}
                ?disabled=${this.busy}
              >
                ${this.renderMenuItemIcon(choice.icon)}
                ${choice.label}
              </sonic-menu-item>
            `,
          )}
          ${this.isArchived
            ? html`
                <sonic-menu-item
                  ?disabled=${this.busy}
                  @click=${this.onDeleteToggle}
                >
                  ${this.renderMenuItemIcon("undo")} Restaurer
                </sonic-menu-item>
              `
            : html`
                <sonic-menu-item
                  type="danger"
                  ?disabled=${this.busy}
                  @click=${this.onDeleteToggle}
                >
                  ${this.renderMenuItemIcon("trash")} Supprimer
                </sonic-menu-item>
              `}
        </sonic-menu>
      </sonic-pop>
    `;
  }

  private get showCrumb(): boolean {
    return (
      this.isScoped ||
      this.action === "create" ||
      this.action === "edit" ||
      this.action === "move"
    );
  }

  render() {
    const description = this.description;

    return html`
      <div>
        <div class="mb-1 flex h-7 items-center gap-2 overflow-hidden">
          ${this.showCrumb
            ? html`
                <task-breadcrumb
                  class="min-w-0"
                  showRoot
                  .ancestors=${this.ancestors}
                ></task-breadcrumb>
              `
            : nothing}
        </div>
        <div class="min-w-0 space-y-1.5">
          <h1 class="scope-heading truncate" title=${this.heading}>
            ${rmLinksTemplate(this.heading)}
          </h1>
          ${this.renderActionMenu()}
          ${description
            ? html`
                <p class="scope-description">${rmLinksTemplate(description)}</p>
              `
            : nothing}
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "task-scope-header": TaskScopeHeader;
  }
}
