import {html} from "lit";
import "@supersoniks/concorde/button";
import {t} from "@supersoniks/concorde/directives/Wording";
import {tx} from "../i18n";
import {LEGACY_CONFIG_REDIRECTS} from "../utils/config-paths";
import {legacyConfigRedirect} from "../utils/legacy-redirect";

function normalizePath(pathname: string): string {
  if (pathname.length > 1 && pathname.endsWith("/")) {
    return pathname.slice(0, -1);
  }
  return pathname || "/";
}

export default function NotFoundPage() {
  const path = normalizePath(location.pathname);
  if (path === "/config" || path.startsWith("/config/")) {
    if (LEGACY_CONFIG_REDIRECTS[path] || path === "/config") {
      return legacyConfigRedirect(path);
    }
  }

  return html`
    <section class="space-y-4 py-8 text-center">
      <h2 class="text-2xl font-bold">404</h2>
      <p class="text-neutral-600">${t("errors.not_found")}</p>
      <sonic-button pushstate href="/tache" variant="primary"
        >${tx("errors.back_home")}</sonic-button
      >
    </section>
  `;
}
