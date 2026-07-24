import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/divider";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement} from "lit";
import {customElement, property} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {CONFIG_ROOT, type ConfigSection} from "../utils/config-paths";
import {
  CONFIG_SECTION_GROUPS,
  CONFIG_SECTIONS,
} from "../utils/config-sections";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tx} from "../i18n";
import tailwind from "../../css/tailwind";

/**
 * En-tête config : titre + menu d’actions (sonic-pop), comme task/tag scope.
 * Le retour mène à la landing `/config`.
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
    return CONFIG_SECTIONS.find((item) => item.id === this.section) ?? CONFIG_SECTIONS[0];
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

    return html`
      <div>
        <div class="mb-1 flex h-7 items-center gap-2 overflow-hidden">
          <nav
            class="flex min-w-0 items-center gap-0.5 text-sm"
            aria-label=${tx("config.back")}
          >
            <sonic-tooltip
              label=${tx("config.back_home")}
              placement="bottom"
            >
              <sonic-button
                href=${CONFIG_ROOT}
                pushstate
                shape="circle"
                variant="ghost"
                size="sm"
                class="shrink-0"
                data-aria-label=${tx("config.back_home")}
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
          <h1 class="scope-heading">${t("config.title")}</h1>
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
              ${CONFIG_SECTION_GROUPS.map(
                (group) => html`
                  <sonic-divider
                    label=${tx(group.labelKey)}
                    align="left"
                    size="sm"
                  ></sonic-divider>
                  ${group.items.map(
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
                `,
              )}
            </sonic-menu>
          </sonic-pop>
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
