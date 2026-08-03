import {html} from "lit";
import "../components/home-landing";
import "../components/page-shell";

/** Site root: guest landing, or redirect to tasks when signed in. */
export default function HomePage() {
  return html`
    <page-shell>
      <home-landing></home-landing>
    </page-shell>
  `;
}
