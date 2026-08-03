import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/tooltip";
import {html, LitElement, nothing} from "lit";
import {customElement, property} from "lit/decorators.js";
import {tx} from "../i18n";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";

/**
 * Navigation entre tâches sœurs : précédent / décompte / suivant.
 * Affiché seulement s’il y a au moins 2 tâches dans le contexte.
 */
@customElement("task-sibling-nav")
export class TaskSiblingNav extends LitElement {
  static styles = [tailwind];

  /** Index 1-based de la tâche courante. */
  @property({type: Number})
  index = 0;

  @property({type: Number})
  total = 0;

  @property()
  previousHref = "";

  @property()
  nextHref = "";

  render() {
    if (this.total < 2 || this.index < 1) return nothing;

    const hasPrevious = Boolean(this.previousHref);
    const hasNext = Boolean(this.nextHref);

    return html`
      <div
        class="flex shrink-0 items-center gap-0.5"
        role="navigation"
        aria-label=${tx("tasks.siblings_aria")}
      >
        <sonic-tooltip label=${tx("tasks.prev")} placement="bottom">
          <sonic-button
            shape="circle"
            variant="ghost"
            size="sm"
            ?disabled=${!hasPrevious}
            href=${hasPrevious ? this.previousHref : nothing}
            ?pushstate=${hasPrevious}
            data-aria-label=${tx("tasks.prev")}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="nav-arrow-left"
              size="sm"
            ></sonic-icon>
          </sonic-button>
        </sonic-tooltip>

        <span
          class="min-w-[2.75rem] px-0.5 text-center text-xs tabular-nums text-neutral-500"
          aria-live="polite"
          >${this.index}&nbsp;/&nbsp;${this.total}</span
        >

        <sonic-tooltip label=${tx("tasks.next")} placement="bottom">
          <sonic-button
            shape="circle"
            variant="ghost"
            size="sm"
            ?disabled=${!hasNext}
            href=${hasNext ? this.nextHref : nothing}
            ?pushstate=${hasNext}
            data-aria-label=${tx("tasks.next")}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="nav-arrow-right"
              size="sm"
            ></sonic-icon>
          </sonic-button>
        </sonic-tooltip>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "task-sibling-nav": TaskSiblingNav;
  }
}
