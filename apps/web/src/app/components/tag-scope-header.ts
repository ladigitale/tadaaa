import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {deleteTag, fetchTag, fetchTodos} from "../api/client";
import {countTodosByTag} from "../api/store-logic";
import type {Tag} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {todosFilterKey, type TodosFilter} from "../dp";
import {
  TAGS_ROOT,
  tagsItemEditPath,
  tagsNewPath,
} from "../utils/tag-paths";
import {TACHE_ROOT} from "../utils/tache-paths";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {navigateTo} from "../utils/navigate";
import tailwind from "../../css/tailwind";

/** Action affichée sous le titre (contexte de la page). */
export type TagScopeAction = "" | "create" | "edit";

type ActionChoice = {
  id: TagScopeAction;
  label: string;
  icon: string;
  href: string;
};

/**
 * En-tête de contexte étiquette : fil d’Ariane, titre, menu d’action.
 */
@customElement("tag-scope-header")
export class TagScopeHeader extends LitElement {
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

    `,
  ];

  /** Id de l’étiquette courante, ou vide = liste / création racine. */
  @property({type: String})
  scopeId = "";

  /**
   * Action de page :
   * - create → « Nouvelle étiquette »
   * - edit → « Modifier »
   */
  @property()
  action: TagScopeAction = "";

  @state()
  private scopeTag: Tag | null = null;

  @state()
  private taskCount = 0;

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
    if (!this.isScoped) return "Étiquettes";
    return this.scopeTag?.name?.trim() || "…";
  }

  private get tasksLabel(): string {
    return this.taskCount <= 1
      ? `${this.taskCount} tâche`
      : `${this.taskCount} tâches`;
  }

  private get actionChoices(): ActionChoice[] {
    const id = this.scopeId?.trim();
    if (!id) {
      if (this.action !== "create") return [];
      return [
        {
          id: "create",
          label: "Nouvelle étiquette",
          icon: "plus",
          href: tagsNewPath(),
        },
      ];
    }

    return [
      {
        id: "create",
        label: "Nouvelle étiquette",
        icon: "plus",
        href: tagsNewPath(),
      },
      {
        id: "edit",
        label: "Modifier",
        icon: "edit-pencil",
        href: tagsItemEditPath(id),
      },
    ];
  }

  private get currentAction(): ActionChoice | null {
    const choices = this.actionChoices;
    if (choices.length === 0) return null;
    return choices.find((choice) => choice.id === this.action) ?? choices[0];
  }

  private async syncScope() {
    const scopeId = this.scopeId?.trim() || "";
    if (!scopeId) {
      this.scopeTag = null;
      this.taskCount = 0;
      return;
    }

    try {
      const [tag, todosResponse] = await Promise.all([
        fetchTag(scopeId),
        fetchTodos({status: "all", limit: 500, recursive: true}),
      ]);
      this.scopeTag = tag;
      this.taskCount = countTodosByTag(todosResponse.data ?? [], tag.id);
    } catch {
      this.scopeTag = null;
      this.taskCount = 0;
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

  private goToFilteredTodos = () => {
    const tag = this.scopeTag;
    if (!tag) return;
    const filter = read(todosFilterKey.path) as TodosFilter;
    set(todosFilterKey.path, {
      ...filter,
      q: "",
      status: "all",
      tags: [tag.id],
      parentId: "",
      recursive: true,
      _rev: (filter._rev ?? 0) + 1,
    });
    navigateTo(TACHE_ROOT);
  };

  private onDelete = async () => {
    const tag = this.scopeTag;
    if (!tag || this.busy) return;

    const ok = await confirmDialog({
      title: "Supprimer l’étiquette",
      message: `Supprimer « ${tag.name} » ? Elle est utilisée par ${this.taskCount} tâche(s).`,
      confirmLabel: "Supprimer",
      danger: true,
    });
    if (!ok) return;

    this.busy = true;
    try {
      await deleteTag(tag.id);
      navigateTo(TAGS_ROOT, true);
    } catch (error) {
      await showError(error, "Impossible de supprimer l’étiquette");
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private renderActionMenu() {
    const current = this.currentAction;
    const choices = this.actionChoices;
    if (!current || choices.length === 0) return nothing;

    if (choices.length === 1 && !this.isScoped) {
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
          ${this.isScoped
            ? html`
                <sonic-menu-item
                  ?disabled=${this.busy}
                  @click=${this.goToFilteredTodos}
                >
                  ${this.renderMenuItemIcon("list")}
                  ${this.tasksLabel}
                </sonic-menu-item>
              `
            : nothing}
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
          ${this.isScoped
            ? html`
                <sonic-menu-item
                  type="danger"
                  ?disabled=${this.busy}
                  @click=${this.onDelete}
                >
                  ${this.renderMenuItemIcon("trash")} Supprimer
                </sonic-menu-item>
              `
            : nothing}
        </sonic-menu>
      </sonic-pop>
    `;
  }

  private get showCrumb(): boolean {
    return this.isScoped || this.action === "create" || this.action === "edit";
  }

  private renderCrumb() {
    if (!this.showCrumb) return nothing;

    return html`
      <nav
        class="flex min-w-0 items-center gap-0.5 text-sm"
        aria-label="Fil d’Ariane"
      >
        <sonic-button
          goBack
          shape="circle"
          variant="ghost"
          size="sm"
          class="shrink-0"
          data-aria-label="Retour"
          title="Retour"
        >
          <sonic-icon
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="nav-arrow-left"
            size="sm"
          ></sonic-icon>
        </sonic-button>
        <sonic-button
          href=${TAGS_ROOT}
          pushstate
          autoActive="disabled"
          variant="link"
          size="sm"
        >
          Étiquettes
        </sonic-button>
      </nav>
    `;
  }

  render() {
    return html`
      <div>
        <div class="mb-1 flex h-7 items-center gap-2 overflow-hidden">
          ${this.renderCrumb()}
        </div>
        <div class="min-w-0 space-y-1.5">
          <h1 class="scope-heading truncate" title=${this.heading}>
            ${this.heading}
          </h1>
          ${this.renderActionMenu()}
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tag-scope-header": TagScopeHeader;
  }
}
