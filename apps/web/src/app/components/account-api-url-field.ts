import "@supersoniks/concorde/input";
import {html, LitElement} from "lit";
import {customElement} from "lit/decorators.js";
import {tx} from "../i18n";
import {appConfigKey} from "../dp";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";

/** Shared API base URL field — keep the provided default in normal use. */
@customElement("account-api-url-field")
export class AccountApiUrlField extends LitElement {
  static styles = [tailwind, formLabelStyles];

  render() {
    return html`
      <sonic-input
        formDataProvider=${appConfigKey.path}
        name="accountApiBaseUrl"
        label=${tx("account.api_url")}
        description=${tx("account.api_url_help")}
        placeholder=${tx("account.api_url_ph")}
      ></sonic-input>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "account-api-url-field": AccountApiUrlField;
  }
}
