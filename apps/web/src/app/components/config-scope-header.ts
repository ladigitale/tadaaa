import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import {css, html, LitElement} from "lit";
import {customElement, property} from "lit/decorators.js";
import {
  configSectionPath,
  type ConfigSection,
} from "../utils/config-paths";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import tailwind from "../../css/tailwind";

type SectionChoice = {
  id: ConfigSection;
  label: string;
  icon: string;
};

const SECTIONS: SectionChoice[] = [
  {id: "account", label: "Compte cloud", icon: "user"},
  {id: "issues", label: "Liens d’issues", icon: "link"},
  {id: "data", label: "Export / import", icon: "page"},
  {id: "p2p", label: "Partage P2P", icon: "share-android"},
  {id: "datasets", label: "Jeux de données", icon: "database"},
  {id: "maintenance", label: "Maintenance", icon: "trash"},
];

/**
 * En-tête config : titre + menu d’actions (sonic-pop), comme task/tag scope.
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
  section: ConfigSection = "issues";

  private get current(): SectionChoice {
    return SECTIONS.find((item) => item.id === this.section) ?? SECTIONS[0];
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
            aria-label="Fil d’Ariane"
          >
            <sonic-button
              goBack
              shape="circle"
              variant="ghost"
              size="sm"
              class="shrink-0"
              data-aria-label="Retour"
              title="Retour"
            >
              <sonic-icon
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="nav-arrow-left"
                size="sm"
              ></sonic-icon>
            </sonic-button>
          </nav>
        </div>
        <div class="min-w-0 space-y-1.5">
          <h1 class="scope-heading">Configuration</h1>
          <sonic-pop class="inline-block" placement="bottom-start">
            <sonic-button
              size="xs"
              variant="ghost"
              class="scope-action-trigger text-neutral-500"
              data-aria-label="Section configuration"
            >
              <sonic-icon
                slot="prefix"
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name=${current.icon}
                size="sm"
              ></sonic-icon>
              ${current.label}
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
              minWidth="14rem"
            >
              ${SECTIONS.map(
                (choice) => html`
                  <sonic-menu-item
                    href=${configSectionPath(choice.id)}
                    pushstate
                    ?active=${choice.id === current.id}
                  >
                    ${this.renderMenuItemIcon(choice.icon)}
                    ${choice.label}
                  </sonic-menu-item>
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
