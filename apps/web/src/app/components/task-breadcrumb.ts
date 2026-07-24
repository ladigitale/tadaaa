import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/tooltip";
import {html, LitElement, nothing} from "lit";
import {customElement, property} from "lit/decorators.js";
import type {TodoAncestor} from "../api/types";
import {tx} from "../i18n";
import {TACHE_ROOT, tacheItemPath} from "../utils/tache-paths";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {rmLinksTemplate} from "./rm-link-text";

const MAX_LABEL = 28;

export type BreadcrumbSegment = {
  label: string;
  href?: string;
  /** Nom complet de tâche → tooltip au survol */
  taskName?: string;
};

function truncateLabel(text: string, max = MAX_LABEL): string {
  const trimmed = text.trim();
  if (trimmed.length <= max) return trimmed;
  return `${trimmed.slice(0, Math.max(1, max - 1))}…`;
}

@customElement("task-breadcrumb")
export class TaskBreadcrumb extends LitElement {
  static styles = [tailwind];

  @property({attribute: false})
  ancestors: TodoAncestor[] = [];

  /** Segment courant (non lien), ex. titre de la tâche ou « Nouvelle tâche ». */
  @property()
  current = "";

  /**
   * Affiche le lien racine « Tâches principales » (uniquement hors racine,
   * quand on est déjà entré dans une tâche / écran enfant).
   */
  @property({type: Boolean})
  showRoot = false;

  /** Le segment `current` est un nom de tâche (tooltip avec le texte complet). */
  @property({type: Boolean})
  currentIsTask = false;

  private get segments(): BreadcrumbSegment[] {
    const items: BreadcrumbSegment[] = [];
    if (this.showRoot) {
      items.push({label: tx("tasks.root_heading"), href: TACHE_ROOT});
    }
    for (const ancestor of this.ancestors) {
      const full = ancestor.text.trim();
      items.push({
        label: truncateLabel(full),
        href: tacheItemPath(ancestor.id),
        taskName: full,
      });
    }
    const current = this.current.trim();
    if (current) {
      items.push({
        label: truncateLabel(current),
        taskName: this.currentIsTask ? current : undefined,
      });
    }
    return items;
  }

  private renderSegment(segment: BreadcrumbSegment) {
    const label = rmLinksTemplate(segment.label);
    const content = segment.href
      ? html`
          <sonic-button
            href=${segment.href}
            pushstate
            autoActive="disabled"
            variant="link"
            size="sm"
            class="max-w-[10rem] sm:max-w-[14rem]"
          >
            <span class="truncate">${label}</span>
          </sonic-button>
        `
      : html`
          <span
            class="max-w-[12rem] truncate px-1 text-sm font-medium text-neutral-900 sm:max-w-[16rem]"
            aria-current="page"
            >${label}</span
          >
        `;

    if (!segment.taskName) return content;

    return html`
      <sonic-tooltip label=${segment.taskName} placement="bottom">
        ${content}
      </sonic-tooltip>
    `;
  }

  render() {
    const segments = this.segments;
    if (segments.length === 0) return nothing;

    return html`
      <nav
        class="flex min-w-0 flex-wrap items-center gap-0.5 text-sm"
        aria-label=${tx("common.breadcrumb_aria")}
      >
        <sonic-tooltip label=${tx("common.back")} placement="bottom">
          <sonic-button
            goBack
            shape="circle"
            variant="ghost"
            size="sm"
            class="shrink-0"
            data-aria-label=${tx("common.back")}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="nav-arrow-left"
              size="sm"
            ></sonic-icon>
          </sonic-button>
        </sonic-tooltip>
        ${segments.map(
          (segment, index) => html`
            ${index > 0
              ? html`
                  <sonic-icon
                    library=${ICON_LIBRARY}
                    prefix=${ICON_PREFIX}
                    name="nav-arrow-right"
                    size="sm"
                    class="shrink-0 text-neutral-400"
                  ></sonic-icon>
                `
              : nothing}
            ${this.renderSegment(segment)}
          `,
        )}
      </nav>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "task-breadcrumb": TaskBreadcrumb;
  }
}
