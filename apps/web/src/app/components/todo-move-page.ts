import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/form-actions";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {
  get,
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
import type {Todo} from "../api/types";
import {set} from "../../utils/dataprovider";
import {todoMoveKey, todosCatalogKey} from "../dp";
import {bumpTodosRev} from "../init";
import {tx} from "../i18n";
import {navigateTo} from "../utils/navigate";
import {TACHE_ROOT, tacheItemPath} from "../utils/tache-paths";
import {focusPrimaryInput} from "../utils/focus-primary-input";
import tailwind from "../../css/tailwind";
import {showError} from "../utils/modal-dialog";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {rmLinksTemplate} from "./rm-link-text";
import "./page-shell";
import "./task-scope-header";

const ROOT_VALUE = "__root__";

@customElement("todo-move-page")
export class TodoMovePage extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }

      .move-targets {
        max-height: min(50vh, 24rem);
        overflow-x: hidden;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: transparent transparent;
      }

      .move-targets:hover {
        scrollbar-color: var(--sc-base-500) transparent;
      }

      .move-targets::-webkit-scrollbar {
        width: 0.5rem;
        height: 0.5rem;
        border: solid 0.15rem transparent;
        border-radius: var(--sc-rounded);
        background: transparent;
      }

      .move-targets::-webkit-scrollbar-thumb {
        transition: box-shadow 0.2s;
        border: solid 0.15rem transparent;
        border-radius: var(--sc-rounded);
        box-shadow: inset 0 0 0 0 transparent;
      }

      .move-targets:hover::-webkit-scrollbar-thumb {
        box-shadow: inset 0 0 2rem 2rem var(--sc-base-800);
      }
    `,
  ];

  @property({type: String})
  todoId = "";

  @state()
  private parentTodoId = "";

  @state()
  private loading = true;

  @state()
  private notFound = false;

  @state()
  private targets: Todo[] = [];

  /** `__root__` ou id de tâche cible. */
  @state()
  private selectedTarget = "";

  @subscribe(todoMoveKey.q)
  @state()
  searchQuery = "";

  @state()
  private busy = false;

  @get(endpoints.todos.byId, {skipEmptyPlaceholder: true})
  @state()
  todoPayload: ApiResult<ApiData<Todo>> | null = null;

  @subscribe(todosCatalogKey)
  @state()
  catalogTodos: Todo[] = [];

  @post(endpoints.todos.move, endpoints.keys.submit.todoMove, {
    skipEmptyPlaceholder: true,
  })
  @state()
  movePayload: ApiResult<ApiData<Todo>> | null = null;

  private lastHydratedTodoId = "";
  private pendingSubmit = false;

  connectedCallback() {
    super.connectedCallback();
    set(todoMoveKey.path, {q: ""});
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("todoId")) {
      this.lastHydratedTodoId = "";
      this.loading = Boolean(this.todoId);
      this.notFound = false;
      this.selectedTarget = "";
      set(todoMoveKey.path, {q: ""});
    }
    if (
      changed.has("todoPayload") ||
      changed.has("catalogTodos") ||
      changed.has("todoId")
    ) {
      this.hydrateFromPayloads();
    }
    if (changed.has("movePayload") && this.pendingSubmit) {
      void this.finishSubmit();
    }
  }

  private get backHref(): string {
    return this.parentTodoId ? tacheItemPath(this.parentTodoId) : TACHE_ROOT;
  }

  private get filteredTargets(): Todo[] {
    const needle = this.searchQuery?.trim().toLowerCase() ?? "";
    if (!needle) return this.targets;
    return this.targets.filter(
      (todo) =>
        todo.text.toLowerCase().includes(needle) ||
        (todo.description?.toLowerCase().includes(needle) ?? false),
    );
  }

  private hydrateFromPayloads() {
    if (!this.todoId) return;

    const todoPayload = this.todoPayload;
    if (todoPayload == null) {
      this.loading = true;
      return;
    }

    const todo = readApiData(todoPayload);
    if (!todo?.id) {
      this.notFound = true;
      this.targets = [];
      this.loading = false;
      return;
    }

    const catalog = Array.isArray(this.catalogTodos) ? this.catalogTodos : [];
    this.parentTodoId = todo.parentId?.trim() || "";

    const blocked = new Set<string>([todo.id]);
    let grew = true;
    while (grew) {
      grew = false;
      for (const item of catalog) {
        if (
          item.parentId &&
          blocked.has(item.parentId) &&
          !blocked.has(item.id)
        ) {
          blocked.add(item.id);
          grew = true;
        }
      }
    }

    this.targets = catalog.filter(
      (item) => !blocked.has(item.id) && !item.archived,
    );
    this.notFound = false;
    this.loading = false;

    if (todo.id !== this.lastHydratedTodoId) {
      this.lastHydratedTodoId = todo.id;
      void focusPrimaryInput(this);
    }
  }

  private async finishSubmit() {
    this.pendingSubmit = false;
    set(endpoints.keys.submit.todoMove.path, null);
    const todo = readApiData(this.movePayload);
    if (!todo) {
      await showError(apiResultError(this.movePayload));
      this.busy = false;
      return;
    }
    bumpTodosRev();
    set(todoMoveKey.path, {q: ""});
    navigateTo(this.backHref, true);
  }

  private selectTarget(target: string) {
    this.selectedTarget = target;
  }

  private onSubmit() {
    if (!this.selectedTarget || !this.todoId || this.busy) return;

    const parentId =
      this.selectedTarget === ROOT_VALUE ? null : this.selectedTarget;

    this.busy = true;
    this.pendingSubmit = true;
    set(endpoints.keys.submit.todoMove.path, {parentId});
  }

  private renderMenuItem(value: string, label: string, icon: string) {
    const selected = this.selectedTarget === value;
    return html`
      <sonic-menu-item
        class="w-full"
        ?disabled=${this.busy}
        ?active=${selected}
        @click=${() => this.selectTarget(value)}
      >
        <sonic-icon
          slot="prefix"
          library=${ICON_LIBRARY}
          prefix=${ICON_PREFIX}
          name=${icon}
          size="sm"
        ></sonic-icon>
        <span class="min-w-0 truncate">${rmLinksTemplate(label)}</span>
        ${selected
          ? html`
              <sonic-icon
                slot="suffix"
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="check"
                size="sm"
              ></sonic-icon>
            `
          : nothing}
      </sonic-menu-item>
    `;
  }

  private renderScopeHeader() {
    return html`
      <div
        class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
      >
        <task-scope-header
          .scopeId=${this.todoId}
          action="move"
        ></task-scope-header>
      </div>
    `;
  }

  render() {
    if (this.loading) {
      return html`
        <page-shell>
          ${this.renderScopeHeader()}
          <p class="mt-3 text-sm text-neutral-500">${t("common.loading")}</p>
        </page-shell>
      `;
    }

    if (this.notFound) {
      return html`
        <page-shell>
          ${this.renderScopeHeader()}
          <p class="mt-3 text-sm text-neutral-500">${t("tasks.not_found")}</p>
          <sonic-button href=${TACHE_ROOT} pushstate variant="outline">
            ${t("tasks.back")}
          </sonic-button>
        </page-shell>
      `;
    }

    const filtered = this.filteredTargets;
    const canSubmit = !this.busy && Boolean(this.selectedTarget);

    return html`
      <page-shell>
        ${this.renderScopeHeader()}

        <div class="mt-3 space-y-3">
          <p class="text-sm text-neutral-600">${t("tasks.move.help")}</p>

          <div formDataProvider=${todoMoveKey.path}>
            <sonic-input
              name="q"
              type="search"
              size="sm"
              placeholder=${tx("tasks.search_ph")}
              class="min-w-0"
            >
              <sonic-icon
                slot="prefix"
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="search"
                size="sm"
              ></sonic-icon>
            </sonic-input>
          </div>

          <div class="move-targets" aria-label=${tx("tasks.move.destinations_aria")}>
            <sonic-menu
              direction="column"
              align="left"
              size="sm"
              class="w-full"
            >
              ${this.renderMenuItem(ROOT_VALUE, tx("tasks.move.root"), "home")}
              ${filtered.map((todo) =>
                this.renderMenuItem(todo.id, todo.text, "check-circle"),
              )}
            </sonic-menu>
            ${filtered.length === 0
              ? html`
                  <p class="py-4 text-sm italic text-neutral-500">
                    ${t("tasks.move.empty")}
                  </p>
                `
              : nothing}
          </div>

          <sonic-form-actions justify="flex-end">
            <sonic-button
              href=${this.backHref}
              pushstate
              variant="outline"
              ?disabled=${this.busy}
            >
              ${t("common.cancel")}
            </sonic-button>
            <sonic-button
              type="primary"
              ?disabled=${!canSubmit}
              @click=${this.onSubmit}
            >
              <sonic-icon
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="data-transfer-both"
                size="sm"
              ></sonic-icon>
              ${t("tasks.move.submit")}
            </sonic-button>
          </sonic-form-actions>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "todo-move-page": TodoMovePage;
  }
}
