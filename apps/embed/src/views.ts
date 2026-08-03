import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {findEmbedHost, type TadaaaEmbed} from "./host";
import {filteredTodos} from "./store";
import type {EmbedFilter, EmbedState, EmbedTag, EmbedTodo} from "./types";

export abstract class EmbedView extends LitElement {
  @state() protected snap: EmbedState | null = null;
  private hostEl: TadaaaEmbed | null = null;
  private unsub: (() => void) | null = null;

  connectedCallback() {
    super.connectedCallback();
    this.bindHost();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this.unsub?.();
    this.unsub = null;
    this.hostEl = null;
  }

  protected bindHost() {
    this.unsub?.();
    this.hostEl = findEmbedHost(this);
    if (!this.hostEl) {
      this.snap = null;
      return;
    }
    this.snap = this.hostEl.store.get();
    this.unsub = this.hostEl.store.subscribe(() => {
      this.snap = this.hostEl?.store.get() ?? null;
    });
  }

  protected setFilter(patch: Partial<EmbedFilter>) {
    if (!this.hostEl) return;
    this.hostEl.store.set({
      filter: {...this.hostEl.store.get().filter, ...patch},
    });
  }
}

const shared = css`
  :host {
    display: block;
  }
  .muted {
    color: var(--tadaaa-muted, #64748b);
  }
  .card {
    border: 1px solid var(--tadaaa-border);
    border-radius: var(--tadaaa-radius, 12px);
    padding: var(--tadaaa-gap, 0.75rem);
  }
`;

@customElement("tadaaa-filter")
export class TadaaaFilter extends EmbedView {
  static styles = [
    shared,
    css`
      .row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
      }
      input,
      select {
        font: inherit;
        color: inherit;
        background: transparent;
        border: 1px solid var(--tadaaa-border);
        border-radius: calc(var(--tadaaa-radius, 12px) * 0.6);
        padding: 0.35rem 0.55rem;
      }
      button {
        font: inherit;
        cursor: pointer;
        border: 1px solid var(--tadaaa-border);
        background: transparent;
        color: inherit;
        border-radius: 999px;
        padding: 0.25rem 0.65rem;
      }
      button[aria-pressed="true"] {
        background: var(--tadaaa-accent);
        border-color: var(--tadaaa-accent);
        color: #fff;
      }
    `,
  ];

  @property({type: String}) tags = "";
  @property({type: String}) status: EmbedFilter["status"] | "" = "";

  connectedCallback() {
    super.connectedCallback();
    this.applyAttrs();
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("tags") || changed.has("status")) this.applyAttrs();
  }

  private applyAttrs() {
    const patch: Partial<EmbedFilter> = {};
    if (this.tags.trim()) {
      patch.tags = this.tags.split(",").map((t) => t.trim()).filter(Boolean);
    }
    if (this.status) patch.status = this.status;
    if (Object.keys(patch).length) this.setFilter(patch);
  }

  private toggleTag(id: string) {
    const cur = this.snap?.filter.tags ?? [];
    const next = cur.includes(id) ? cur.filter((t) => t !== id) : [...cur, id];
    this.setFilter({tags: next});
  }

  render() {
    const tags = this.snap?.feed?.tags ?? [];
    const filter = this.snap?.filter;
    if (!filter) return html`<div class="muted">…</div>`;

    return html`
      <div class="row card">
        <input
          type="search"
          placeholder="Search"
          .value=${filter.q}
          @input=${(e: Event) =>
            this.setFilter({q: (e.target as HTMLInputElement).value})}
        />
        <select
          .value=${filter.status}
          @change=${(e: Event) =>
            this.setFilter({
              status: (e.target as HTMLSelectElement).value as EmbedFilter["status"],
            })}
        >
          <option value="active">Active</option>
          <option value="done">Done</option>
          <option value="all">All</option>
        </select>
        ${tags.map(
          (tag: EmbedTag) => html`
            <button
              type="button"
              aria-pressed=${filter.tags.includes(tag.id) ? "true" : "false"}
              @click=${() => this.toggleTag(tag.id)}
            >
              ${tag.name}
            </button>
          `,
        )}
      </div>
    `;
  }
}

@customElement("tadaaa-list")
export class TadaaaList extends EmbedView {
  static styles = [
    shared,
    css`
      ul {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
      }
      li {
        display: flex;
        gap: 0.55rem;
        align-items: flex-start;
        padding: 0.45rem 0.55rem;
        border-radius: calc(var(--tadaaa-radius, 12px) * 0.7);
        border: 1px solid var(--tadaaa-border);
      }
      .dot {
        width: 0.65rem;
        height: 0.65rem;
        border-radius: 50%;
        margin-top: 0.35rem;
        background: var(--tadaaa-accent);
        flex: 0 0 auto;
      }
      .dot[data-done="true"] {
        opacity: 0.35;
      }
      .text[data-done="true"] {
        text-decoration: line-through;
        color: var(--tadaaa-muted);
      }
      .meta {
        font-size: 0.8em;
        color: var(--tadaaa-muted);
      }
    `,
  ];

  render() {
    const todos = filteredTodos(this.snap?.feed ?? null, this.snap?.filter ?? {
      q: "",
      status: "active",
      tags: [],
    });
    if (!this.snap?.feed) return html`<div class="muted">…</div>`;
    if (todos.length === 0) return html`<div class="muted">No tasks</div>`;

    return html`
      <ul>
        ${todos.map(
          (todo: EmbedTodo) => html`
            <li>
              <span class="dot" data-done=${todo.done ? "true" : "false"}></span>
              <div>
                <div class="text" data-done=${todo.done ? "true" : "false"}>
                  ${todo.text}
                </div>
                ${todo.startAt || todo.endAt
                  ? html`<div class="meta">
                      ${todo.startAt ?? ""}${todo.endAt && todo.endAt !== todo.startAt
                        ? ` → ${todo.endAt}`
                        : ""}
                    </div>`
                  : nothing}
              </div>
            </li>
          `,
        )}
      </ul>
    `;
  }
}

@customElement("tadaaa-kpi")
export class TadaaaKpi extends EmbedView {
  static styles = [
    shared,
    css`
      .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(6.5rem, 1fr));
        gap: 0.5rem;
      }
      .cell {
        border: 1px solid var(--tadaaa-border);
        border-radius: var(--tadaaa-radius, 12px);
        padding: 0.65rem 0.75rem;
      }
      .n {
        font-size: 1.4em;
        font-weight: 700;
        color: var(--tadaaa-accent);
      }
      .l {
        font-size: 0.8em;
        color: var(--tadaaa-muted);
      }
    `,
  ];

  render() {
    const stats = this.snap?.feed?.stats;
    if (!stats) return html`<div class="muted">…</div>`;
    const cells = [
      {n: stats.open, l: "Open"},
      {n: stats.done, l: "Done"},
      {n: stats.overdue, l: "Overdue"},
      {n: stats.dated, l: "Dated"},
    ];
    return html`
      <div class="grid">
        ${cells.map(
          (c) => html`<div class="cell"><div class="n">${c.n}</div><div class="l">${c.l}</div></div>`,
        )}
      </div>
    `;
  }
}

@customElement("tadaaa-agenda")
export class TadaaaAgenda extends EmbedView {
  static styles = [
    shared,
    css`
      .item {
        display: grid;
        grid-template-columns: 6.5rem 1fr;
        gap: 0.75rem;
        padding: 0.55rem 0;
        border-bottom: 1px solid var(--tadaaa-border);
      }
      .when {
        font-size: 0.85em;
        color: var(--tadaaa-muted);
      }
      .title {
        font-weight: 560;
      }
    `,
  ];

  @property({type: Number}) days = 14;

  render() {
    const filter = this.snap?.filter ?? {q: "", status: "active", tags: []};
    const todos = filteredTodos(this.snap?.feed ?? null, filter)
      .filter((t) => t.startAt || t.endAt)
      .sort((a, b) => (a.startAt ?? a.endAt ?? "").localeCompare(b.startAt ?? b.endAt ?? ""));

    const horizon = Date.now() + this.days * 86400000;
    const upcoming = todos.filter((t) => {
      const raw = t.startAt ?? t.endAt;
      if (!raw) return false;
      const ts = Date.parse(raw.length === 10 ? `${raw}T00:00:00Z` : raw);
      return !Number.isNaN(ts) && ts <= horizon;
    });

    if (!this.snap?.feed) return html`<div class="muted">…</div>`;
    if (upcoming.length === 0) return html`<div class="muted">No upcoming tasks</div>`;

    return html`
      <div class="card">
        ${upcoming.map(
          (todo) => html`
            <div class="item">
              <div class="when">${todo.startAt ?? todo.endAt}</div>
              <div class="title">${todo.text}</div>
            </div>
          `,
        )}
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tadaaa-filter": TadaaaFilter;
    "tadaaa-list": TadaaaList;
    "tadaaa-kpi": TadaaaKpi;
    "tadaaa-agenda": TadaaaAgenda;
  }
}
