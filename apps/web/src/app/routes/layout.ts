import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/tooltip";
import {html} from "lit";
import type {DirectiveResult} from "lit/directive.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import "../components/todo-search-modal";
import "../components/command-palette-modal";
import "../components/demo-tour-modal";
import "../components/demo-header-badge";
import "../components/main-nav-menu";
import type {CommandPaletteModal} from "../components/command-palette-modal";

function openCommandPalette() {
  const modal = document.querySelector("command-palette-modal") as
    | CommandPaletteModal
    | null;
  void modal?.open();
}

function goHome(event: Event) {
  event.preventDefault();
  if (location.pathname === "/") return;
  history.pushState(null, "", "/");
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

          <main-nav-menu slot="content"></main-nav-menu>
        </sonic-pop>

        <div class="flex min-w-0 shrink-0 items-center gap-1.5">
          <a
            href="/"
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
          label=${tx("nav.command_aria")}
          placement="bottom"
        >
          <sonic-button
            variant="ghost"
            data-aria-label=${tx("nav.command_aria")}
            @click=${openCommandPalette}
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
              >Ctrl+Shift+P</kbd
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
    <command-palette-modal></command-palette-modal>
    <demo-tour-modal></demo-tour-modal>
  </div>
`;
