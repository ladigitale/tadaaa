import "@supersoniks/concorde/button";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/pop";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property} from "lit/decorators.js";
import {t} from "@supersoniks/concorde/directives/Wording";
import type {DatasetInfo} from "../api/store";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {tf, tx} from "../i18n";

@customElement("dataset-row")
export class DatasetRow extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: block;
      }
    `,
  ];

  @property({attribute: false})
  datasetInfo!: DatasetInfo;

  @property({type: Boolean})
  disabled = false;

  @property({type: Boolean})
  canDelete = true;

  /** Badge principal (ex. Actif / En édition). */
  @property()
  activeLabel = "";

  /** Libellé du bouton d’activation. */
  @property()
  activateLabel = "";

  /** Badge secondaire (ex. actif MCP), indépendant de l’édition. */
  @property({type: Boolean})
  mcpActive = false;

  /** Affiche l’action Partager (owner). */
  @property({type: Boolean})
  canShare = false;

  /** Affiche l’action Renommer. */
  @property({type: Boolean})
  canRename = true;

  /** Libellé optionnel du rôle (writer / reader). */
  @property()
  roleLabel = "";

  private formatDate(value: string): string {
    try {
      return new Date(value).toLocaleString("fr-FR", {
        dateStyle: "short",
        timeStyle: "short",
      });
    } catch {
      return value;
    }
  }

  private renderMenuItemIcon(name: string) {
    return html`
      <sonic-icon
        slot="prefix"
        library=${ICON_LIBRARY}
        prefix=${ICON_PREFIX}
        name=${name}
        size="sm"
      ></sonic-icon>
    `;
  }

  private onActivate() {
    this.dispatchEvent(
      new CustomEvent("dataset-activate", {
        bubbles: true,
        composed: true,
        detail: {dataset: this.datasetInfo},
      }),
    );
  }

  private onDelete() {
    this.dispatchEvent(
      new CustomEvent("dataset-delete", {
        bubbles: true,
        composed: true,
        detail: {dataset: this.datasetInfo},
      }),
    );
  }

  private onShare() {
    this.dispatchEvent(
      new CustomEvent("dataset-share", {
        bubbles: true,
        composed: true,
        detail: {dataset: this.datasetInfo},
      }),
    );
  }

  private onRename() {
    this.dispatchEvent(
      new CustomEvent("dataset-rename", {
        bubbles: true,
        composed: true,
        detail: {dataset: this.datasetInfo},
      }),
    );
  }

  render() {
    if (!this.datasetInfo) return html``;

    const activeLabel = this.activeLabel || tx("datasets.active");
    const activateLabel = this.activateLabel || tx("datasets.activate");

    return html`
      <article class="py-4 sm:py-5">
        <div class="flex items-start gap-2 sm:gap-3">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-medium text-neutral-900 sm:text-base"
                >${this.datasetInfo.name}</span
              >
              ${this.datasetInfo.active
                ? html`<sonic-badge size="2xs" type="success"
                    >${activeLabel}</sonic-badge
                  >`
                : nothing}
              ${this.mcpActive
                ? html`<sonic-badge size="2xs" type="info"
                    >${t("cloud.mcp_badge")}</sonic-badge
                  >`
                : nothing}
              ${this.roleLabel
                ? html`<sonic-badge size="2xs" type="neutral"
                    >${this.roleLabel}</sonic-badge
                  >`
                : nothing}
            </div>
            <p class="mt-0.5 truncate font-mono text-xs text-neutral-500">
              ${this.datasetInfo.baseId}
            </p>
            <p class="text-xs text-neutral-500">
              ${tf("datasets.updated", {
                date: this.formatDate(this.datasetInfo.updatedAt),
              })}
            </p>
          </div>

          <div class="flex shrink-0 items-center gap-0.5">
            ${!this.datasetInfo.active
              ? html`
                  <sonic-button
                    size="sm"
                    variant="ghost"
                    ?disabled=${this.disabled}
                    @click=${this.onActivate}
                  >
                    <sonic-icon
                      library=${ICON_LIBRARY}
                      prefix=${ICON_PREFIX}
                      name="check"
                      size="sm"
                    ></sonic-icon>
                    ${activateLabel}
                  </sonic-button>
                `
              : nothing}

            ${this.canShare
              ? html`
                  <sonic-tooltip label=${tx("cloud.share")} placement="bottom">
                    <sonic-button
                      size="sm"
                      variant="ghost"
                      ?disabled=${this.disabled}
                      @click=${this.onShare}
                      data-aria-label=${tx("cloud.share")}
                    >
                      <sonic-icon
                        library=${ICON_LIBRARY}
                        prefix=${ICON_PREFIX}
                        name="share-android"
                        size="sm"
                      ></sonic-icon>
                      <span class="ml-1 hidden sm:inline">${t("cloud.share")}</span>
                    </sonic-button>
                  </sonic-tooltip>
                `
              : nothing}

            ${this.canRename
              ? html`
                  <sonic-tooltip
                    label=${tx("datasets.rename")}
                    placement="bottom"
                  >
                    <sonic-button
                      size="sm"
                      variant="ghost"
                      ?disabled=${this.disabled}
                      @click=${this.onRename}
                      data-aria-label=${tx("datasets.rename")}
                    >
                      <sonic-icon
                        library=${ICON_LIBRARY}
                        prefix=${ICON_PREFIX}
                        name="edit-pencil"
                        size="sm"
                      ></sonic-icon>
                      <span class="ml-1 hidden sm:inline"
                        >${tx("datasets.rename")}</span
                      >
                    </sonic-button>
                  </sonic-tooltip>
                `
              : nothing}

            <sonic-pop class="inline-block" placement="bottom">
              <sonic-tooltip label=${tx("common.actions")} placement="bottom">
                <sonic-button
                  shape="circle"
                  size="sm"
                  variant="ghost"
                  ?disabled=${this.disabled}
                  data-aria-label=${tx("common.actions")}
                >
                  <sonic-icon
                    library=${ICON_LIBRARY}
                    prefix=${ICON_PREFIX}
                    name="more-vert"
                    size="lg"
                  ></sonic-icon>
                </sonic-button>
              </sonic-tooltip>

              <sonic-menu
                slot="content"
                direction="column"
                align="left"
                size="sm"
                minWidth="12rem"
              >
                ${!this.datasetInfo.active
                  ? html`
                      <sonic-menu-item
                        ?disabled=${this.disabled}
                        @click=${this.onActivate}
                      >
                        ${this.renderMenuItemIcon("check")}
                        ${activateLabel}
                      </sonic-menu-item>
                    `
                  : nothing}
                ${this.canShare
                  ? html`
                      <sonic-menu-item
                        ?disabled=${this.disabled}
                        @click=${this.onShare}
                      >
                        ${this.renderMenuItemIcon("share-android")} ${t("cloud.share")}
                      </sonic-menu-item>
                    `
                  : nothing}
                ${this.canRename
                  ? html`
                      <sonic-menu-item
                        ?disabled=${this.disabled}
                        @click=${this.onRename}
                      >
                        ${this.renderMenuItemIcon("edit-pencil")}
                        ${tx("datasets.rename")}
                      </sonic-menu-item>
                    `
                  : nothing}
                <sonic-menu-item
                  type="danger"
                  ?disabled=${this.disabled || !this.canDelete}
                  @click=${this.onDelete}
                >
                  ${this.renderMenuItemIcon("trash")} ${t("cloud.delete")}
                </sonic-menu-item>
              </sonic-menu>
            </sonic-pop>
          </div>
        </div>
      </article>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "dataset-row": DatasetRow;
  }
}
