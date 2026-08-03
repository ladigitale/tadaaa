import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {createStore, resolveApiBase, type EmbedStore} from "./store";
import type {EmbedFeed, EmbedTheme} from "./types";

@customElement("tadaaa-embed")
export class TadaaaEmbed extends LitElement {
  static styles = css`
    :host {
      display: block;
      color: var(--tadaaa-fg, #0f172a);
      background: var(--tadaaa-bg, transparent);
      font-family: var(--tadaaa-font, system-ui, sans-serif);
      font-size: var(--tadaaa-font-size, 15px);
      line-height: 1.45;
      --tadaaa-accent: #0d9488;
      --tadaaa-muted: #64748b;
      --tadaaa-border: color-mix(in srgb, var(--tadaaa-fg) 12%, transparent);
      --tadaaa-radius: 12px;
      --tadaaa-gap: 0.75rem;
    }
    :host([data-theme="dark"]) {
      color-scheme: dark;
      --tadaaa-fg: #e2e8f0;
      --tadaaa-bg: #0f172a;
      --tadaaa-muted: #94a3b8;
    }
    :host([data-theme="light"]) {
      color-scheme: light;
      --tadaaa-fg: #0f172a;
      --tadaaa-bg: #ffffff;
      --tadaaa-muted: #64748b;
    }
    .shell {
      display: flex;
      flex-direction: column;
      gap: var(--tadaaa-gap);
    }
    .status {
      color: var(--tadaaa-muted);
      font-size: 0.9em;
    }
    .error {
      color: #b91c1c;
      font-size: 0.9em;
    }
  `;

  @property({type: String}) key = "";
  @property({attribute: "api-base", type: String}) apiBase = "";
  @property({type: String}) theme: EmbedTheme["theme"] = "auto";
  @property({type: String}) accent = "#0d9488";
  @property({type: String}) font = "system-ui, sans-serif";
  @property({type: String}) radius = "12px";
  @property({type: String}) density: EmbedTheme["density"] = "comfortable";
  @property({type: String}) view = "";
  @property({type: Number}) poll = 60;
  @property({type: String}) locale = "";

  @state() private loading = false;
  @state() private error: string | null = null;
  @state() private feed: EmbedFeed | null = null;

  /** @internal */
  store: EmbedStore = createStore();
  private pollTimer: number | null = null;
  private unsub: (() => void) | null = null;
  private mq: MediaQueryList | null = null;
  private autoViewEl: HTMLElement | null = null;

  connectedCallback() {
    super.connectedCallback();
    this.unsub = this.store.subscribe(() => {
      const s = this.store.get();
      this.loading = s.loading;
      this.error = s.error;
      this.feed = s.feed;
      this.applyTheme(s.theme);
    });
    this.syncStoreFromProps();
    this.ensureDefaultView();
    void this.refresh();
    this.startPoll();
    this.mq = window.matchMedia("(prefers-color-scheme: dark)");
    this.mq.addEventListener("change", this.onSchemeChange);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this.unsub?.();
    this.unsub = null;
    this.stopPoll();
    this.mq?.removeEventListener("change", this.onSchemeChange);
    this.autoViewEl?.remove();
    this.autoViewEl = null;
  }

  protected updated(changed: Map<string, unknown>) {
    if (
      changed.has("key") ||
      changed.has("apiBase") ||
      changed.has("theme") ||
      changed.has("accent") ||
      changed.has("font") ||
      changed.has("radius") ||
      changed.has("density")
    ) {
      this.syncStoreFromProps();
    }
    if (changed.has("key") || changed.has("apiBase")) {
      void this.refresh();
    }
    if (changed.has("poll")) {
      this.startPoll();
    }
    if (changed.has("view")) {
      this.ensureDefaultView(true);
    }
  }

  /** Mount default view in light DOM so widgets can `closest('tadaaa-embed')`. */
  private ensureDefaultView(force = false) {
    const hasManual = [...this.children].some(
      (el) =>
        el !== this.autoViewEl &&
        el.tagName.startsWith("TADAAA-") &&
        el.tagName !== "TADAAA-EMBED",
    );
    if (hasManual) {
      this.autoViewEl?.remove();
      this.autoViewEl = null;
      return;
    }
    const v = (this.view.trim() || "list").toLowerCase();
    const tag =
      v === "kpi" || v === "agenda" || v === "list" ? `tadaaa-${v}` : "tadaaa-list";
    if (!force && this.autoViewEl?.tagName === tag.toUpperCase()) return;
    this.autoViewEl?.remove();
    const el = document.createElement(tag);
    el.setAttribute("data-tadaaa-auto", "");
    this.appendChild(el);
    this.autoViewEl = el;
  }

  private onSchemeChange = () => {
    if (this.theme === "auto") this.applyTheme(this.store.get().theme);
  };

  private syncStoreFromProps() {
    this.store.set({
      key: this.key.trim(),
      apiBase: resolveApiBase(this.apiBase),
      theme: {
        theme: this.theme,
        accent: this.accent,
        font: this.font,
        radius: this.radius,
        density: this.density,
      },
    });
  }

  private applyTheme(theme: EmbedTheme) {
    const resolved =
      theme.theme === "auto"
        ? window.matchMedia("(prefers-color-scheme: dark)").matches
          ? "dark"
          : "light"
        : theme.theme;
    this.setAttribute("data-theme", resolved);
    this.style.setProperty("--tadaaa-accent", theme.accent);
    this.style.setProperty("--tadaaa-font", theme.font);
    this.style.setProperty("--tadaaa-radius", theme.radius);
    this.style.setProperty(
      "--tadaaa-gap",
      theme.density === "compact" ? "0.5rem" : "0.75rem",
    );
    this.style.setProperty(
      "--tadaaa-font-size",
      theme.density === "compact" ? "13px" : "15px",
    );
  }

  private startPoll() {
    this.stopPoll();
    if (this.poll <= 0) return;
    this.pollTimer = window.setInterval(() => {
      if (document.visibilityState === "visible") void this.refresh();
    }, this.poll * 1000);
  }

  private stopPoll() {
    if (this.pollTimer !== null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  async refresh() {
    const {key, apiBase} = this.store.get();
    if (!key || !apiBase) {
      this.store.set({
        error: !key ? "Missing embed key" : "Missing api-base",
        loading: false,
      });
      return;
    }
    this.store.set({loading: true, error: null});
    try {
      const res = await fetch(`${apiBase}/api/public/embeds/${encodeURIComponent(key)}`, {
        credentials: "omit",
      });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const feed = (await res.json()) as EmbedFeed;
      this.store.set({feed, loading: false, error: null});
    } catch (e) {
      this.store.set({
        loading: false,
        error: e instanceof Error ? e.message : "Load failed",
      });
    }
  }

  render() {
    return html`
      <div class="shell">
        ${this.error ? html`<div class="error">${this.error}</div>` : nothing}
        ${this.loading && !this.feed
          ? html`<div class="status">Loading…</div>`
          : nothing}
        <slot></slot>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tadaaa-embed": TadaaaEmbed;
  }
}

export function findEmbedHost(el: HTMLElement): TadaaaEmbed | null {
  return el.closest("tadaaa-embed");
}
