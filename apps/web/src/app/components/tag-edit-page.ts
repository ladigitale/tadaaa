import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import "@supersoniks/concorde/tooltip";
import {html, LitElement} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {
  get,
  patch,
  subscribe,
  type ApiResult,
} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  apiResultError,
  endpoints,
  readApiData,
  type ApiData,
} from "../api/endpoints";
import {TAG_COLORS} from "../api/store-logic";
import type {Tag, TagColor} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {tagEditKey, type TagEditForm} from "../dp";
import {bumpTagsList, bumpTodosRev} from "../init";
import {tx} from "../i18n";
import {navigateTo} from "../utils/navigate";
import {isEnterSubmitEvent} from "../utils/form-enter-submit";
import {focusPrimaryInput} from "../utils/focus-primary-input";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import {showError} from "../utils/modal-dialog";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {TAGS_ROOT} from "../utils/tag-paths";
import {shortcuts} from "../shortcuts";
import "./page-shell";
import "./tag-badge";
import "./tag-scope-header";

@customElement("tag-edit-page")
export class TagEditPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @property({type: String})
  tagId = "";

  @subscribe(tagEditKey.name)
  @state()
  name = "";

  @subscribe(tagEditKey.color)
  @state()
  color: TagColor = "default";

  @state()
  private loading = true;

  @state()
  private notFound = false;

  @state()
  private busy = false;

  @get(endpoints.tags.byId, {skipEmptyPlaceholder: true})
  @state()
  tagPayload: ApiResult<ApiData<Tag>> | null = null;

  @patch(endpoints.tags.patch, endpoints.keys.submit.tagEdit, {
    skipEmptyPlaceholder: true,
  })
  @state()
  savePayload: ApiResult<ApiData<Tag>> | null = null;

  private lastHydratedTagId = "";
  private pendingSubmit = false;

  private get previewTag(): Tag {
    const name = this.name?.trim();
    return {
      id: "preview",
      name: name || tx("tags.form.preview"),
      color: this.color ?? "default",
    };
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("tagId")) {
      this.lastHydratedTagId = "";
      this.loading = Boolean(this.tagId);
      this.notFound = false;
    }
    if (changed.has("tagPayload") || changed.has("tagId")) {
      this.hydrateFromPayload();
    }
    if (changed.has("savePayload") && this.pendingSubmit) {
      void this.finishSubmit();
    }
  }

  private async finishSubmit() {
    this.pendingSubmit = false;
    set(endpoints.keys.submit.tagEdit.path, null);
    const tag = readApiData(this.savePayload);
    if (!tag) {
      await showError(apiResultError(this.savePayload));
      this.busy = false;
      return;
    }
    bumpTodosRev();
    bumpTagsList();
    navigateTo(TAGS_ROOT, true);
  }

  private hydrateFromPayload() {
    if (!this.tagId) return;

    const payload = this.tagPayload;
    if (payload == null) {
      this.loading = true;
      return;
    }

    const tag = payload.result?.data;
    if (!tag?.id) {
      this.notFound = true;
      this.loading = false;
      return;
    }

    if (tag.id === this.lastHydratedTagId) {
      this.loading = false;
      return;
    }

    this.lastHydratedTagId = tag.id;
    this.notFound = false;
    set(tagEditKey.path, {name: tag.name, color: tag.color});
    this.loading = false;
    void focusPrimaryInput(this);
  }

  private onFormKeyDown = (event: KeyboardEvent) => {
    if (!isEnterSubmitEvent(event)) return;
    event.preventDefault();
    this.onSubmit();
  };

  private onSubmit() {
    const form = read(tagEditKey.path) as TagEditForm;
    const name = form.name?.trim();
    if (!name || !this.tagId || this.busy) return;

    this.busy = true;
    this.pendingSubmit = true;
    set(endpoints.keys.submit.tagEdit.path, {
      name,
      color: form.color ?? "default",
    });
  }

  private renderScopeHeader() {
    return html`
      <div
        class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
      >
        <tag-scope-header
          .scopeId=${this.tagId}
          action="edit"
        ></tag-scope-header>
      </div>
    `;
  }

  render() {
    if (this.loading) {
      return html`
        <page-shell>
          ${this.renderScopeHeader()}
          <p class="mt-3 text-sm text-neutral-500">${t("common.loading")}</p>
        </page-shell>
      `;
    }

    if (this.notFound) {
      return html`
        <page-shell>
          ${this.renderScopeHeader()}
          <p class="mt-3 text-sm text-neutral-500">${t("tags.not_found")}</p>
          <sonic-button href=${TAGS_ROOT} pushstate variant="outline">
            ${t("common.back")}
          </sonic-button>
        </page-shell>
      `;
    }

    return html`
      <page-shell>
        ${this.renderScopeHeader()}

        <div
          class="mt-3"
          formDataProvider=${tagEditKey.path}
          @keydown=${this.onFormKeyDown}
        >
          <sonic-form-layout>
            <sonic-input
              name="name"
              label=${tx("tags.form.name")}
              placeholder=${tx("tags.form.name_ph")}
            ></sonic-input>

            <div class="form-field">
              <label class="form-label">${t("tags.form.color")}</label>
              <div class="form-field-control flex flex-wrap gap-1.5 sm:gap-2">
                ${TAG_COLORS.map(
                  (color) => html`
                    <sonic-button
                      radio
                      name="color"
                      value=${color}
                      shape="circle"
                      size="sm"
                      type=${color}
                      variant="outline"
                      data-aria-label=${color}
                      ?disabled=${this.busy}
                    >
                      <sonic-icon
                        library=${ICON_LIBRARY}
                        prefix=${ICON_PREFIX}
                        name="check"
                        size="sm"
                        swap="on"
                      ></sonic-icon>
                      <span
                        swap="off"
                        class="block h-3 w-3"
                        aria-hidden="true"
                      ></span>
                    </sonic-button>
                  `,
                )}
              </div>
            </div>

            <div class="form-field">
              <label class="form-label">${t("tags.form.preview")}</label>
              <div class="form-field-control">
                <tag-badge .tag=${this.previewTag} size="sm"></tag-badge>
              </div>
            </div>

            <sonic-form-actions justify="flex-end">
              <sonic-button
                href=${TAGS_ROOT}
                pushstate
                variant="outline"
                ?disabled=${this.busy}
              >
                ${t("common.cancel")}
              </sonic-button>
              <sonic-tooltip
                label=${shortcuts.withHint(tx("common.save"), "submitForm")}
                placement="top"
              >
                <sonic-button
                  type="primary"
                  ?disabled=${this.busy}
                  data-aria-label=${shortcuts.withHint(
                    tx("common.save"),
                    "submitForm",
                  )}
                  @click=${this.onSubmit}
                >
                  ${t("common.save")}
                </sonic-button>
              </sonic-tooltip>
            </sonic-form-actions>
          </sonic-form-layout>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tag-edit-page": TagEditPage;
  }
}
