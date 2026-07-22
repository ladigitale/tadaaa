import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {css, html, LitElement} from "lit";
import {customElement, property} from "lit/decorators.js";
import type {Tag} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {todosFilterKey, type TodosFilter} from "../dp";
import {navigateTo} from "../utils/navigate";
import {TACHE_ROOT} from "../utils/tache-paths";
import {tagsItemEditPath} from "../utils/tag-paths";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import "./tag-badge";

@customElement("tag-row")
export class TagRow extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }
    `,
  ];

  @property({attribute: false})
  tag!: Tag;

  @property({type: Number})
  count = 0;

  @property({type: Boolean})
  disabled = false;

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

  private goToFilteredTodos() {
    const filter = read(todosFilterKey.path) as TodosFilter;
    set(todosFilterKey.path, {
      ...filter,
      q: "",
      status: "all",
      tags: [this.tag.id],
      parentId: "",
      recursive: true,
      _rev: (filter._rev ?? 0) + 1,
    });
    navigateTo(TACHE_ROOT);
  }

  private onDelete() {
    this.dispatchEvent(
      new CustomEvent("tag-delete", {
        bubbles: true,
        composed: true,
        detail: {tag: this.tag},
      }),
    );
  }

  private get tasksLabel(): string {
    return this.count <= 1 ? `${this.count} tâche` : `${this.count} tâches`;
  }

  render() {
    if (!this.tag) return html``;

    return html`
      <article class="py-4 sm:py-5">
        <div class="flex items-start gap-2 sm:gap-3">
          <div class="min-w-0 flex-1">
            <tag-badge .tag=${this.tag} size="sm"></tag-badge>
          </div>

          <div class="flex shrink-0 items-center gap-0.5">
            <sonic-button
              size="sm"
              variant="ghost"
              ?disabled=${this.disabled}
              @click=${this.goToFilteredTodos}
            >
              <sonic-icon
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="list"
                size="sm"
              ></sonic-icon>
              ${this.tasksLabel}
            </sonic-button>

            <sonic-pop class="inline-block" placement="bottom">
              <sonic-button
                shape="circle"
                size="sm"
                variant="ghost"
                ?disabled=${this.disabled}
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
                <sonic-menu-item
                  ?disabled=${this.disabled}
                  @click=${this.goToFilteredTodos}
                >
                  ${this.renderMenuItemIcon("filter")} ${this.tasksLabel}
                </sonic-menu-item>
                <sonic-menu-item
                  href=${tagsItemEditPath(this.tag.id)}
                  pushstate
                  ?disabled=${this.disabled}
                >
                  ${this.renderMenuItemIcon("edit-pencil")} Modifier
                </sonic-menu-item>
                <sonic-menu-item
                  type="danger"
                  ?disabled=${this.disabled}
                  @click=${this.onDelete}
                >
                  ${this.renderMenuItemIcon("trash")} Supprimer
                </sonic-menu-item>
              </sonic-menu>
            </sonic-pop>
          </div>
        </div>
      </article>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tag-row": TagRow;
  }
}
