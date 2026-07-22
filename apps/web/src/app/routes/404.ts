import {html} from "lit";
import "@supersoniks/concorde/button";

export default function NotFoundPage() {
  return html`
    <section class="space-y-4 py-8 text-center">
      <h2 class="text-2xl font-bold">404</h2>
      <p class="text-neutral-600">Page introuvable.</p>
      <sonic-button pushstate href="/tache" variant="primary">Retour à l’accueil</sonic-button>
    </section>
  `;
}
