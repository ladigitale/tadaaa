import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/divider";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/tooltip";
import {html} from "lit";
import type {DirectiveResult} from "lit/directive.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {CONFIG_SECTION_GROUPS} from "../utils/config-sections";
import "../components/todo-search-modal";
import "../components/demo-tour-modal";
import "../components/demo-header-badge";
import type {TodoSearchModal} from "../components/todo-search-modal";

function openTodoSearch() {
  const modal = document.querySelector("todo-search-modal") as
    | TodoSearchModal
    | null;
  void modal?.open();
}

function goHome(event: Event) {
  event.preventDefault();
  if (location.pathname === "/tache") return;
  history.pushState(null, "", "/tache");
  window.dispatchEvent(new PopStateEvent("popstate"));
}

/**
 * pushstate stopPropagation dans le shadow du bouton : capture + hide différé.
 */
const closeMainNavPop = {
  capture: true,
  handleEvent(event: Event) {
    const menu = event.currentTarget as HTMLElement | null;
    const pop = menu?.closest("sonic-pop") as
      | (HTMLElement & {hide?: () => void})
      | null;
    queueMicrotask(() => pop?.hide?.());
  },
};

function menuItemIcon(name: string) {
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

export default (children: DirectiveResult) => html`
  <div
    class="flex flex-col overflow-hidden bg-neutral-0"
    style="height: 100vh"
  >
    <nav
      class="shrink-0 border-b-[.18rem] border-current bg-neutral-0"
      aria-label=${tx("nav.main_aria")}
    >
      <div
        class="mx-auto flex w-full max-w-6xl items-center gap-2 px-3 py-3 sm:gap-3 sm:px-4"
      >
        <sonic-pop class="inline-block shrink-0" placement="bottom-start">
          <sonic-tooltip
            label=${tx("nav.menu_aria")}
            placement="bottom"
          >
            <sonic-button
              shape="circle"
              size="sm"
              variant="ghost"
              data-aria-label=${tx("nav.menu_aria")}
            >
              <sonic-icon
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="menu"
                size="lg"
              ></sonic-icon>
            </sonic-button>
          </sonic-tooltip>

          <sonic-menu
            slot="content"
            direction="column"
            align="left"
            size="sm"
            minWidth="14rem"
            @click=${closeMainNavPop}
          >
            <sonic-menu-item href="/tache" pushstate autoActive="partial">
              ${menuItemIcon("list")}
              ${t("nav.tasks")}
            </sonic-menu-item>
            <sonic-menu-item href="/tags" pushstate autoActive="strict">
              ${menuItemIcon("label")}
              ${t("nav.tags")}
            </sonic-menu-item>

            ${CONFIG_SECTION_GROUPS.map(
              (group) => html`
                <sonic-divider
                  label=${tx(group.labelKey)}
                  align="left"
                  size="sm"
                ></sonic-divider>
                ${group.items.map(
                  (item) => html`
                    <sonic-menu-item
                      href=${item.href}
                      pushstate
                      autoActive="strict"
                    >
                      ${menuItemIcon(item.icon)}
                      ${t(item.labelKey)}
                    </sonic-menu-item>
                  `,
                )}
              `,
            )}
          </sonic-menu>
        </sonic-pop>

        <div class="flex min-w-0 shrink-0 items-center gap-1.5">
          <a
            href="/tache"
            class="flex items-center gap-1 text-neutral-900 no-underline"
            @click=${goHome}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="check-circle"
              size="2xl"
              aria-hidden="true"
            ></sonic-icon>
            <span
              class="font-semibold italic leading-none tracking-tight"
              style="font-size: 1.75rem"
              >Tadaaa</span
            >
          </a>
          <demo-header-badge></demo-header-badge>
        </div>

        <sonic-tooltip
          class="ml-auto"
          label=${tx("nav.search_aria")}
          placement="bottom"
        >
          <sonic-button
            variant="ghost"
            data-aria-label=${tx("nav.search_aria")}
            @click=${openTodoSearch}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="search"
              size="lg"
            ></sonic-icon>
            <span class="ml-1 hidden text-neutral-500 sm:inline"
              >${t("nav.search")}</span
            >
            <kbd
              class="ml-2 hidden rounded border border-neutral-200 bg-neutral-100 px-1.5 py-0.5 font-sans text-[0.65rem] text-neutral-500 sm:inline"
              >Ctrl+K</kbd
            >
          </sonic-button>
        </sonic-tooltip>
      </div>
    </nav>
    <main
      class="custom-scroll mx-auto w-full max-w-6xl flex-1 overflow-x-hidden overflow-y-auto px-3 py-3 sm:px-4 sm:py-4 md:py-5"
      style="min-height: 0"
    >
      ${children}
    </main>
    <todo-search-modal></todo-search-modal>
    <demo-tour-modal></demo-tour-modal>
  </div>
`;
