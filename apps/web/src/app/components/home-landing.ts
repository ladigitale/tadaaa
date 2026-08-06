import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {css, html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  ACCOUNT_CHANGED_EVENT,
  isAccountConnected,
  loadAccountSettings,
} from "../account-settings";
import {refreshAccountSession} from "../cloud-api/client";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tx} from "../i18n";
import {configSectionPath} from "../utils/config-paths";
import {TACHE_ROOT} from "../utils/tache-paths";
import {hasSeenDemoTour, openDemoTour} from "../demo-tour";
import {navigateTo} from "../utils/navigate";
import {tadaaaBrand} from "../brand/tadaaa-logo";
import tailwind from "../../css/tailwind";
import "./landing-flow-canvas";
import "./legal-footer-links";

type AuthGate = "checking" | "guest" | "connected";

/**
 * Site root (`/`) for guests. Redirects to tasks only after a live session check.
 */
@customElement("home-landing")
export class HomeLanding extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
        position: relative;
        z-index: 1;
      }
      /* Guest stage is viewport-fixed — don't inflate main / create a scrollbar. */
      :host([data-guest]) {
        height: 0;
        overflow: visible;
      }
      /* Center in the full viewport (not main-below-header). */
      .landing-stage {
        position: fixed;
        inset: 0;
        z-index: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: safe center;
        box-sizing: border-box;
        padding: 1rem;
        pointer-events: none;
      }
      /* Opaque scrim so eccentric themes stay readable over the canvas */
      .landing-copy {
        position: relative;
        z-index: 1;
        width: 100%;
        max-width: 32rem;
        max-height: calc(100dvh - 2rem);
        overflow-x: hidden;
        overflow-y: auto;
        pointer-events: auto;
        color: var(--sc-base-content);
        background: color-mix(in srgb, var(--sc-base) 94%, transparent);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid
          color-mix(in srgb, var(--sc-base-content) 14%, transparent);
        border-radius: calc(var(--sc-rounded, 0.5rem) + 0.35rem);
        padding: 1.5rem 1.25rem;
        box-shadow: 0 12px 40px
          color-mix(in srgb, var(--sc-base-content) 8%, transparent);
      }
      @media (min-width: 640px) {
        .landing-copy {
          padding: 2rem 1.75rem;
        }
      }
      .landing-title {
        font-family: var(--sc-font-family-headings), sans-serif;
        color: var(--sc-base-content);
      }
      .landing-muted {
        color: color-mix(in srgb, var(--sc-base-content) 72%, var(--sc-base));
      }
      .landing-faint {
        color: color-mix(in srgb, var(--sc-base-content) 58%, var(--sc-base));
      }
    `,
  ];

  @state()
  private gate: AuthGate = "checking";

  private setLandingChrome(active: boolean) {
    if (active) {
      document.documentElement.setAttribute("data-landing", "");
      this.setAttribute("data-guest", "");
    } else {
      document.documentElement.removeAttribute("data-landing");
      this.removeAttribute("data-guest");
    }
  }

  private onAccountChanged = () => {
    if (isAccountConnected()) {
      this.gate = "connected";
      this.setLandingChrome(false);
      navigateTo(TACHE_ROOT, true);
      return;
    }
    this.gate = "guest";
    this.setLandingChrome(true);
  };

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    void this.resolveAuthGate();
  }

  disconnectedCallback() {
    window.removeEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    this.setLandingChrome(false);
    super.disconnectedCallback();
  }

  private async resolveAuthGate() {
    const local = loadAccountSettings();
    if (!local.token) {
      this.gate = "guest";
      this.setLandingChrome(true);
      return;
    }

    this.gate = "checking";
    this.setLandingChrome(false);
    try {
      const next = await refreshAccountSession(local);
      if (isAccountConnected(next)) {
        this.gate = "connected";
        this.setLandingChrome(false);
        navigateTo(TACHE_ROOT, true);
        return;
      }
    } catch {
      /* expired / offline — stay on landing as guest */
    }
    this.gate = "guest";
    this.setLandingChrome(true);
  }

  private onTryWithoutAccount = () => {
    navigateTo(TACHE_ROOT);
    if (!hasSeenDemoTour()) {
      queueMicrotask(() => openDemoTour());
    }
  };

  private actionIcon(name: string) {
    return html`
      <sonic-icon
        slot="prefix"
        library=${ICON_LIBRARY}
        prefix=${ICON_PREFIX}
        name=${name}
        size="md"
      ></sonic-icon>
    `;
  }

  render() {
    if (this.gate === "checking" || this.gate === "connected") {
      return html`
        <p class="landing-muted py-8 text-center text-sm" aria-live="polite">
          ${tx("home.landing.checking")}
        </p>
      `;
    }

    return html`
      <div class="landing-stage">
        <landing-flow-canvas></landing-flow-canvas>
        <div class="landing-copy flex flex-col gap-8">
          <div class="space-y-5">
            ${tadaaaBrand({size: "hero", className: "text-content"})}
            <div class="space-y-3">
              <h1
                class="landing-title text-2xl font-semibold tracking-tight sm:text-3xl"
              >
                ${t("home.landing.title")}
              </h1>
              <p class="landing-muted text-base leading-relaxed">
                ${t("home.landing.body")}
              </p>
              <ul class="landing-muted space-y-2 text-sm">
                <li class="flex gap-2">
                  <span aria-hidden="true">·</span>
                  <span>${t("home.landing.point_local")}</span>
                </li>
                <li class="flex gap-2">
                  <span aria-hidden="true">·</span>
                  <span>${t("home.landing.point_cloud")}</span>
                </li>
                <li class="flex gap-2">
                  <span aria-hidden="true">·</span>
                  <span>${t("home.landing.point_share")}</span>
                </li>
              </ul>
            </div>
          </div>

          <div class="flex flex-col gap-3">
            <sonic-button
              type="primary"
              size="lg"
              class="w-full justify-center"
              href=${configSectionPath("accountLogin")}
              pushstate
            >
              ${this.actionIcon("log-in")}
              ${t("account.login")}
            </sonic-button>
            <sonic-button
              type="neutral"
              variant="outline"
              size="lg"
              class="w-full justify-center"
              href=${configSectionPath("accountRegister")}
              pushstate
            >
              ${this.actionIcon("user-plus")}
              ${t("account.signup")}
            </sonic-button>
            <sonic-button
              variant="ghost"
              size="lg"
              class="w-full justify-center"
              @click=${this.onTryWithoutAccount}
            >
              ${this.actionIcon("play")}
              ${t("home.landing.try_local")}
            </sonic-button>
          </div>

          <p class="landing-faint text-center text-sm">
            ${t("home.landing.footnote")}
          </p>
          <legal-footer-links></legal-footer-links>
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "home-landing": HomeLanding;
  }
}
