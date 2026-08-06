import "@supersoniks/concorde/modal";
import "@supersoniks/concorde/modal-title";
import "@supersoniks/concorde/modal-content";
import "@supersoniks/concorde/input";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/divider";
import {html, LitElement, nothing} from "lit";
import {customElement, query, state} from "lit/decorators.js";
import {handle, subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {set} from "../../utils/dataprovider";
import {
  collectCommandGroups,
  warmCommandPaletteCache,
  type CommandGroup,
  type CommandItem,
  type CommandItemType,
} from "../command-palette";
import {commandPaletteKey} from "../dp";
import {tx} from "../i18n";
import {focusPrimaryInput} from "../utils/focus-primary-input";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import {rmLinksTemplate} from "./rm-link-text";

const TYPE_LABEL_KEY: Record<CommandItemType, string> = {
  action: "command.type.action",
  page: "command.type.page",
  tag: "command.type.tag",
  task: "command.type.task",
};

@customElement("command-palette-modal")
export class CommandPaletteModal extends LitElement {
  static styles = [tailwind];

  @query("#commandPaletteModal")
  private modal?: HTMLElement & {show: () => void; hide: () => void};

  @query("#commandPaletteResults")
  private resultsEl?: HTMLElement;

  @subscribe(commandPaletteKey.q)
  @state()
  q = "";

  @state()
  private groups: CommandGroup[] = [];

  @state()
  private loading = false;

  /** Flat index into `flatItems` (always 0 = first result when present). */
  @state()
  private activeIndex = 0;

  private collectSeq = 0;

  /** Ignore query churn until the cache for this open is ready. */
  private ready = false;

  private isOpen = false;

  private get flatItems(): CommandItem[] {
    return this.groups.flatMap((group) => group.items);
  }

  private onKeyDown = (event: KeyboardEvent) => {
    if (!this.isOpen) return;
    const items = this.flatItems;
    if (items.length === 0) return;

    if (event.key === "ArrowDown") {
      event.preventDefault();
      this.activeIndex = (this.activeIndex + 1) % items.length;
      void this.scrollActiveIntoView();
      return;
    }

    if (event.key === "ArrowUp") {
      event.preventDefault();
      this.activeIndex =
        (this.activeIndex - 1 + items.length) % items.length;
      void this.scrollActiveIntoView();
      return;
    }

    if (event.key === "Enter") {
      event.preventDefault();
      const item = items[this.activeIndex] ?? items[0];
      if (item) this.runItem(item);
    }
  };

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener("keydown", this.onKeyDown);
  }

  disconnectedCallback() {
    window.removeEventListener("keydown", this.onKeyDown);
    super.disconnectedCallback();
  }

  async open() {
    this.ready = false;
    this.isOpen = true;
    this.activeIndex = 0;
    set(commandPaletteKey.path, {q: ""});
    this.groups = [];
    this.loading = true;

    await this.updateComplete;
    await this.modal?.show();
    void focusPrimaryInput(this);
    await warmCommandPaletteCache();
    this.ready = true;
    await this.refreshResults();
  }

  private closePalette() {
    this.isOpen = false;
    this.ready = false;
    this.modal?.hide();
  }

  @handle(commandPaletteKey.q)
  onQueryChange(_q: string) {
    if (!this.ready) return;
    void this.refreshResults();
  }

  private async refreshResults() {
    const seq = ++this.collectSeq;
    this.loading = true;
    try {
      const groups = await collectCommandGroups(this.q ?? "");
      if (seq !== this.collectSeq) return;
      this.groups = groups;
      this.activeIndex = 0;
    } catch {
      if (seq !== this.collectSeq) return;
      this.groups = [];
      this.activeIndex = 0;
    } finally {
      if (seq === this.collectSeq) this.loading = false;
    }
  }

  private async scrollActiveIntoView() {
    await this.updateComplete;
    const el = this.resultsEl?.querySelector<HTMLElement>(
      `[data-command-index="${this.activeIndex}"]`,
    );
    el?.scrollIntoView({block: "nearest"});
  }

  private runItem(item: CommandItem) {
    set(commandPaletteKey.path, {q: ""});
    this.closePalette();
    item.run();
  }

  private renderItem(item: CommandItem, index: number) {
    const label =
      item.type === "task" ? rmLinksTemplate(item.label) : item.label;
    const active = index === this.activeIndex;
    return html`
      <sonic-menu-item
        class="w-full rounded-md ${active
          ? "bg-neutral-100 ring-1 ring-neutral-200"
          : ""}"
        data-command-index=${index}
        @click=${() => this.runItem(item)}
        @mouseenter=${() => {
          this.activeIndex = index;
        }}
      >
        <sonic-icon
          slot="prefix"
          library=${ICON_LIBRARY}
          prefix=${ICON_PREFIX}
          name=${item.icon}
          size="sm"
        ></sonic-icon>
        <span class="min-w-0 truncate">${label}</span>
      </sonic-menu-item>
    `;
  }

  private renderGroups() {
    let index = 0;
    return this.groups.map((group) => {
      const items = group.items.map((item) => {
        const rendered = this.renderItem(item, index);
        index += 1;
        return rendered;
      });
      return html`
        <sonic-divider
          label=${tx(TYPE_LABEL_KEY[group.type])}
          align="left"
          size="sm"
        ></sonic-divider>
        ${items}
      `;
    });
  }

  render() {
    const needle = this.q?.trim() ?? "";
    const hasResults = this.flatItems.length > 0;

    return html`
      <sonic-modal
        id="commandPaletteModal"
        maxWidth="36rem"
        width="100%"
        @hide=${() => {
          this.isOpen = false;
          this.ready = false;
        }}
      >
        <sonic-modal-title>${t("command.search_title")}</sonic-modal-title>
        <sonic-modal-content>
          <div class="space-y-3" formDataProvider=${commandPaletteKey.path}>
            <sonic-input
              name="q"
              type="search"
              size="sm"
              placeholder=${tx("command.search_ph")}
              class="min-w-0 w-full"
            >
              <sonic-icon
                slot="prefix"
                library=${ICON_LIBRARY}
                prefix=${ICON_PREFIX}
                name="search"
                size="sm"
              ></sonic-icon>
            </sonic-input>

            <div
              id="commandPaletteResults"
              class="max-h-80 overflow-y-auto"
              aria-label=${tx("command.search_results_aria")}
            >
              ${this.loading && !hasResults
                ? html`
                    <p class="py-4 text-center text-sm text-neutral-500">
                      ${t("common.loading")}
                    </p>
                  `
                : html`
                    <sonic-menu
                      direction="column"
                      align="left"
                      size="sm"
                      class="w-full"
                    >
                      ${this.renderGroups()}
                    </sonic-menu>
                    ${!hasResults
                      ? html`
                          <p
                            class="py-4 text-center text-sm italic text-neutral-500"
                          >
                            ${needle
                              ? t("command.empty_filtered")
                              : t("command.empty")}
                          </p>
                        `
                      : nothing}
                  `}
            </div>
          </div>
        </sonic-modal-content>
      </sonic-modal>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "command-palette-modal": CommandPaletteModal;
  }
}
