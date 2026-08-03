import type {EmbedFeed, EmbedFilter, EmbedState, EmbedTheme, EmbedTodo} from "./types";

type Listener = () => void;

const DEFAULT_THEME: EmbedTheme = {
  theme: "auto",
  accent: "#0d9488",
  font: "system-ui, sans-serif",
  radius: "12px",
  density: "comfortable",
};

const DEFAULT_FILTER: EmbedFilter = {
  q: "",
  status: "active",
  tags: [],
};

export function createStore(partial?: Partial<EmbedState>) {
  let state: EmbedState = {
    key: partial?.key ?? "",
    apiBase: partial?.apiBase ?? "",
    loading: partial?.loading ?? false,
    error: partial?.error ?? null,
    feed: partial?.feed ?? null,
    filter: {...DEFAULT_FILTER, ...(partial?.filter ?? {})},
    theme: {...DEFAULT_THEME, ...(partial?.theme ?? {})},
  };

  const listeners = new Set<Listener>();

  const notify = () => {
    for (const listener of listeners) listener();
  };

  return {
    get(): EmbedState {
      return state;
    },
    set(patch: Partial<EmbedState>) {
      state = {
        ...state,
        ...patch,
        filter: patch.filter ? {...state.filter, ...patch.filter} : state.filter,
        theme: patch.theme ? {...state.theme, ...patch.theme} : state.theme,
      };
      notify();
    },
    subscribe(listener: Listener): () => void {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
}

export type EmbedStore = ReturnType<typeof createStore>;

export function filteredTodos(feed: EmbedFeed | null, filter: EmbedFilter): EmbedTodo[] {
  if (!feed) return [];
  const q = filter.q.trim().toLowerCase();
  return feed.todos.filter((todo) => {
    if (filter.status === "active" && todo.done) return false;
    if (filter.status === "done" && !todo.done) return false;
    if (filter.tags.length > 0 && !filter.tags.some((t) => todo.tagIds.includes(t))) {
      return false;
    }
    if (q && !todo.text.toLowerCase().includes(q)) return false;
    return true;
  });
}

export function resolveApiBase(explicit?: string): string {
  if (explicit) return explicit.replace(/\/$/, "");
  const fromScript = document.currentScript?.getAttribute("data-api");
  if (fromScript) return fromScript.replace(/\/$/, "");
  return "";
}
