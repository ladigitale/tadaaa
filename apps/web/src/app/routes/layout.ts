import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/tooltip";
import {html} from "lit";
import type {DirectiveResult} from "lit/directive.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {getMockApiServiceUrl} from "../api/config";
import {tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import "../components/tags-list-loader";
import "../components/todos-catalog-loader";
import "../components/todo-search-modal";
import "../components/command-palette-modal";
import "../components/demo-tour-modal";
import "../components/main-nav-menu";
import "../components/legal-footer-links";
import type {CommandPaletteModal} from "../components/command-palette-modal";
import {tadaaaBrand} from "../brand/tadaaa-logo";
import {isAccountConnected} from "../account-settings";
import {shortcuts} from "../shortcuts";
import {TACHE_ROOT} from "../utils/tache-paths";

function openCommandPalette() {
  const modal = document.querySelector("command-palette-modal") as
    | CommandPaletteModal
    | null;
  void modal?.open();
}

/** Logo home : tâches racines si connecté, landing invite sinon. */
function goHome(event: Event) {
  event.preventDefault();
  const target = isAccountConnected() ? TACHE_ROOT : "/";
  if (location.pathname === target) return;
  history.pushState(null, "", target);
  window.dispatchEvent(new PopStateEvent("popstate"));
}

export default (children: DirectiveResult) => html`
  <div
    class="app-shell flex flex-col overflow-hidden bg-neutral-0"
    style="height: 100vh"
    serviceURL=${getMockApiServiceUrl()}
  >
    <tags-list-loader></tags-list-loader>
    <todos-catalog-loader></todos-catalog-loader>
    <nav
      class="app-shell-chrome shrink-0 border-b-[.18rem] border-current bg-neutral-0"
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
            href=${isAccountConnected() ? TACHE_ROOT : "/"}
            class="flex items-center text-content no-underline"
            @click=${goHome}
          >
            ${tadaaaBrand({size: "sm"})}
          </a>
        </div>

        <sonic-tooltip
          class="ml-auto"
          label=${shortcuts.withHint(tx("nav.command_aria"), "commandPalette")}
          placement="bottom"
        >
          <sonic-button
            variant="ghost"
            data-aria-label=${shortcuts.withHint(
              tx("nav.command_aria"),
              "commandPalette",
            )}
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
          </sonic-button>
        </sonic-tooltip>
      </div>
    </nav>
    <main
      class="app-shell-main custom-scroll relative z-[1] mx-auto w-full max-w-6xl flex-1 overflow-x-hidden overflow-y-auto px-3 py-3 sm:px-4 sm:py-4 md:py-5"
      style="min-height: 0"
    >
      ${children}
    </main>
    <footer
      class="app-shell-chrome shrink-0 border-t border-neutral-200 px-3 py-2 sm:px-4"
    >
      <legal-footer-links></legal-footer-links>
    </footer>
    <todo-search-modal></todo-search-modal>
    <command-palette-modal></command-palette-modal>
    <demo-tour-modal></demo-tour-modal>
  </div>
`;
