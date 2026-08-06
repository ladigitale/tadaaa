import {html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {fetchLegalConfig} from "../cloud-api/client";
import {tf, tx} from "../i18n";
import {
  EMPTY_LEGAL_CONFIG,
  legalPath,
  type LegalDocId,
  type LegalPublicConfig,
} from "../legal";
import {navigateTo} from "../utils/navigate";
import tailwind from "../../css/tailwind";
import "./page-shell";

const DOC_PARAS: Record<LegalDocId, string[]> = {
  mentions: [
    "legal.mentions.intro",
    "legal.mentions.editor",
    "legal.mentions.director",
    "legal.mentions.host",
    "legal.mentions.contact",
  ],
  privacy: [
    "legal.privacy.intro",
    "legal.privacy.controller",
    "legal.privacy.purposes",
    "legal.privacy.legal_bases",
    "legal.privacy.recipients",
    "legal.privacy.retention",
    "legal.privacy.rights",
    "legal.privacy.contact",
  ],
  terms: [
    "legal.terms.intro",
    "legal.terms.service",
    "legal.terms.account",
    "legal.terms.responsibilities",
    "legal.terms.termination",
    "legal.terms.law",
  ],
  cookies: [
    "legal.cookies.intro",
    "legal.cookies.local",
    "legal.cookies.third_parties",
    "legal.cookies.manage",
  ],
};

@customElement("legal-document-page")
export class LegalDocumentPage extends LitElement {
  static styles = [tailwind];

  @property({type: String})
  doc: LegalDocId = "mentions";

  @state()
  private legal: LegalPublicConfig = EMPTY_LEGAL_CONFIG;

  @state()
  private loading = true;

  @state()
  private loadError = false;

  connectedCallback() {
    super.connectedCallback();
    void this.load();
  }

  private async load() {
    this.loading = true;
    this.loadError = false;
    try {
      this.legal = await fetchLegalConfig();
    } catch {
      this.legal = EMPTY_LEGAL_CONFIG;
      this.loadError = true;
    } finally {
      this.loading = false;
    }
  }

  private vars(): Record<string, string> {
    const l = this.legal;
    const dash = tx("legal.placeholder");
    return {
      publisherName: l.publisherName || dash,
      publisherEmail: l.publisherEmail || dash,
      publisherAddress: l.publisherAddress || dash,
      siret: l.siret || dash,
      hostName: l.hostName || dash,
      hostAddress: l.hostAddress || dash,
      hostContact: l.hostContact || dash,
      privacyEmail: l.privacyEmail || dash,
    };
  }

  private renderNav() {
    const docs: LegalDocId[] = ["mentions", "privacy", "terms", "cookies"];
    return html`
      <nav class="flex flex-wrap gap-x-3 gap-y-1 text-sm" aria-label=${tx("legal.nav_aria")}>
        ${docs.map((id) => {
          const href = legalPath(id);
          const active = id === this.doc;
          return html`
            <a
              href=${href}
              class=${active
                ? "font-medium text-neutral-900 underline"
                : "text-neutral-600 no-underline hover:underline"}
              @click=${(e: Event) => {
                e.preventDefault();
                navigateTo(href);
              }}
              >${t(`legal.nav.${id}`)}</a
            >
          `;
        })}
      </nav>
    `;
  }

  render() {
    const titleKey = `legal.${this.doc}.title`;
    const paras = DOC_PARAS[this.doc] ?? [];
    const vars = this.vars();

    return html`
      <page-shell>
        <div class="mx-auto max-w-2xl space-y-6 pt-2">
          ${this.renderNav()}
          <h1 class="text-2xl font-semibold tracking-tight">${t(titleKey)}</h1>
          ${this.loading
            ? html`<p class="text-sm text-neutral-500">${t("common.loading")}</p>`
            : nothing}
          ${this.loadError
            ? html`<p class="text-sm text-amber-700">${t("legal.load_error")}</p>`
            : nothing}
          ${!this.loading && !this.legal.configured
            ? html`<p class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                ${t("legal.not_configured")}
              </p>`
            : nothing}
          ${this.doc === "mentions" && this.legal.siret
            ? html`<p class="text-sm leading-relaxed text-neutral-700">
                ${tf("legal.mentions.siret", vars)}
              </p>`
            : nothing}
          <div class="space-y-4 text-sm leading-relaxed text-neutral-700">
            ${paras.map(
              (key) => html`<p>${tf(key, vars)}</p>`,
            )}
          </div>
          <p class="text-xs text-neutral-500">${t("legal.disclaimer")}</p>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "legal-document-page": LegalDocumentPage;
  }
}
