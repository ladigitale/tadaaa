import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {html, LitElement} from "lit";
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
import tailwind from "../../css/tailwind";

type AuthGate = "checking" | "guest" | "connected";

/**
 * Site root (`/`) for guests. Redirects to tasks only after a live session check.
 */
@customElement("home-landing")
export class HomeLanding extends LitElement {
  static styles = [tailwind];

  @state()
  private gate: AuthGate = "checking";

  private onAccountChanged = () => {
    if (isAccountConnected()) {
      this.gate = "connected";
      navigateTo(TACHE_ROOT, true);
      return;
    }
    this.gate = "guest";
  };

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    void this.resolveAuthGate();
  }

  disconnectedCallback() {
    window.removeEventListener(ACCOUNT_CHANGED_EVENT, this.onAccountChanged);
    super.disconnectedCallback();
  }

  private async resolveAuthGate() {
    const local = loadAccountSettings();
    if (!local.token) {
      this.gate = "guest";
      return;
    }

    this.gate = "checking";
    try {
      const next = await refreshAccountSession(local);
      if (isAccountConnected(next)) {
        this.gate = "connected";
        navigateTo(TACHE_ROOT, true);
        return;
      }
    } catch {
      /* expired / offline — stay on landing as guest */
    }
    this.gate = "guest";
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
        <p class="py-8 text-center text-sm text-neutral-500" aria-live="polite">
          ${tx("home.landing.checking")}
        </p>
      `;
    }

    return html`
      <div class="mx-auto flex max-w-lg flex-col gap-8 pt-2">
        <div class="space-y-3">
          <p class="text-sm font-medium uppercase tracking-wide text-neutral-500">
            ${t("home.landing.eyebrow")}
          </p>
          <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
            ${t("home.landing.title")}
          </h1>
          <p class="text-base leading-relaxed text-neutral-600">
            ${t("home.landing.body")}
          </p>
          <ul class="space-y-2 text-sm text-neutral-600">
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

        <p class="text-center text-sm text-neutral-500">
          ${t("home.landing.footnote")}
        </p>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "home-landing": HomeLanding;
  }
}
