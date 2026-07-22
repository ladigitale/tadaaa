import "@supersoniks/concorde/modal";
import "@supersoniks/concorde/modal-title";
import "@supersoniks/concorde/modal-content";
import "@supersoniks/concorde/input";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {html, LitElement, nothing} from "lit";
import {customElement, query, state} from "lit/decorators.js";
import {handle, subscribe} from "@supersoniks/concorde/decorators";
import {fetchTags, fetchTodos} from "../api/client";
import {filterTodos} from "../api/store-logic";
import type {SortDirection, Tag, Todo, TodoSortBy} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {todoSearchKey, type TodoSearchForm} from "../dp";
import {navigateTo} from "../utils/navigate";
import {tacheItemPath} from "../utils/tache-paths";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {rmLinksTemplate} from "./rm-link-text";
import "./pop-select";
import type {PopSelectOption} from "./pop-select";
import {
  TODO_SORT_OPTIONS,
  TODO_STATUS_OPTIONS,
  parseTodoSortKey,
} from "./todo-filter-options";

const RESULT_LIMIT = 40;

const emptySearchForm = (): TodoSearchForm => ({
  q: "",
  status: "all",
  tags: [],
  sort: "createdAt:desc",
  sortBy: "createdAt",
  sortDir: "desc",
});

@customElement("todo-search-modal")
export class TodoSearchModal extends LitElement {
  static styles = [tailwind];

  @query("#todoSearchModal")
  private modal?: HTMLElement & {show: () => void; hide: () => void};

  @state()
  private todos: Todo[] = [];

  @state()
  private tags: Tag[] = [];

  /** Champs abonnés unitairement (formDataProvider écrit en nested path). */
  @subscribe(todoSearchKey.q)
  @state()
  q = "";

  @subscribe(todoSearchKey.status)
  @state()
  status: TodoSearchForm["status"] = "all";

  @subscribe(todoSearchKey.tags)
  @state()
  selectedTags: string[] = [];

  @subscribe(todoSearchKey.sort)
  @state()
  sort: TodoSearchForm["sort"] = "createdAt:desc";

  @subscribe(todoSearchKey.sortBy)
  @state()
  sortBy: TodoSortBy = "createdAt";

  @subscribe(todoSearchKey.sortDir)
  @state()
  sortDir: SortDirection = "desc";

  @state()
  private loading = false;

  private onKeyDown = (event: KeyboardEvent) => {
    const isModifier = event.metaKey || event.ctrlKey;
    if (!isModifier || event.key.toLowerCase() !== "k") return;
    event.preventDefault();
    void this.open();
  };

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener("keydown", this.onKeyDown);
  }

  disconnectedCallback() {
    window.removeEventListener("keydown", this.onKeyDown);
    super.disconnectedCallback();
  }

  @handle(todoSearchKey.sort)
  onSortKeyChange(sort: string) {
    const parsed = parseTodoSortKey(sort);
    if (!parsed) return;

    const filter = read(todoSearchKey.path) as TodoSearchForm;
    if (
      filter.sortBy === parsed.sortBy &&
      filter.sortDir === parsed.sortDir &&
      filter.sort === sort
    ) {
      return;
    }

    set(todoSearchKey.path, {
      ...filter,
      sort: `${parsed.sortBy}:${parsed.sortDir}`,
      sortBy: parsed.sortBy,
      sortDir: parsed.sortDir,
    });
  }

  private get tagFilterOptions(): PopSelectOption[] {
    return [
      {value: "all", label: "Toutes", icon: "label", checksAll: true},
      ...this.tags.map((tag) => ({
        value: tag.id,
        label: tag.name,
        icon: "label",
      })),
    ];
  }

  private get sortValue(): string {
    return this.sort || `${this.sortBy}:${this.sortDir}`;
  }

  private get tagsValue(): string[] {
    return Array.isArray(this.selectedTags) ? this.selectedTags : [];
  }

  private get filteredTodos(): Todo[] {
    const knownTagIds = this.tags.map((tag) => tag.id);
    const result = filterTodos(
      this.todos,
      {
        status: this.status ?? "all",
        tagIds: this.tagsValue,
        q: this.q ?? "",
        sortBy: this.sortBy ?? "createdAt",
        sortDir: this.sortDir ?? "desc",
        parentId: null,
        recursive: true,
      },
      knownTagIds,
    );
    return result.slice(0, RESULT_LIMIT);
  }

  /** Charge actives + archivées pour filtrer côté client (statut compris). */
  private async loadTodosForSearch(): Promise<Todo[]> {
    const [active, archived] = await Promise.all([
      fetchTodos({
        status: "all",
        limit: 500,
        recursive: true,
        sortBy: "createdAt",
        sortDir: "desc",
      }),
      fetchTodos({
        status: "archived",
        limit: 500,
        recursive: true,
        sortBy: "createdAt",
        sortDir: "desc",
      }),
    ]);
    const byId = new Map<string, Todo>();
    for (const todo of [...(active.data ?? []), ...(archived.data ?? [])]) {
      byId.set(todo.id, todo);
    }
    return [...byId.values()];
  }

  async open() {
    set(todoSearchKey.path, emptySearchForm());
    this.loading = true;

    try {
      const [todos, tags] = await Promise.all([
        this.loadTodosForSearch(),
        fetchTags(),
      ]);
      this.todos = todos;
      this.tags = tags;
    } catch {
      this.todos = [];
      this.tags = [];
    } finally {
      this.loading = false;
    }

    await this.updateComplete;
    await this.modal?.show();
    this.focusSearchInput();
  }

  private focusSearchInput() {
    queueMicrotask(() => {
      const input = this.renderRoot.querySelector("sonic-input");
      if (input && "focus" in input && typeof input.focus === "function") {
        input.focus();
      }
    });
  }

  private goToTodo(todo: Todo) {
    set(todoSearchKey.path, emptySearchForm());
    this.modal?.hide();
    navigateTo(tacheItemPath(todo.id));
  }

  private renderResult(todo: Todo) {
    return html`
      <sonic-menu-item class="w-full" @click=${() => this.goToTodo(todo)}>
        <sonic-icon
          slot="prefix"
          library=${ICON_LIBRARY}
          prefix=${ICON_PREFIX}
          name=${todo.done ? "check-circle" : "circle"}
          size="sm"
        ></sonic-icon>
        <span
          class="min-w-0 truncate ${todo.done
            ? "text-neutral-400 line-through"
            : ""}"
          >${rmLinksTemplate(todo.text)}</span
        >
      </sonic-menu-item>
    `;
  }

  render() {
    const results = this.filteredTodos;
    const needle = this.q?.trim() ?? "";
    const selectedTags = this.tagsValue;

    return html`
      <sonic-modal id="todoSearchModal" maxWidth="36rem" width="100%">
        <sonic-modal-title>Rechercher une tâche</sonic-modal-title>
        <sonic-modal-content>
          <div class="space-y-3" formDataProvider=${todoSearchKey.path}>
            <div class="space-y-2">
              <sonic-input
                name="q"
                type="search"
                size="sm"
                placeholder="Nom ou RM-12345"
                class="min-w-0 w-full"
              >
                <sonic-icon
                  slot="prefix"
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name="search"
                  size="sm"
                ></sonic-icon>
              </sonic-input>

              <div class="flex flex-wrap items-center gap-1.5">
                <pop-select
                  label="Statut"
                  name="status"
                  mode="radio"
                  .value=${this.status}
                  .options=${TODO_STATUS_OPTIONS}
                  minWidth="11rem"
                ></pop-select>

                ${this.tags.length > 0
                  ? html`
                      <pop-select
                        label="Étiquette"
                        name="tags"
                        mode="multi"
                        .value=${selectedTags}
                        .options=${this.tagFilterOptions}
                        minWidth="12rem"
                      ></pop-select>
                    `
                  : nothing}

                <pop-select
                  label="Tri"
                  name="sort"
                  mode="radio"
                  .value=${this.sortValue}
                  .options=${TODO_SORT_OPTIONS}
                  minWidth="14rem"
                ></pop-select>
              </div>
            </div>

            <div class="max-h-72 overflow-y-auto" aria-label="Résultats">
              ${this.loading
                ? html`
                    <p class="py-4 text-center text-sm text-neutral-500">
                      Chargement…
                    </p>
                  `
                : html`
                    <sonic-menu
                      direction="column"
                      align="left"
                      size="sm"
                      class="w-full"
                    >
                      ${results.map((todo) => this.renderResult(todo))}
                    </sonic-menu>
                    ${results.length === 0
                      ? html`
                          <p
                            class="py-4 text-center text-sm italic text-neutral-500"
                          >
                            ${needle ||
                            selectedTags.length > 0 ||
                            this.status !== "all"
                              ? "Aucune tâche pour ces filtres."
                              : "Aucune tâche."}
                          </p>
                        `
                      : nothing}
                  `}
            </div>
          </div>
        </sonic-modal-content>
      </sonic-modal>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "todo-search-modal": TodoSearchModal;
  }
}
