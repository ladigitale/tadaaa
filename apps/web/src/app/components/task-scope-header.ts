import "@supersoniks/concorde/button";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {
  get,
  patch,
  post,
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
import type {Todo, TodoAncestor} from "../api/types";
import {set} from "../../utils/dataprovider";
import {bumpTodosRev} from "../init";
import {tf, tx} from "../i18n";
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
import {rmLinksTemplate, richTextTemplate} from "./rm-link-text";

/** Action affichée sous le titre (contexte de la page). */
export type TaskScopeAction = "" | "children" | "create" | "edit" | "move";

type ActionChoice = {
  id: TaskScopeAction;
  label: string;
  icon: string;
  href: string;
  badge?: number;
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

      .heading-tooltip {
        display: block;
        min-width: 0;
        max-width: 100%;
        text-align: left;
      }

      .scope-description p {
        margin: 0;
      }

      .scope-description p + p,
      .scope-description ul + p,
      .scope-description ol + p,
      .scope-description p + ul,
      .scope-description p + ol {
        margin-top: 0.4em;
      }

      .scope-description ul,
      .scope-description ol {
        margin: 0.3em 0 0;
        padding-left: 1.2rem;
      }

      .scope-description code {
        font-size: 0.9em;
        padding: 0.05em 0.3em;
        border-radius: 0.25rem;
        background: color-mix(in srgb, currentColor 12%, transparent);
      }

      .scope-description a {
        color: rgb(37 99 235);
        text-decoration: underline;
        text-underline-offset: 2px;
      }

      @media print {
        .scope-action-menu {
          display: none !important;
        }

        .scope-heading {
          color: #111 !important;
        }

        .scope-description {
          color: #333 !important;
        }
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

  @get(endpoints.todos.byScopeId, {skipEmptyPlaceholder: true})
  @state()
  scopePayload: ApiResult<ApiData<Todo>> | null = null;

  @patch(endpoints.todos.scopePatch, endpoints.keys.submit.todoScopePatch, {
    skipEmptyPlaceholder: true,
  })
  @state()
  patchPayload: ApiResult<ApiData<Todo>> | null = null;

  @post(endpoints.todos.collection, endpoints.keys.submit.todoScopeCopy, {
    skipEmptyPlaceholder: true,
  })
  @state()
  copyPayload: ApiResult<ApiData<Todo>> | null = null;

  private pendingSubmit = false;
  private pendingCopy = false;

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("scopeId") || changed.has("scopePayload")) {
      this.hydrateScope();
    }
    if (changed.has("patchPayload") && this.pendingSubmit) {
      void this.finishArchiveSubmit();
    }
    if (changed.has("copyPayload") && this.pendingCopy) {
      void this.finishCopy();
    }
  }

  private get isScoped(): boolean {
    return Boolean(this.scopeId?.trim());
  }

  private get heading(): string {
    if (!this.isScoped) return tx("tasks.root_heading");
    return this.scopeTodo?.text?.trim() || "…";
  }

  private get description(): string {
    return this.scopeTodo?.description?.trim() || "";
  }

  private get isArchived(): boolean {
    return Boolean(this.scopeTodo?.archived);
  }

  private hydrateScope() {
    const scopeId = this.scopeId?.trim() || "";
    if (!scopeId) {
      this.scopeTodo = null;
      this.ancestors = [];
      return;
    }

    if (this.scopePayload == null) return;

    const todo = readApiData(this.scopePayload);
    if (!todo?.id) {
      this.scopeTodo = null;
      this.ancestors = [];
      return;
    }

    this.scopeTodo = todo;
    this.ancestors = todo.ancestors ?? [];
  }

  private get actionChoices(): ActionChoice[] {
    const id = this.scopeId?.trim();
    if (!id) {
      if (this.action !== "create") return [];
      return [
        {
          id: "create",
          label: tx("tasks.new"),
          icon: "plus",
          href: tacheNewPath(),
        },
      ];
    }

    return [
      {
        id: "children",
        label: tx("tasks.see"),
        icon: "eye",
        href: tacheItemPath(id),
        badge: this.scopeTodo?.childCount ?? 0,
      },
      {
        id: "create",
        label: tx("tasks.new_sub"),
        icon: "plus",
        href: tacheItemNewPath(id),
      },
      {
        id: "edit",
        label: tx("tasks.edit"),
        icon: "edit-pencil",
        href: tacheItemEditPath(id),
      },
      {
        id: "move",
        label: tx("tasks.move"),
        icon: "data-transfer-both",
        href: tacheItemMovePath(id),
      },
    ];
  }

  private get currentAction(): ActionChoice | null {
    const choices = this.actionChoices;
    if (choices.length === 0) return null;
    return choices.find((choice) => choice.id === this.action) ?? choices[0];
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

  private renderCountBadge(count?: number) {
    if (!count || count <= 0) return nothing;
    return html`
      <sonic-tooltip
        slot="suffix"
        label=${tf("tasks.subtasks", {n: count})}
        placement="bottom"
        class="inline-block"
      >
        <sonic-badge type="neutral" size="xs">${count}</sonic-badge>
      </sonic-tooltip>
    `;
  }

  private onCopy = () => {
    const todo = this.scopeTodo;
    const scopeId = this.scopeId?.trim();
    if (!todo || !scopeId || this.busy || todo.archived) return;

    this.busy = true;
    this.pendingCopy = true;
    set(endpoints.keys.paths.todoScopeCopy(scopeId), todoCopyInput(todo));
  };

  private async finishCopy() {
    const scopeId = this.scopeId?.trim();
    this.pendingCopy = false;
    if (scopeId) set(endpoints.keys.paths.todoScopeCopy(scopeId), null);
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
    const todo = this.scopeTodo;
    const scopeId = this.scopeId?.trim();
    if (!todo || !scopeId || this.busy) return;

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

    this.busy = true;
    this.pendingSubmit = true;
    set(endpoints.keys.paths.todoScopePatch(scopeId), {archived: !todo.archived});
  };

  private async finishArchiveSubmit() {
    const todo = this.scopeTodo;
    const scopeId = this.scopeId?.trim();
    this.pendingSubmit = false;
    if (scopeId) set(endpoints.keys.paths.todoScopePatch(scopeId), null);

    const updated = readApiData(this.patchPayload);
    if (!updated) {
      await showError(apiResultError(this.patchPayload));
      this.busy = false;
      return;
    }

    bumpTodosRev();
    const parentId = todo?.parentId?.trim();
    navigateTo(parentId ? tacheItemPath(parentId) : TACHE_ROOT, true);
  }

  private renderActionMenu() {
    const current = this.currentAction;
    const choices = this.actionChoices;
    if (!current || choices.length === 0) return nothing;

    if (choices.length === 1) {
      return html`
        <p
          class="scope-action-menu flex items-center gap-1 text-sm italic text-neutral-500"
        >
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
      <sonic-pop
        class="scope-action-menu inline-block"
        placement="bottom-start"
      >
        <sonic-button
          size="xs"
          variant="ghost"
          class="scope-action-trigger text-neutral-500"
          ?disabled=${this.busy}
          data-aria-label=${tx("common.change_action_aria")}
        >
          <sonic-icon
            slot="prefix"
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name=${current.icon}
            size="sm"
          ></sonic-icon>
          ${current.label}
          ${this.renderCountBadge(current.badge)}
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
                ${this.renderCountBadge(choice.badge)}
              </sonic-menu-item>
            `,
          )}
          <sonic-menu-item
            ?disabled=${this.busy}
            @click=${() => window.print()}
          >
            ${this.renderMenuItemIcon("printer")} ${tx("tasks.print")}
          </sonic-menu-item>
          ${this.isArchived
            ? html`
                <sonic-menu-item
                  ?disabled=${this.busy}
                  @click=${this.onDeleteToggle}
                >
                  ${this.renderMenuItemIcon("undo")} ${t("tasks.restore")}
                </sonic-menu-item>
              `
            : html`
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
          <sonic-tooltip
            class="heading-tooltip"
            label=${this.heading}
            placement="bottom"
          >
            <h1 class="scope-heading truncate">
              ${rmLinksTemplate(this.heading)}
            </h1>
          </sonic-tooltip>
          ${this.renderActionMenu()}
          ${description
            ? html`
                <div class="scope-description rich-text">
                  ${richTextTemplate(description)}
                </div>
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
