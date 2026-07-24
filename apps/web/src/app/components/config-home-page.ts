import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement} from "lit";
import {customElement} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {CONFIG_SECTION_GROUPS} from "../utils/config-sections";
import {navigateTo} from "../utils/navigate";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tx} from "../i18n";
import tailwind from "../../css/tailwind";
import "./page-shell";

@customElement("config-home-page")
export class ConfigHomePage extends LitElement {
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

      .section-link {
        display: flex;
        width: 100%;
        align-items: center;
        gap: 0.875rem;
        padding: 0.875rem 0.25rem;
        margin: 0;
        border: 0;
        border-radius: 0.25rem;
        background: transparent;
        color: inherit;
        font: inherit;
        text-align: left;
        cursor: pointer;
        transition: background-color 0.15s ease;
      }

      .section-link:hover {
        background-color: color-mix(
          in srgb,
          var(--sc-base-content) 6%,
          transparent
        );
      }

      .section-link:focus {
        outline: none;
      }

      .section-link:focus-visible {
        box-shadow: 0 0 0 2px var(--sc-primary);
      }

      .section-icon {
        display: flex;
        height: 2.5rem;
        width: 2.5rem;
        flex-shrink: 0;
        align-items: center;
        justify-content: center;
        border: 0.12rem solid currentColor;
        border-radius: 0.35rem;
      }
    `,
  ];

  private onOpenSection = (href: string) => (event: MouseEvent) => {
    event.preventDefault();
    navigateTo(href);
  };

  render() {
    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
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
            <h1 class="scope-heading">${t("config.title")}</h1>
            <p class="text-sm text-neutral-600">${t("config.intro")}</p>
          </div>
        </div>

        <div class="mt-8 space-y-10">
          ${CONFIG_SECTION_GROUPS.map(
            (group) => html`
              <section class="space-y-1">
                <h2
                  class="px-1 text-xs font-semibold uppercase tracking-wide text-neutral-500"
                >
                  ${t(group.labelKey)}
                </h2>
                <ul class="m-0 list-none divide-y divide-current/10 p-0">
                  ${group.items.map(
                    (item) => html`
                      <li>
                        <button
                          type="button"
                          class="section-link"
                          @click=${this.onOpenSection(item.href)}
                        >
                          <span class="section-icon" aria-hidden="true">
                            <sonic-icon
                              library=${ICON_LIBRARY}
                              prefix=${ICON_PREFIX}
                              name=${item.icon}
                              size="sm"
                            ></sonic-icon>
                          </span>
                          <span class="min-w-0 flex-1">
                            <span class="block font-medium text-neutral-900"
                              >${t(item.labelKey)}</span
                            >
                            <span class="mt-0.5 block text-sm text-neutral-600"
                              >${t(item.descriptionKey)}</span
                            >
                          </span>
                          <sonic-icon
                            class="shrink-0 text-neutral-400"
                            library=${ICON_LIBRARY}
                            prefix=${ICON_PREFIX}
                            name="nav-arrow-right"
                            size="sm"
                          ></sonic-icon>
                        </button>
                      </li>
                    `,
                  )}
                </ul>
              </section>
            `,
          )}
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-home-page": ConfigHomePage;
  }
}
