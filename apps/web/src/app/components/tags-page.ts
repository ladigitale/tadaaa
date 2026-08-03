import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {css, html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {deleteTag, fetchTags, fetchTodos} from "../api/client";
import {countTodosByTag} from "../api/store-logic";
import type {Tag, Todo} from "../api/types";
import {set} from "../../utils/dataprovider";
import {tagsFilterKey, tagsListKey} from "../dp";
import {tf, tx} from "../i18n";
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
        /* Place pour le FAB fixe en bas de page. */
        padding-bottom: 4.5rem;
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

      .tags-add {
        pointer-events: none;
        position: fixed;
        inset-inline: 0;
        bottom: 0;
        z-index: 20;
        padding-bottom: max(0.75rem, env(safe-area-inset-bottom, 0px));
      }

      @media (min-width: 640px) {
        .tags-add {
          padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
        }
      }

      .tags-add-inner {
        pointer-events: auto;
        margin-inline: auto;
        max-width: 72rem;
        padding-inline: 0.75rem;
      }

      @media (min-width: 640px) {
        .tags-add-inner {
          padding-inline: 1rem;
        }
      }
    `,
  ];

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
      title: tx("tags.delete_title"),
      message: tf("tags.delete_confirm", {name: tag.name, n: count}),
      confirmLabel: tx("tags.delete"),
      danger: true,
    });
    if (!ok || this.busy) return;

    this.busy = true;
    try {
      await deleteTag(tag.id);
      await this.reload();
    } catch (error) {
      await showError(error);
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
              placeholder=${tx("tags.filter_ph")}
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
                  ${t("tags.empty_filtered")}
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

        <div class="tags-add">
          <div class="tags-add-inner">
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
              ${t("tags.new")}
            </sonic-button>
          </div>
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
