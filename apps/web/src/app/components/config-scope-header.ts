import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import type {ConfigSection} from "../utils/config-paths";
import {
  CONFIG_SECTIONS,
  groupForSection,
} from "../utils/config-sections";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tx} from "../i18n";
import tailwind from "../../css/tailwind";

/**
 * En-tête de page config : titre de section + menu limité à la catégorie.
 * Retour = goBack (plus de landing hub).
 */
@customElement("config-scope-header")
export class ConfigScopeHeader extends LitElement {
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
    `,
  ];

  @property()
  section: ConfigSection = "appearance";

  private get current() {
    return (
      CONFIG_SECTIONS.find((item) => item.id === this.section) ??
      CONFIG_SECTIONS[0]
    );
  }

  private get categoryItems() {
    return groupForSection(this.section)?.items ?? [this.current];
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

  render() {
    const current = this.current;
    const siblings = this.categoryItems;
    const showSwitcher = siblings.length > 1;

    return html`
      <div>
        <div class="mb-1 flex h-7 items-center gap-2 overflow-hidden">
          <nav
            class="flex min-w-0 items-center gap-0.5 text-sm"
            aria-label=${tx("config.back")}
          >
            <sonic-tooltip label=${tx("config.back")} placement="bottom">
              <sonic-button
                goBack
                shape="circle"
                variant="ghost"
                size="sm"
                class="shrink-0"
                data-aria-label=${tx("config.back")}
              >
                <sonic-icon
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name="nav-arrow-left"
                  size="sm"
                ></sonic-icon>
              </sonic-button>
            </sonic-tooltip>
          </nav>
        </div>
        <div class="min-w-0 space-y-1.5">
          <h1 class="scope-heading">${t(current.labelKey)}</h1>
          ${showSwitcher
            ? html`
                <sonic-pop class="inline-block" placement="bottom-start">
                  <sonic-button
                    size="xs"
                    variant="ghost"
                    class="scope-action-trigger text-neutral-500"
                    data-aria-label=${tx("config.section_menu_aria")}
                  >
                    <sonic-icon
                      slot="prefix"
                      library=${ICON_LIBRARY}
                      prefix=${ICON_PREFIX}
                      name=${current.icon}
                      size="sm"
                    ></sonic-icon>
                    ${t(current.labelKey)}
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
                    minWidth="15rem"
                  >
                    ${siblings.map(
                      (choice) => html`
                        <sonic-menu-item
                          href=${choice.href}
                          pushstate
                          ?active=${choice.id === current.id}
                        >
                          ${this.renderMenuItemIcon(choice.icon)}
                          ${t(choice.labelKey)}
                        </sonic-menu-item>
                      `,
                    )}
                  </sonic-menu>
                </sonic-pop>
              `
            : nothing}
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-scope-header": ConfigScopeHeader;
  }
}
