import "@supersoniks/concorde/icon";
import {html, LitElement} from "lit";
import {customElement} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import {legalPath, type LegalDocId} from "../legal";
import {navigateTo} from "../utils/navigate";
import {tx} from "../i18n";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import tailwind from "../../css/tailwind";

const DOCS: LegalDocId[] = ["mentions", "privacy", "terms", "cookies"];
const GITHUB_REPO_URL = "https://github.com/ladigitale/tadaaa";

@customElement("legal-footer-links")
export class LegalFooterLinks extends LitElement {
  static styles = [tailwind];

  render() {
    return html`
      <nav
        class="flex flex-wrap justify-center gap-x-3 gap-y-1 text-xs text-neutral-500"
        aria-label=${tx("legal.nav_aria")}
      >
        ${DOCS.map((id) => {
          const href = legalPath(id);
          return html`
            <a
              href=${href}
              class="text-neutral-500 no-underline hover:underline"
              @click=${(e: Event) => {
                e.preventDefault();
                navigateTo(href);
              }}
              >${t(`legal.nav.${id}`)}</a
            >
          `;
        })}
        <a
          href=${GITHUB_REPO_URL}
          class="inline-flex items-center gap-1 text-neutral-500 no-underline hover:underline"
          target="_blank"
          rel="noopener noreferrer"
        >
          <sonic-icon
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="github"
            size="xs"
          ></sonic-icon>
          ${t("legal.nav.github")}
        </a>
      </nav>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "legal-footer-links": LegalFooterLinks;
  }
}
