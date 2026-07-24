import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/queue";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, query, state} from "lit/decorators.js";
import type {DirectiveResult} from "lit/directive.js";
import {handle, subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {fetchTags} from "../api/client";
import {getMockApiServiceUrl} from "../api/config";
import type {
  Tag,
  Todo,
} from "../api/types";
import {dp, read, set} from "../../utils/dataprovider";
import {TodosFilter, tagsListKey, todosDoneKey, todosFilterKey} from "../dp";
import {tx} from "../i18n";
import "./todo-row";
import "./pop-select";
import "./task-scope-header";
import "./todo-bulk-actions";
import "./tasks-calendar";
import type {PopSelectOption} from "./pop-select";
import {
  todoSortOptions,
  todoStatusOptions,
  parseTodoSortKey,
} from "./todo-filter-options";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {
  tacheItemNewPath,
  tacheNewPath,
} from "../utils/tache-paths";

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
    css`
      :host {
        display: block;
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

      /* max-height calculé en JS depuis le top (viewport restant − bouton).
         Marge négative + padding : place pour le focus ring checkbox sans décaler l’alignement. */
      .todo-app-queue {
        min-height: 0;
        margin-inline: -0.5rem;
        padding-inline: 0.5rem;
        overflow-x: hidden;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: transparent transparent;
      }

      .todo-app-queue:hover {
        scrollbar-color: var(--sc-base-500) transparent;
      }

      .todo-app-queue::-webkit-scrollbar {
        width: 0.5rem;
        height: 0.5rem;
        border: solid 0.15rem transparent;
        border-radius: var(--sc-rounded);
        background: transparent;
      }

      .todo-app-queue::-webkit-scrollbar-thumb {
        transition: box-shadow 0.2s;
        border: solid 0.15rem transparent;
        border-radius: var(--sc-rounded);
        box-shadow: inset 0 0 0 0 transparent;
      }

      .todo-app-queue:hover::-webkit-scrollbar-thumb {
        box-shadow: inset 0 0 2rem 2rem var(--sc-base-800);
      }
    `,
  ];

  /** null / "" = liste racine ; sinon enfants (et sous-arbre) de cette tâche. */
  @property({type: String})
  parentId = "";

  @query(".todo-app-queue")
  private queueEl?: HTMLElement;

  @query(".todo-app-add")
  private addEl?: HTMLElement;

  private queueHeightRaf = 0;
  private layoutObserver?: ResizeObserver;

  @state()
  private tags: Tag[] = [];

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
    void this.reloadTags();
    this.syncFilterParent();
    window.addEventListener("resize", this.scheduleQueueMaxHeight);
  }

  disconnectedCallback() {
    window.removeEventListener("resize", this.scheduleQueueMaxHeight);
    this.layoutObserver?.disconnect();
    this.layoutObserver = undefined;
    if (this.queueHeightRaf) {
      cancelAnimationFrame(this.queueHeightRaf);
      this.queueHeightRaf = 0;
    }
    super.disconnectedCallback();
  }

  protected firstUpdated() {
    this.layoutObserver = new ResizeObserver(() => this.scheduleQueueMaxHeight());
    this.layoutObserver.observe(this);
    const layout = this.renderRoot.querySelector(".todo-app-layout");
    if (layout) this.layoutObserver.observe(layout);
    if (this.queueEl) this.layoutObserver.observe(this.queueEl);
    this.scheduleQueueMaxHeight();
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("parentId")) {
      this.syncFilterParent();
    }
    this.scheduleQueueMaxHeight();
  }

  /** Hauteur max = bas du main − top queue − bouton ; seulement si le contenu déborde. */
  private scheduleQueueMaxHeight = () => {
    if (this.queueHeightRaf) cancelAnimationFrame(this.queueHeightRaf);
    this.queueHeightRaf = requestAnimationFrame(() => {
      this.queueHeightRaf = 0;
      this.updateQueueMaxHeight();
    });
  };

  private updateQueueMaxHeight() {
    const queue = this.queueEl;
    if (!queue) return;

    const top = queue.getBoundingClientRect().top;
    const addHeight = this.addEl?.offsetHeight ?? 0;
    const layout = queue.parentElement;
    const gap = layout
      ? parseFloat(getComputedStyle(layout).rowGap || getComputedStyle(layout).gap) ||
        0
      : 0;

    const main = this.closest("main");
    let bottomLimit = window.innerHeight;
    if (main) {
      const padBottom = parseFloat(getComputedStyle(main).paddingBottom) || 0;
      bottomLimit = main.getBoundingClientRect().bottom - padBottom;
    }

    // Marge anti-subpixel / arrondis (évite un scroll fantôme avec 1–2 tâches).
    const slack = 8;
    const available = Math.floor(bottomLimit - top - addHeight - gap - slack);
    if (available <= 0) {
      queue.style.maxHeight = "";
      return;
    }

    if (queue.scrollHeight > available) {
      queue.style.maxHeight = `${available}px`;
    } else {
      queue.style.maxHeight = "";
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

  private async reloadTags() {
    const tags = await fetchTags();
    this.tags = tags;
    set(tagsListKey.path, tags);

    const selected = Array.isArray(this.filter?.tags) ? this.filter.tags : [];
    const valid = selected.filter((tagId) =>
      tags.some((tag) => tag.id === tagId),
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

  private get tagFilterOptions(): PopSelectOption[] {
    return [
      {
        value: "all",
        label: tx("tasks.filter.tags_all"),
        icon: "label",
        checksAll: true,
      },
      ...this.tags.map((tag) => ({
        value: tag.id,
        label: tag.name,
        icon: "label",
      })),
    ];
  }

  private get sortValue(): string {
    return (
      this.filter.sort ||
      `${this.filter.sortBy ?? "createdAt"}:${this.filter.sortDir ?? "desc"}`
    );
  }

  private get addHref(): string {
    const scopeId = this.parentId?.trim();
    return scopeId ? tacheItemNewPath(scopeId) : tacheNewPath();
  }

  private setViewMode(mode: TasksViewMode) {
    this.viewMode = mode;
    saveViewMode(mode);
    this.scheduleQueueMaxHeight();
  }

  private toggleViewMode() {
    this.setViewMode(this.viewMode === "calendar" ? "list" : "calendar");
  }

  private renderViewSwitch() {
    const isCalendar = this.viewMode === "calendar";
    const tip = isCalendar
      ? tx("tasks.view.list")
      : tx("tasks.view.calendar");
    return html`
      <sonic-tooltip label=${tip} placement="bottom">
        <sonic-button
          shape="circle"
          size="sm"
          variant="ghost"
          ?active=${isCalendar}
          data-aria-label=${tip}
          @click=${this.toggleViewMode}
        >
          <sonic-icon
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="calendar"
            size="sm"
            swap="on"
          ></sonic-icon>
          <sonic-icon
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="list"
            size="sm"
            swap="off"
          ></sonic-icon>
        </sonic-button>
      </sonic-tooltip>
    `;
  }

  render() {
    const base = getMockApiServiceUrl();
    const filterProvider = todosFilterKey.path;
    const selectedTags = Array.isArray(this.filter.tags)
      ? this.filter.tags
      : [];
    const isCalendar = this.viewMode === "calendar";

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
            <div
              class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
            >
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
                  name="filter"
                  size="sm"
                ></sonic-icon>
              </sonic-input>

              <div class="flex flex-wrap items-center gap-1.5 sm:justify-end">
                <pop-select
                  label=${tx("tasks.filter.status")}
                  name="status"
                  mode="radio"
                  .value=${this.filter.status}
                  .options=${todoStatusOptions()}
                  minWidth="11rem"
                ></pop-select>

                ${this.tags.length > 0
                  ? html`
                      <pop-select
                        label=${tx("tasks.filter.tag")}
                        name="tags"
                        mode="multi"
                        .value=${selectedTags}
                        .options=${this.tagFilterOptions}
                        minWidth="12rem"
                      ></pop-select>
                    `
                  : nothing}

                ${!isCalendar
                  ? html`
                      <pop-select
                        label=${tx("tasks.filter.sort")}
                        name="sort"
                        mode="radio"
                        .value=${this.sortValue}
                        .options=${todoSortOptions()}
                        minWidth="14rem"
                      ></pop-select>
                      <todo-bulk-actions
                        .filter=${this.filter}
                      ></todo-bulk-actions>
                    `
                  : nothing}

                ${this.renderViewSwitch()}
              </div>
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

        <div class="todo-app-add shrink-0 pt-1">
          <sonic-button
            href=${this.addHref}
            pushstate
            type="primary"
            size="sm"
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="plus"
              size="sm"
            ></sonic-icon>
            ${this.parentId?.trim() ? t("tasks.new_sub") : t("tasks.new")}
          </sonic-button>
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "todo-app": TodoApp;
  }
}
