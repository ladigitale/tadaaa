import {html, LitElement} from "lit";
import {customElement} from "lit/decorators.js";
import "@supersoniks/concorde/sonic-scope";
import {getMockApiServiceUrl} from "./app/api/config";
import {initApp} from "./app/init";
import {router} from "./app/routes/router";

@customElement("app-router-host")
export class AppRouterHost extends LitElement {
  protected createRenderRoot(): HTMLElement | DocumentFragment {
    return this;
  }

  connectedCallback() {
    super.connectedCallback();
    initApp();

    const path = document.location.pathname;
    if (!path || path === "/" || path === "/index.html") {
      history.replaceState(null, "", "/tache");
    }
  }

  render() {
    return html`
      <sonic-scope
        serviceURL=${getMockApiServiceUrl()}
        customIconLibraryPath="https://cdn.jsdelivr.net/npm/iconoir@7.10.1/icons/$prefix/$name.svg"
        customIconDefaultPrefix="regular"
      >
        ${router("")}
      </sonic-scope>
    `;
  }
}
