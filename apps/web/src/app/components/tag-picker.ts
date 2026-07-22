import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import type {Tag} from "../api/types";
import {dp} from "../../utils/dataprovider";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import tailwind from "../../css/tailwind";

/**
 * Sélection multi d’étiquettes via sonic-button FormCheckable (`name` + `value`).
 * Clic = FormCheckable uniquement (pas de toggle manuel).
 * `value` réhydrate le formDataProvider à l’init pour resélectionner.
 * Swap natif : `on` = sélectionné, `off` = non.
 */
@customElement("tag-picker")
export class TagPicker extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }

      /* Pas d’anneau / offset de focus carré sur ces toggles. */
      .tag-picker-btn::part(button),
      .tag-picker-btn {
        outline: none;
        box-shadow: none;
      }
    `,
  ];

  @property({attribute: false})
  tags: Tag[] = [];

  /**
   * Chemin formulaire — posé en `formDataProvider` dans le shadow pour que
   * FormCheckable trouve le DP.
   */
  @property()
  formPath = "";

  /** Nom du champ multi (écrit un tableau d’ids dans le formulaire). */
  @property()
  name = "tagIds";

  /** Ids sélectionnés (lié depuis le parent via @subscribe nested). */
  @property({attribute: false})
  value: string[] = [];

  @property({type: Boolean})
  disabled = false;

  /** Miroir pour variant outline/plein (suivi du DP via `value`). */
  @state()
  private selectedIds: string[] = [];

  /** Évite de réécrire le DP en boucle (même sélection, nouvelle ref). */
  private lastSyncedKey = "";

  protected willUpdate(changed: Map<string, unknown>) {
    if (changed.has("value")) {
      const next = Array.isArray(this.value)
        ? this.value.map(String).filter(Boolean)
        : [];
      const same =
        next.length === this.selectedIds.length &&
        next.every((id) => this.selectedIds.includes(id));
      if (!same) this.selectedIds = next;
    }
  }

  protected updated(changed: Map<string, unknown>) {
    // Réhydrate le DP (édition) — FormCheckable coche via onAssign.
    // Ne pas toucher au clic : FormCheckable écrit déjà le champ.
    if (
      this.formPath &&
      this.tags.length > 0 &&
      (changed.has("value") || changed.has("formPath") || changed.has("tags"))
    ) {
      this.syncFormDataProvider();
    }
  }

  private syncFormDataProvider() {
    if (!this.formPath || !this.name) return;
    const key = [...this.selectedIds].map(String).sort().join("\0");
    if (key === this.lastSyncedKey) return;
    this.lastSyncedKey = key;
    dp(`${this.formPath}/${this.name}`).set([...this.selectedIds]);
  }

  private isSelected(tagId: string): boolean {
    return this.selectedIds.includes(tagId);
  }

  render() {
    if (this.tags.length === 0) return nothing;

    const fieldName = this.name;

    return html`
      <div
        class="flex flex-wrap gap-1.5 sm:gap-2"
        formDataProvider=${this.formPath || nothing}
      >
        ${this.tags.map((tag) => {
          const selected = this.isSelected(tag.id);
          return html`
            <sonic-button
              class="tag-picker-btn"
              size="sm"
              type=${tag.color}
              variant=${selected ? "default" : "outline"}
              name=${fieldName}
              value=${tag.id}
              ?disabled=${this.disabled}
              data-aria-label=${selected
                ? `Retirer ${tag.name}`
                : `Ajouter ${tag.name}`}
            >
              <sonic-icon
                slot="prefix"
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="check"
                size="sm"
                swap="on"
              ></sonic-icon>
              <sonic-icon
                slot="prefix"
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="circle"
                size="sm"
                swap="off"
              ></sonic-icon>
              ${tag.name}
            </sonic-button>
          `;
        })}
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tag-picker": TagPicker;
  }
}
