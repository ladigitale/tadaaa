import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/input";
import "@supersoniks/concorde/checkbox";
import {html, LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {
  isAccountConnected,
  loadAccountSettings,
} from "../account-settings";
import {createAuthHandoff, fetchAppDatasets} from "../cloud-api/client";
import {loadThemeId} from "../theme";
import tailwind from "../../css/tailwind";
import "./config-scope-header";
import "./page-shell";

type SisterApp = {
  id: string;
  name: string;
  description: string;
  url: string;
  enabled: boolean;
};

const STORAGE_KEY = "tada-sister-apps";

const DEFAULT_APPS: SisterApp[] = [
  {
    id: "belts",
    name: "Ceintures",
    description:
      "Classroom skill-belt tracker (pupils × subjects, synthesis, optional Tadaaa sync). French UI only.",
    url: "http://localhost:3200",
    enabled: false,
  },
];

function loadApps(): SisterApp[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return DEFAULT_APPS.map((a) => ({...a}));
    const parsed = JSON.parse(raw) as SisterApp[];
    const byId = new Map(parsed.map((a) => [a.id, a]));
    return DEFAULT_APPS.map((def) => ({...def, ...byId.get(def.id)}));
  } catch {
    return DEFAULT_APPS.map((a) => ({...a}));
  }
}

function saveApps(apps: SisterApp[]) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(apps));
}

/**
 * Settings → Apps: enable/disable sister SPAs + SSO open.
 */
@customElement("config-apps-page")
export class ConfigAppsPage extends LitElement {
  static styles = [tailwind];

  @state()
  private apps: SisterApp[] = loadApps();

  @state()
  private beltsDatasets: {id: string; name: string}[] = [];

  @state()
  private busyOpen = false;

  connectedCallback() {
    super.connectedCallback();
    void this.loadBeltsDatasets();
  }

  private async loadBeltsDatasets() {
    if (!isAccountConnected()) return;
    try {
      this.beltsDatasets = await fetchAppDatasets("belts");
    } catch {
      this.beltsDatasets = [];
    }
  }

  private patch(id: string, patch: Partial<SisterApp>) {
    this.apps = this.apps.map((a) => (a.id === id ? {...a, ...patch} : a));
    saveApps(this.apps);
  }

  private async openWithSso(app: SisterApp) {
    const base = app.url.replace(/\/$/, "");
    const cloudUrl = new URL(`${base}/cloud`);
    cloudUrl.searchParams.set("theme", loadThemeId());
    const cloudHref = cloudUrl.toString();
    if (!isAccountConnected()) {
      window.location.assign(
        `/account/login?return_to=${encodeURIComponent(cloudHref)}`,
      );
      return;
    }
    this.busyOpen = true;
    try {
      const {code} = await createAuthHandoff(loadAccountSettings());
      cloudUrl.searchParams.set("handoff", code);
      window.location.assign(cloudUrl.toString());
    } catch {
      window.open(cloudHref, "_blank", "noopener,noreferrer");
    } finally {
      this.busyOpen = false;
    }
  }

  render() {
    return html`
      <page-shell>
        <config-scope-header
          section="apps"
          .title=${tx("config.section.apps")}
          .help=${tx("config.section.apps.help")}
        ></config-scope-header>

        <div class="mx-auto flex w-full max-w-2xl flex-col gap-4 px-3 py-4">
          <p class="text-sm opacity-70">${tx("apps.intro")}</p>
          ${this.apps.map(
            (app) => html`
              <div
                class="flex flex-col gap-3 rounded-lg border border-neutral-200 p-4 dark:border-neutral-700"
              >
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="font-semibold">${app.name}</div>
                    <div class="text-sm opacity-70">${app.description}</div>
                  </div>
                  <sonic-checkbox
                    label=${tx("apps.enabled")}
                    .checked=${app.enabled}
                    @change=${(e: Event) =>
                      this.patch(app.id, {
                        enabled: (e.target as HTMLInputElement).checked,
                      })}
                  ></sonic-checkbox>
                </div>
                <sonic-input
                  label=${tx("apps.url")}
                  .value=${app.url}
                  @change=${(e: Event) =>
                    this.patch(app.id, {
                      url: (e.target as HTMLInputElement).value,
                    })}
                ></sonic-input>
                ${app.enabled
                  ? html`
                      <sonic-button
                        type="success"
                        ?disabled=${this.busyOpen}
                        @click=${() => this.openWithSso(app)}
                      >
                        <sonic-icon
                          library=${ICON_LIBRARY}
                          prefix=${ICON_PREFIX}
                          name="open-new-window"
                          slot="prefix"
                        ></sonic-icon>
                        ${tx("apps.open_sso")}
                      </sonic-button>
                      ${app.id === "belts" && this.beltsDatasets.length
                        ? html`<div class="text-sm opacity-80">
                            <div class="font-medium mb-1">
                              ${tx("apps.belts_datasets")}
                            </div>
                            <ul class="list-disc pl-5">
                              ${this.beltsDatasets.map(
                                (d) => html`<li>${d.name}</li>`,
                              )}
                            </ul>
                          </div>`
                        : ""}
                    `
                  : ""}
              </div>
            `,
          )}
        </div>
      </page-shell>
    `;
  }
}
