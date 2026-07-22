import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {css, html, LitElement, nothing} from "lit";
import {customElement, query, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {deleteTag, fetchTags, fetchTodos} from "../api/client";
import {countTodosByTag} from "../api/store-logic";
import type {Tag, Todo} from "../api/types";
import {set} from "../../utils/dataprovider";
import {tagsFilterKey, tagsListKey} from "../dp";
import tailwind from "../../css/tailwind";
import {confirmDialog, showError} from "../utils/modal-dialog";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tagsNewPath} from "../utils/tag-paths";
import "./tag-row";
import "./tag-scope-header";

@customElement("tags-page")
export class TagsPage extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }

      .tags-layout {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
      }

      @media (min-width: 640px) {
        .tags-layout {
          gap: 1rem;
        }
      }

      .tags-list {
        min-height: 0;
        overflow-x: hidden;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: transparent transparent;
      }

      .tags-list:hover {
        scrollbar-color: var(--sc-base-500) transparent;
      }

      .tags-list::-webkit-scrollbar {
        width: 0.5rem;
        height: 0.5rem;
        border: solid 0.15rem transparent;
        border-radius: var(--sc-rounded);
        background: transparent;
      }

      .tags-list::-webkit-scrollbar-thumb {
        transition: box-shadow 0.2s;
        border: solid 0.15rem transparent;
        border-radius: var(--sc-rounded);
        box-shadow: inset 0 0 0 0 transparent;
      }

      .tags-list:hover::-webkit-scrollbar-thumb {
        box-shadow: inset 0 0 2rem 2rem var(--sc-base-800);
      }
    `,
  ];

  @query(".tags-list")
  private listEl?: HTMLElement;

  @query(".tags-add")
  private addEl?: HTMLElement;

  private listHeightRaf = 0;
  private layoutObserver?: ResizeObserver;

  @state()
  private tags: Tag[] = [];

  @state()
  private todos: Todo[] = [];

  @state()
  private busy = false;

  @subscribe(tagsFilterKey.q)
  @state()
  searchQuery = "";

  connectedCallback() {
    super.connectedCallback();
    void this.reload();
    window.addEventListener("resize", this.scheduleListMaxHeight);
  }

  disconnectedCallback() {
    window.removeEventListener("resize", this.scheduleListMaxHeight);
    this.layoutObserver?.disconnect();
    this.layoutObserver = undefined;
    if (this.listHeightRaf) {
      cancelAnimationFrame(this.listHeightRaf);
      this.listHeightRaf = 0;
    }
    super.disconnectedCallback();
  }

  protected firstUpdated() {
    this.layoutObserver = new ResizeObserver(() => this.scheduleListMaxHeight());
    this.layoutObserver.observe(this);
    const layout = this.renderRoot.querySelector(".tags-layout");
    if (layout) this.layoutObserver.observe(layout);
    if (this.listEl) this.layoutObserver.observe(this.listEl);
    this.scheduleListMaxHeight();
  }

  protected updated() {
    this.scheduleListMaxHeight();
  }

  private scheduleListMaxHeight = () => {
    if (this.listHeightRaf) cancelAnimationFrame(this.listHeightRaf);
    this.listHeightRaf = requestAnimationFrame(() => {
      this.listHeightRaf = 0;
      this.updateListMaxHeight();
    });
  };

  private updateListMaxHeight() {
    const list = this.listEl;
    if (!list) return;

    const top = list.getBoundingClientRect().top;
    const addHeight = this.addEl?.offsetHeight ?? 0;
    const layout = list.parentElement;
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

    const slack = 8;
    const available = Math.floor(bottomLimit - top - addHeight - gap - slack);
    if (available <= 0) {
      list.style.maxHeight = "";
      return;
    }

    if (list.scrollHeight > available) {
      list.style.maxHeight = `${available}px`;
    } else {
      list.style.maxHeight = "";
    }
  }

  private get filteredTags(): Tag[] {
    const needle = this.searchQuery?.trim().toLowerCase() ?? "";
    if (!needle) return this.tags;
    return this.tags.filter((tag) => tag.name.toLowerCase().includes(needle));
  }

  private async reload() {
    const [tags, todosResponse] = await Promise.all([
      fetchTags(),
      fetchTodos({status: "all", limit: 500, recursive: true}),
    ]);
    this.tags = tags;
    this.todos = todosResponse.data ?? [];
    set(tagsListKey.path, tags);
  }

  private async onDeleteTag(event: CustomEvent<{tag: Tag}>) {
    const tag = event.detail.tag;
    const count = countTodosByTag(this.todos, tag.id);
    const ok = await confirmDialog({
      title: "Supprimer l’étiquette",
      message: `Supprimer « ${tag.name} » ? Elle est utilisée par ${count} tâche(s).`,
      confirmLabel: "Supprimer",
      danger: true,
    });
    if (!ok || this.busy) return;

    this.busy = true;
    try {
      await deleteTag(tag.id);
      await this.reload();
    } catch (error) {
      await showError(error, "Impossible de supprimer l’étiquette");
      console.error(error);
    } finally {
      this.busy = false;
    }
  }

  private tagSeparator = () =>
    html`<div
      class="w-full bg-neutral-100"
      style="min-height: 2px"
      role="separator"
    ></div>`;

  render() {
    const filtered = this.filteredTags;
    const filterProvider = tagsFilterKey.path;

    return html`
      <div class="tags-layout">
        <section
          class="shrink-0 space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <tag-scope-header></tag-scope-header>
          <div formDataProvider=${filterProvider}>
            <sonic-input
              name="q"
              type="search"
              size="sm"
              placeholder="Filtrer les étiquettes"
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
          </div>
        </section>

        <div class="tags-list">
          ${filtered.length === 0
            ? html`
                <p class="py-12 text-sm italic text-neutral-500">
                  Aucune étiquette pour ces filtres.
                </p>
              `
            : html`
                <ul class="m-0 list-none p-0">
                  ${filtered.map(
                    (tag, index) => html`
                      <li>
                        ${index > 0 ? this.tagSeparator() : nothing}
                        <tag-row
                          .tag=${tag}
                          .count=${countTodosByTag(this.todos, tag.id)}
                          ?disabled=${this.busy}
                          @tag-delete=${this.onDeleteTag}
                        ></tag-row>
                      </li>
                    `,
                  )}
                </ul>
              `}
        </div>

        <div class="tags-add shrink-0 pt-1">
          <sonic-button
            href=${tagsNewPath()}
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
            Nouvelle étiquette
          </sonic-button>
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tags-page": TagsPage;
  }
}
