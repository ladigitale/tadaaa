import {html} from "lit";
import {navigateTo} from "../utils/navigate";

/** Redirection vers la liste des tâches. */
export default function HomePage() {
  queueMicrotask(() => navigateTo("/tache", true));
  return html``;
}
