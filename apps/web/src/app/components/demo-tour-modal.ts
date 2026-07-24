import "@supersoniks/concorde/modal";
import "@supersoniks/concorde/modal-title";
import "@supersoniks/concorde/modal-subtitle";
import "@supersoniks/concorde/modal-content";
import "@supersoniks/concorde/modal-actions";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/badge";
import "@supersoniks/concorde/icon";
import {css, html, LitElement} from "lit";
import {customElement, query, state} from "lit/decorators.js";
import {tx} from "../i18n";
import {isAccountConnected} from "../account-settings";
import {hasSeenDemoTour, markDemoTourSeen} from "../demo-tour";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";

type TourStep = {
  titleKey: string;
  subtitleKey: string;
  bodyKey: string;
  icon: string;
};

const STEPS: TourStep[] = [
  {
    titleKey: "demo.tour.s1.title",
    subtitleKey: "demo.tour.s1.subtitle",
    bodyKey: "demo.tour.s1.body",
    icon: "sparks",
  },
  {
    titleKey: "demo.tour.s2.title",
    subtitleKey: "demo.tour.s2.subtitle",
    bodyKey: "demo.tour.s2.body",
    icon: "list",
  },
  {
    titleKey: "demo.tour.s3.title",
    subtitleKey: "demo.tour.s3.subtitle",
    bodyKey: "demo.tour.s3.body",
    icon: "label",
  },
  {
    titleKey: "demo.tour.s4.title",
    subtitleKey: "demo.tour.s4.subtitle",
    bodyKey: "demo.tour.s4.body",
    icon: "cloud-upload",
  },
];

@customElement("demo-tour-modal")
export class DemoTourModal extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: contents;
      }

      .tour-body {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        line-height: 1.45;
      }

      .tour-hero {
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }

      .tour-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2.75rem;
        height: 2.75rem;
        border-radius: var(--sc-rounded, 0.5rem);
        background: color-mix(in srgb, var(--sc-info) 16%, transparent);
        color: var(--sc-info);
        flex-shrink: 0;
      }

      .tour-dots {
        display: flex;
        gap: 0.4rem;
        justify-content: center;
        margin-top: 0.25rem;
      }

      .tour-dot {
        width: 0.45rem;
        height: 0.45rem;
        border-radius: 999px;
        background: var(--sc-base-300);
        transition: background 0.15s, transform 0.15s;
      }

      .tour-dot[data-active] {
        background: var(--sc-info);
        transform: scale(1.25);
      }
    `,
  ];

  @query("#demoTourModal")
  private modal?: HTMLElement & {show: () => void; hide: () => void};

  @state()
  private step = 0;

  private autoOpened = false;

  connectedCallback() {
    super.connectedCallback();
    // Connected accounts already know the product — skip the auto tour.
    if (isAccountConnected() || hasSeenDemoTour() || this.autoOpened) return;
    this.autoOpened = true;
    window.setTimeout(() => {
      if (isAccountConnected()) return;
      void this.open();
    }, 700);
  }

  async open() {
    this.step = 0;
    await this.updateComplete;
    this.modal?.show();
  }

  private finish() {
    markDemoTourSeen();
    this.modal?.hide();
  }

  private onHidden = () => {
    markDemoTourSeen();
  };

  private prev = () => {
    if (this.step > 0) this.step -= 1;
  };

  private next = () => {
    if (this.step < STEPS.length - 1) {
      this.step += 1;
      return;
    }
    this.finish();
  };

  private skip = () => {
    this.finish();
  };

  render() {
    const current = STEPS[this.step] ?? STEPS[0];
    const isLast = this.step === STEPS.length - 1;
    const isFirst = this.step === 0;

    return html`
      <sonic-modal
        id="demoTourModal"
        maxWidth="28rem"
        width="100%"
        @hidden=${this.onHidden}
      >
        <sonic-modal-title>
          <span class="inline-flex items-center gap-2">
            <sonic-badge type="info" size="sm">${tx("demo.badge")}</sonic-badge>
            ${tx(current.titleKey)}
          </span>
        </sonic-modal-title>
        <sonic-modal-subtitle>${tx(current.subtitleKey)}</sonic-modal-subtitle>
        <sonic-modal-content>
          <div class="tour-body">
            <div class="tour-hero">
              <div class="tour-icon" aria-hidden="true">
                <sonic-icon
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name=${current.icon}
                  size="lg"
                ></sonic-icon>
              </div>
              <p class="m-0 text-sm text-neutral-700">${tx(current.bodyKey)}</p>
            </div>
            <div
              class="tour-dots"
              role="tablist"
              aria-label=${tx("demo.tour.progress_aria")}
            >
              ${STEPS.map(
                (_, i) => html`
                  <span
                    class="tour-dot"
                    ?data-active=${i === this.step}
                    role="presentation"
                  ></span>
                `,
              )}
            </div>
          </div>
        </sonic-modal-content>
        <sonic-modal-actions>
          ${isFirst
            ? html`
                <sonic-button variant="ghost" @click=${this.skip}
                  >${tx("demo.tour.skip")}</sonic-button
                >
              `
            : html`
                <sonic-button variant="ghost" @click=${this.prev}
                  >${tx("common.back")}</sonic-button
                >
              `}
          <sonic-button type="primary" @click=${this.next}>
            ${isLast ? tx("demo.tour.start") : tx("demo.tour.next")}
          </sonic-button>
        </sonic-modal-actions>
      </sonic-modal>
    `;
  }
}
