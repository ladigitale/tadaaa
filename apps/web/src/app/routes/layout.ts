import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/tooltip";
import {html} from "lit";
import type {DirectiveResult} from "lit/directive.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
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
        class="mx-auto flex w-full max-w-6xl items-center gap-6 px-3 py-3 sm:gap-8 sm:px-4"
      >
        <div class="flex shrink-0 items-center gap-1.5">
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

        <sonic-menu direction="row" align="left" size="sm">
          <sonic-menu-item href="/tache" pushstate autoActive="partial">
            <sonic-icon
              slot="prefix"
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="list"
              size="sm"
            ></sonic-icon>
            ${t("nav.tasks")}
          </sonic-menu-item>
          <sonic-menu-item href="/tags" pushstate autoActive="strict">
            <sonic-icon
              slot="prefix"
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="label"
              size="sm"
            ></sonic-icon>
            ${t("nav.tags")}
          </sonic-menu-item>
          <sonic-menu-item href="/config" pushstate autoActive="partial">
            <sonic-icon
              slot="prefix"
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="settings"
              size="sm"
            ></sonic-icon>
            ${t("nav.config")}
          </sonic-menu-item>
        </sonic-menu>

        <sonic-tooltip
          class="ml-auto"
          label=${tx("nav.search_aria")}
          placement="bottom"
        >
          <sonic-button
            variant="ghost"
            size="sm"
            data-aria-label=${tx("nav.search_aria")}
            @click=${openTodoSearch}
          >
            <sonic-icon
              library=${ICON_LIBRARY}
              prefix=${ICON_PREFIX}
              name="search"
              size="sm"
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
      class="mx-auto flex w-full max-w-6xl flex-col overflow-hidden px-3 py-3 sm:px-4 sm:py-4 md:py-5"
      style="flex: 1 1 0; min-height: 0"
    >
      ${children}
    </main>
    <todo-search-modal></todo-search-modal>
    <demo-tour-modal></demo-tour-modal>
  </div>
`;
