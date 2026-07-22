import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {fetchTag, patchTag} from "../api/client";
import {TAG_COLORS} from "../api/store-logic";
import type {Tag, TagColor} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {tagEditKey, type TagEditForm} from "../dp";
import {navigateTo} from "../utils/navigate";
import {isEnterSubmitEvent} from "../utils/form-enter-submit";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import {showError} from "../utils/modal-dialog";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {TAGS_ROOT} from "../utils/tag-paths";
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

  private get previewTag(): Tag {
    const name = this.name?.trim();
    return {
      id: "preview",
      name: name || "Aperçu",
      color: this.color ?? "default",
    };
  }

  connectedCallback() {
    super.connectedCallback();
    void this.load();
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("tagId") && this.tagId) {
      void this.load();
    }
  }

  private async load() {
    if (!this.tagId) return;
    this.loading = true;
    this.notFound = false;
    try {
      const tag = await fetchTag(this.tagId);
      set(tagEditKey.path, {name: tag.name, color: tag.color});
    } catch {
      this.notFound = true;
    } finally {
      this.loading = false;
    }
  }

  private onFormKeyDown = (event: KeyboardEvent) => {
    if (!isEnterSubmitEvent(event)) return;
    event.preventDefault();
    void this.onSubmit();
  };

  private async onSubmit() {
    const form = read(tagEditKey.path) as TagEditForm;
    const name = form.name?.trim();
    if (!name || !this.tagId || this.busy) return;

    this.busy = true;
    try {
      await patchTag(this.tagId, {
        name,
        color: form.color ?? "default",
      });
      navigateTo(TAGS_ROOT, true);
    } catch (error) {
      await showError(error, "Impossible de modifier l’étiquette");
      console.error(error);
    } finally {
      this.busy = false;
    }
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
          <p class="mt-3 text-sm text-neutral-500">Chargement…</p>
        </page-shell>
      `;
    }

    if (this.notFound) {
      return html`
        <page-shell>
          ${this.renderScopeHeader()}
          <p class="mt-3 text-sm text-neutral-500">Étiquette introuvable.</p>
          <sonic-button href=${TAGS_ROOT} pushstate variant="outline">
            Retour
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
              label="Nom"
              placeholder="Ex. Urgent, Backlog…"
            ></sonic-input>

            <div class="form-field">
              <label class="form-label">Couleur</label>
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
              <label class="form-label">Aperçu</label>
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
                Annuler
              </sonic-button>
              <sonic-button
                type="primary"
                ?disabled=${this.busy}
                @click=${this.onSubmit}
              >
                Enregistrer
              </sonic-button>
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
