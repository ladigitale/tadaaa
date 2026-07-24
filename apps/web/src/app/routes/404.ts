import {html} from "lit";
import "@supersoniks/concorde/button";
import {t} from "@supersoniks/concorde/directives/Wording";
import {tx} from "../i18n";

export default function NotFoundPage() {
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
