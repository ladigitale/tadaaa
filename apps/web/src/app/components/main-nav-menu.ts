import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/divider";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/badge";
import {html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  ACCOUNT_CHANGED_EVENT,
  isAccountConnected,
  loadAccountSettings,
} from "../account-settings";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tx} from "../i18n";
import {CONFIG_SECTION_GROUPS} from "../utils/config-sections";
import {NAV_SECTIONS} from "../utils/nav-sections";
import {configSectionPath} from "../utils/config-paths";

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

@customElement("main-nav-menu")
export class MainNavMenu extends LitElement {
  @state()
  private connected = isAccountConnected();

  @state()
  private email = loadAccountSettings().user?.email ?? "";

  /** Light DOM: slotted into sonic-pop; closest(sonic-pop) must work from menu clicks. */
  protected createRenderRoot() {
    return this;
  }

  private onAccountChanged = () => {
    const account = loadAccountSettings();
    this.connected = isAccountConnected(account);
    this.email = account.user?.email ?? "";
  };

  connectedCallback() {
    super.connectedCallback();
    this.onAccountChanged();
    window.addEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
  }

  disconnectedCallback() {
    window.removeEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    super.disconnectedCallback();
  }

  private menuItemIcon(name: string) {
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

  private renderAccountBlock() {
    const sessionHref = configSectionPath("account");
    const loginHref = configSectionPath("accountLogin");
    const registerHref = configSectionPath("accountRegister");
    return html`
      <sonic-divider
        label=${tx("nav.group.account")}
        align="left"
        size="sm"
      ></sonic-divider>
      ${this.connected
        ? html`
            <sonic-menu-item href=${sessionHref} pushstate autoActive="strict">
              ${this.menuItemIcon("user")}
              ${t("config.section.account")}
            </sonic-menu-item>
            <p
              class="truncate px-3 pb-1 text-xs text-neutral-500"
              title=${this.email}
            >
              ${this.email}
            </p>
          `
        : html`
            <sonic-menu-item href=${loginHref} pushstate autoActive="strict">
              ${this.menuItemIcon("log-in")}
              ${t("account.login")}
              <sonic-badge slot="suffix" type="info" size="2xs"
                >${t("account.cta.badge")}</sonic-badge
              >
            </sonic-menu-item>
            <sonic-menu-item
              href=${registerHref}
              pushstate
              autoActive="strict"
            >
              ${this.menuItemIcon("user-plus")}
              ${t("account.signup")}
            </sonic-menu-item>
          `}
    `;
  }

  render() {
    const otherGroups = CONFIG_SECTION_GROUPS.filter(
      (group) => group.id !== "account",
    );

    return html`
      <sonic-menu
        direction="column"
        align="left"
        size="sm"
        minWidth="14rem"
        @click=${closeMainNavPop}
      >
        ${this.renderAccountBlock()}

        <sonic-divider align="left" size="sm"></sonic-divider>

        ${NAV_SECTIONS.map(
          (item) => html`
            <sonic-menu-item
              href=${item.href}
              pushstate
              autoActive=${item.id === "tasks" ? "partial" : "strict"}
            >
              ${this.menuItemIcon(item.icon)}
              ${t(item.labelKey)}
            </sonic-menu-item>
          `,
        )}

        ${otherGroups.map(
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
                  ${this.menuItemIcon(item.icon)}
                  ${t(item.labelKey)}
                </sonic-menu-item>
              `,
            )}
          `,
        )}
      </sonic-menu>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "main-nav-menu": MainNavMenu;
  }
}
