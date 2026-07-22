import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";

export type PopSelectOption = {
  value: string;
  label: string;
  icon?: string;
  /** Option « tout sélectionner » (FormCheckable checksAll) — mode multi uniquement. */
  checksAll?: boolean;
};

export type PopSelectMode = "radio" | "unique" | "multi";

/**
 * Select via sonic-pop + sonic-menu-item.
 * Chaque item porte `name` + `value` (FormCheckable) ; `formDataProvider` vient de l’ancêtre.
 *
 * Le libellé du trigger suit la sélection immédiatement (état local), même si le parent
 * ne `@subscribe` que l’objet formulaire entier (écritures nested).
 *
 * Sur l’option `checksAll`, FormCheckable pose `[indeterminate]` en sélection partielle :
 * on affiche alors une icône minus (comme sonic-checkbox).
 */
@customElement("pop-select")
export class PopSelect extends LitElement {
  static styles = [
    tailwind,
    formLabelStyles,
    css`
      :host {
        display: inline-flex;
        max-width: 100%;
        flex-direction: column;
        align-items: stretch;
      }

      :host([showLabel]) {
        display: flex;
        width: 100%;
      }

      sonic-menu-item[checksAll][indeterminate] sonic-icon[data-check] {
        display: none !important;
      }

      sonic-menu-item[checksAll] sonic-icon[data-indeterminate] {
        display: none;
      }

      sonic-menu-item[checksAll][indeterminate] sonic-icon[data-indeterminate] {
        display: inline-flex !important;
      }
    `,
  ];

  @property({type: String})
  label = "";

  /** Affiche le label au-dessus du trigger (même style que sonic-input). */
  @property({type: Boolean, reflect: true})
  showLabel = false;

  /** Nom du champ form — recopié sur chaque menu-item (pas sur sonic-menu). */
  @property({type: String})
  name = "";

  /** Valeur affichée / synchronisée depuis le parent. */
  @property({attribute: false})
  value: string | string[] = "";

  @property({attribute: false})
  options: PopSelectOption[] = [];

  @property({type: String})
  mode: PopSelectMode = "radio";

  @property({type: String})
  size: "xs" | "sm" | "md" = "sm";

  @property({type: String})
  variant: "default" | "outline" | "ghost" | "link" = "default";

  @property({type: Boolean})
  disabled = false;

  @property({type: String})
  minWidth = "10rem";

  /** Miroir local pour maj immédiate du trigger au clic. */
  @state()
  private displayValue: string | string[] = "";

  protected willUpdate(changed: Map<string, unknown>) {
    if (changed.has("value")) {
      this.displayValue = Array.isArray(this.value)
        ? [...this.value]
        : this.value;
    }
  }

  private get regularOptionValues(): string[] {
    return this.options
      .filter((option) => !option.checksAll)
      .map((option) => option.value);
  }

  private get selectedMultiValues(): string[] {
    if (!Array.isArray(this.displayValue)) return [];
    return this.displayValue.filter(Boolean);
  }

  /** Sélection partielle (ni vide ni toutes les options). */
  private get isPartialMulti(): boolean {
    const selected = this.selectedMultiValues;
    const regularValues = this.regularOptionValues;
    if (selected.length === 0 || regularValues.length === 0) return false;
    const selectedRegular = selected.filter((value) =>
      regularValues.includes(value),
    );
    if (selectedRegular.length === 0) return false;
    return !regularValues.every((value) => selectedRegular.includes(value));
  }

  private get selectedLabel(): string {
    if (Array.isArray(this.displayValue)) {
      const selected = this.selectedMultiValues;
      if (selected.length === 0) return "Toutes";
      const checksAllOption = this.options.find((option) => option.checksAll);
      const regularValues = this.regularOptionValues;
      const allSelected =
        regularValues.length > 0 &&
        regularValues.every((value) => selected.includes(value));
      if (
        allSelected ||
        (checksAllOption && selected.includes(checksAllOption.value))
      ) {
        return checksAllOption?.label ?? "Toutes";
      }
      if (selected.length === 1) {
        return (
          this.options.find((option) => option.value === selected[0])?.label ??
          selected[0]
        );
      }
      return `${selected.length} sélectionnées`;
    }

    return (
      this.options.find((option) => option.value === this.displayValue)
        ?.label ?? "Sélectionner"
    );
  }

  private get selectedIcon(): string | undefined {
    if (Array.isArray(this.displayValue)) {
      if (this.isPartialMulti) return "minus";
      const selected = this.selectedMultiValues;
      if (selected.length === 1) {
        return this.options.find((option) => option.value === selected[0])
          ?.icon;
      }
      return this.options.find((option) => option.checksAll)?.icon ?? "label";
    }
    return this.options.find((option) => option.value === this.displayValue)
      ?.icon;
  }

  private onMenuActivate = (event: Event) => {
    if (this.disabled) return;
    const path = event.composedPath();
    const item = path.find(
      (node): node is HTMLElement =>
        node instanceof HTMLElement &&
        node.tagName.toLowerCase() === "sonic-menu-item",
    );
    if (!item) return;

    const itemValue = item.getAttribute("value") ?? "";
    if (!itemValue) return;

    if (this.mode === "radio" || this.mode === "unique") {
      this.displayValue = itemValue;
      return;
    }

    // multi : bascule locale (FormCheckable reste source de vérité côté DP)
    const checksAll = item.hasAttribute("checksAll");
    const regularValues = this.regularOptionValues;
    if (checksAll) {
      const selected = this.selectedMultiValues;
      const allSelected =
        regularValues.length > 0 &&
        regularValues.every((value) => selected.includes(value));
      this.displayValue = allSelected ? [] : [...regularValues, itemValue];
      return;
    }

    const selected = new Set(this.selectedMultiValues);
    if (selected.has(itemValue)) selected.delete(itemValue);
    else selected.add(itemValue);
    this.displayValue = [...selected];
  };

  private renderItemSuffix(option: PopSelectOption) {
    if (option.checksAll) {
      return html`
        <sonic-icon
          slot="suffix"
          data-check
          swap="on"
          library=${ICON_LIBRARY}
          prefix=${ICON_PREFIX}
          name="check"
          size="sm"
        ></sonic-icon>
        <sonic-icon
          slot="suffix"
          data-indeterminate
          library=${ICON_LIBRARY}
          prefix=${ICON_PREFIX}
          name="minus"
          size="sm"
        ></sonic-icon>
      `;
    }

    return html`
      <sonic-icon
        slot="suffix"
        swap="on"
        library=${ICON_LIBRARY}
        prefix=${ICON_PREFIX}
        name="check"
        size="sm"
      ></sonic-icon>
    `;
  }

  render() {
    const mode = this.mode;
    const fieldName = this.name;

    return html`
      ${this.showLabel && this.label
        ? html`<label class="form-label">${this.label}</label>`
        : nothing}
      <sonic-pop class="inline-block max-w-full">
        <sonic-button
          size=${this.size}
          variant=${this.variant}
          ?disabled=${this.disabled}
          class="max-w-full"
          data-aria-label=${this.label || nothing}
        >
          ${this.selectedIcon
            ? html`
                <sonic-icon
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name=${this.selectedIcon}
                  size="sm"
                ></sonic-icon>
              `
            : nothing}
          <span class="truncate">${this.selectedLabel}</span>
          <sonic-icon
            slot="suffix"
            library=${ICON_LIBRARY}
            prefix=${ICON_PREFIX}
            name="nav-arrow-down"
            size="sm"
          ></sonic-icon>
        </sonic-button>

        <sonic-menu
          slot="content"
          direction="column"
          align="left"
          size=${this.size}
          minWidth=${this.minWidth}
          @click=${this.onMenuActivate}
        >
          ${this.options.map(
            (option) => html`
              <sonic-menu-item
                name=${fieldName}
                value=${option.value}
                ?radio=${mode === "radio"}
                ?unique=${mode === "unique"}
                ?checksAll=${Boolean(option.checksAll)}
              >
                ${option.icon
                  ? html`
                      <sonic-icon
                        slot="prefix"
                        library=${ICON_LIBRARY}
                        prefix=${ICON_PREFIX}
                        name=${option.icon}
                        size="sm"
                      ></sonic-icon>
                    `
                  : nothing}
                ${option.label} ${this.renderItemSuffix(option)}
              </sonic-menu-item>
            `,
          )}
        </sonic-menu>
      </sonic-pop>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "pop-select": PopSelect;
  }
}
