import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/queue";
import "@supersoniks/concorde/tooltip";
import "@supersoniks/concorde/modal";
import "@supersoniks/concorde/modal-title";
import "@supersoniks/concorde/modal-content";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, query, state} from "lit/decorators.js";
import type {DirectiveResult} from "lit/directive.js";
import {handle, subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {getMockApiServiceUrl} from "../api/config";
import type {
  Tag,
  Todo,
} from "../api/types";
import {dp, read, set} from "../../utils/dataprovider";
import {TodosFilter, tagsListKey, todosDoneKey, todosFilterKey} from "../dp";
import {tx} from "../i18n";
import "./todo-row";
import "./tag-picker";
import "./task-scope-header";
import "./todo-bulk-actions";
import "./tasks-calendar";
import {
  todoSortOptions,
  todoStatusOptions,
  parseTodoSortKey,
} from "./todo-filter-options";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {
  tacheItemNewPath,
  tacheNewPath,
} from "../utils/tache-paths";
import {shortcuts} from "../shortcuts";

type TasksViewMode = "list" | "calendar";

const VIEW_STORAGE_KEY = "tada-tasks-view-mode";

/** Préférence SPA : survit aux changements de route même si le storage échoue. */
let rememberedViewMode: TasksViewMode | null = null;

function loadViewMode(): TasksViewMode {
  if (rememberedViewMode === "list" || rememberedViewMode === "calendar") {
    return rememberedViewMode;
  }
  try {
    const raw = localStorage.getItem(VIEW_STORAGE_KEY);
    if (raw === "calendar" || raw === "list") {
      rememberedViewMode = raw;
      return raw;
    }
  } catch {
    /* ignore */
  }
  return "list";
}

function saveViewMode(mode: TasksViewMode) {
  rememberedViewMode = mode;
  try {
    localStorage.setItem(VIEW_STORAGE_KEY, mode);
  } catch {
    /* ignore */
  }
}

@customElement("todo-app")
export class TodoApp extends LitElement {
  static styles = [
    tailwind,
    formLabelStyles,
    css`
      :host {
        display: block;
        /* Place pour le FAB fixe en bas de page. */
        padding-bottom: 4.5rem;
      }

      .todo-app-layout {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
      }

      @media (min-width: 640px) {
        .todo-app-layout {
          gap: 1rem;
        }
      }

      /* Marge négative + padding : place pour le focus ring checkbox. */
      .todo-app-queue {
        margin-inline: -0.5rem;
        padding-inline: 0.5rem;
      }

      .todo-app-add {
        pointer-events: none;
        position: fixed;
        inset-inline: 0;
        bottom: var(--app-shell-footer-offset, 0px);
        z-index: 20;
        padding-bottom: max(0.75rem, env(safe-area-inset-bottom, 0px));
      }

      @media (min-width: 640px) {
        .todo-app-add {
          padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
        }
      }

      .todo-app-add-inner {
        pointer-events: auto;
        margin-inline: auto;
        max-width: 72rem;
        padding-inline: 0.75rem;
      }

      @media (min-width: 640px) {
        .todo-app-add-inner {
          padding-inline: 1rem;
        }
      }

      .filter-option-btn::part(button),
      .filter-option-btn {
        outline: none;
        box-shadow: none;
      }
    `,
  ];

  /** null / "" = liste racine ; sinon enfants (et sous-arbre) de cette tâche. */
  @property({type: String})
  parentId = "";

  @query("#todoFiltersModal")
  private filtersModal?: HTMLElement & {show: () => void; hide: () => void};

  @subscribe(tagsListKey)
  @state()
  tags: Tag[] = [];

  @state()
  private viewMode: TasksViewMode = loadViewMode();

  @subscribe(todosFilterKey)
  @state()
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

  connectedCallback() {
    super.connectedCallback();
    // Tableau partagé FormCheckable : évite qu’un initPublisher écrase avec `[]`.
    if (!Array.isArray(read(`${todosDoneKey.path}.ids`))) {
      set(todosDoneKey.path, {ids: []});
    }
    this.viewMode = loadViewMode();
    this.syncFilterParent();
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("parentId")) {
      this.syncFilterParent();
    }
  }

  private syncFilterParent() {
    const scopeId = this.parentId?.trim() || "";
    const filter = read(todosFilterKey.path) as TodosFilter;
    if (filter.parentId === scopeId) return;
    set(todosFilterKey.path, {
      ...filter,
      parentId: scopeId,
      recursive: false,
    });
  }

  /** Synchronise sortBy / sortDir depuis la clé composite du sélecteur. */
  @handle(todosFilterKey.sort)
  onSortKeyChange(sort: string) {
    const parsed = parseTodoSortKey(sort);
    if (!parsed) return;

    const filter = read(todosFilterKey.path) as TodosFilter;
    if (
      filter.sortBy === parsed.sortBy &&
      filter.sortDir === parsed.sortDir &&
      filter.sort === sort
    ) {
      return;
    }

    set(todosFilterKey.path, {
      ...filter,
      sort: `${parsed.sortBy}:${parsed.sortDir}`,
      sortBy: parsed.sortBy,
      sortDir: parsed.sortDir,
    });
  }

  /** Filtre étiquettes : cherche dans toute l’arborescence si au moins une est active. */
  @handle(todosFilterKey.tags)
  onTagsFilterChange(tags: string[] | string | null) {
    const filter = read(todosFilterKey.path) as TodosFilter;
    const list = Array.isArray(tags)
      ? tags.map(String).filter(Boolean)
      : tags
        ? [String(tags)]
        : [];
    const knownIds = this.tags.map((tag) => tag.id);
    const selectsEveryKnownTag =
      knownIds.length > 0 &&
      list.length >= knownIds.length &&
      knownIds.every((id) => list.includes(id));
    const recursive = list.length > 0 && !selectsEveryKnownTag;
    if (filter.recursive === recursive) return;
    set(todosFilterKey.path, {...filter, recursive});
  }

  /** Prune les ids de filtre invalides quand `tagsListKey` change. */
  @handle(tagsListKey)
  onTagsListChange(tags: Tag[] | null) {
    const list = Array.isArray(tags) ? tags : [];
    const selected = Array.isArray(this.filter?.tags) ? this.filter.tags : [];
    const valid = selected.filter((tagId) =>
      list.some((tag) => tag.id === tagId),
    );
    if (valid.length !== selected.length) {
      dp(`${todosFilterKey.path}/tags`).set(valid);
    }
  }

  private renderTodo = (todo: Todo): DirectiveResult =>
    html`<todo-row .todo=${todo} .tags=${this.tags}></todo-row>`;

  private noTodos = (): DirectiveResult => html`
    <p class="py-12 text-sm italic text-neutral-500">
      ${t("tasks.empty_filtered")}
    </p>
  `;

  private todoSeparator = (): DirectiveResult =>
    html`<div
      class="w-full bg-neutral-100"
      style="min-height: 2px"
      role="separator"
    ></div>`;

  private get sortValue(): string {
    return (
      this.filter.sort ||
      `${this.filter.sortBy ?? "createdAt"}:${this.filter.sortDir ?? "desc"}`
    );
  }

  private get hasActiveFilters(): boolean {
    const tags = Array.isArray(this.filter.tags) ? this.filter.tags : [];
    const sort = this.sortValue;
    const statusActive = (this.filter.status ?? "all") !== "all";
    const tagsActive = tags.length > 0;
    const sortActive =
      this.viewMode !== "calendar" && sort !== "createdAt:desc";
    return statusActive || tagsActive || sortActive;
  }

  private get addHref(): string {
    const scopeId = this.parentId?.trim();
    return scopeId ? tacheItemNewPath(scopeId) : tacheNewPath();
  }

  private openFiltersModal = () => {
    void this.filtersModal?.show();
  };

  private closeFiltersModal = () => {
    this.filtersModal?.hide();
  };

  private setViewMode(mode: TasksViewMode) {
    this.viewMode = mode;
    saveViewMode(mode);
  }

  private onViewChange = (e: CustomEvent<{mode: TasksViewMode}>) => {
    const mode = e.detail?.mode;
    if (mode === "list" || mode === "calendar") {
      this.setViewMode(mode);
    }
  };

  private renderFilterOption(
    name: string,
    option: {value: string; label: string; icon?: string},
  ) {
    return html`
      <sonic-button
        class="filter-option-btn"
        radio
        name=${name}
        value=${option.value}
        size="sm"
        variant="outline"
      >
        ${option.icon
          ? html`
              <sonic-icon
                slot="prefix"
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name=${option.icon}
                size="sm"
              ></sonic-icon>
            `
          : nothing}
        ${option.label}
      </sonic-button>
    `;
  }

  private renderFiltersModal(isCalendar: boolean, selectedTags: string[]) {
    return html`
      <sonic-modal id="todoFiltersModal" maxWidth="28rem" width="100%">
        <sonic-modal-title>${tx("tasks.filter.title")}</sonic-modal-title>
        <sonic-modal-content>
          <div formDataProvider=${todosFilterKey.path}>
            <sonic-form-layout>
              <div class="form-field">
                <label class="form-label">${tx("tasks.filter.status")}</label>
                <div class="form-field-control flex flex-wrap gap-1.5">
                  ${todoStatusOptions().map((option) =>
                    this.renderFilterOption("status", option),
                  )}
                </div>
              </div>

              ${this.tags.length > 0
                ? html`
                    <div class="form-field">
                      <label class="form-label"
                        >${tx("tasks.filter.tag")}</label
                      >
                      <div class="form-field-control">
                        <tag-picker
                          formPath=${todosFilterKey.path}
                          name="tags"
                          .tags=${this.tags}
                          .value=${selectedTags}
                        ></tag-picker>
                      </div>
                    </div>
                  `
                : nothing}

              ${!isCalendar
                ? html`
                    <div class="form-field">
                      <label class="form-label"
                        >${tx("tasks.filter.sort")}</label
                      >
                      <div class="form-field-control flex flex-wrap gap-1.5">
                        ${todoSortOptions().map((option) =>
                          this.renderFilterOption("sort", option),
                        )}
                      </div>
                    </div>
                  `
                : nothing}

              <sonic-form-actions justify="flex-end">
                <sonic-button type="primary" @click=${this.closeFiltersModal}>
                  ${t("common.ok")}
                </sonic-button>
              </sonic-form-actions>
            </sonic-form-layout>
          </div>
        </sonic-modal-content>
      </sonic-modal>
    `;
  }

  render() {
    const base = getMockApiServiceUrl();
    const filterProvider = todosFilterKey.path;
    const selectedTags = Array.isArray(this.filter.tags)
      ? this.filter.tags
      : [];
    const isCalendar = this.viewMode === "calendar";
    const filterAria = tx("tasks.filter.open_aria");
    const addLabel = this.parentId?.trim()
      ? tx("tasks.new_sub")
      : tx("tasks.new");
    const addHint = shortcuts.withHint(addLabel, "newItem");

    return html`
      <div
        class="todo-app-layout"
        formDataProvider=${filterProvider}
        dataFilterProvider=${filterProvider}
      >
        <div
          class="shrink-0 space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <task-scope-header
            .scopeId=${this.parentId}
            action=${this.parentId?.trim() ? "children" : ""}
          ></task-scope-header>

          <section>
            <div class="flex flex-wrap items-end gap-1.5">
              <sonic-input
                name="q"
                type="search"
                size="sm"
                placeholder=${tx("tasks.search_ph")}
                class="min-w-0 flex-1 basis-[12rem]"
              >
                <sonic-icon
                  slot="prefix"
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name="search"
                  size="sm"
                ></sonic-icon>
              </sonic-input>

              <sonic-tooltip label=${filterAria} placement="bottom">
                <sonic-button
                  shape="circle"
                  size="sm"
                  variant="ghost"
                  ?active=${this.hasActiveFilters}
                  data-aria-label=${filterAria}
                  @click=${this.openFiltersModal}
                >
                  <sonic-icon
                    library=${ICON_LIBRARY}
                    prefix=${ICON_PREFIX}
                    name="filter"
                    size="sm"
                  ></sonic-icon>
                </sonic-button>
              </sonic-tooltip>

              <todo-bulk-actions
                .filter=${this.filter}
                viewMode=${this.viewMode}
                @view-change=${this.onViewChange}
              ></todo-bulk-actions>
            </div>
          </section>
        </div>

        ${isCalendar
          ? html`
              <div class="todo-app-queue">
                <tasks-calendar .filter=${this.filter}></tasks-calendar>
              </div>
            `
          : html`
              <div class="todo-app-queue">
                <sonic-queue
                  lazyload
                  dataProviderExpression="todos?offset=$offset&limit=$limit"
                  serviceurl=${base}
                  key="data"
                  limit="20"
                  idKey="id"
                  class="pb-2"
                  .items=${this.renderTodo}
                  .separator=${this.todoSeparator}
                  .noItems=${this.noTodos}
                ></sonic-queue>
              </div>
            `}

        <div class="todo-app-add">
          <div class="todo-app-add-inner">
            <sonic-tooltip label=${addHint} placement="top">
              <sonic-button
                href=${this.addHref}
                pushstate
                type="primary"
                size="sm"
                data-aria-label=${addHint}
              >
                <sonic-icon
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name="plus"
                  size="sm"
                ></sonic-icon>
                ${addLabel}
              </sonic-button>
            </sonic-tooltip>
          </div>
        </div>
      </div>

      ${this.renderFiltersModal(isCalendar, selectedTags)}
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "todo-app": TodoApp;
  }
}
