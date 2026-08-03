import "./host";
import "./views";
import {TadaaaEmbed} from "./host";
import type {EmbedFilter, EmbedTheme} from "./types";

export type CreateEmbedOptions = {
  target: string | HTMLElement;
  key: string;
  apiBase: string;
  theme?: Partial<EmbedTheme>;
  filter?: Partial<EmbedFilter>;
  view?: string;
  poll?: number;
  widgets?: Array<"filter" | "kpi" | "list" | "agenda">;
};

export function create(options: CreateEmbedOptions): TadaaaEmbed {
  const target =
    typeof options.target === "string"
      ? document.querySelector(options.target)
      : options.target;
  if (!target) {
    throw new Error("Tadaaa embed target not found");
  }

  const host = document.createElement("tadaaa-embed") as TadaaaEmbed;
  host.key = options.key;
  host.apiBase = options.apiBase;
  if (options.theme?.theme) host.theme = options.theme.theme;
  if (options.theme?.accent) host.accent = options.theme.accent;
  if (options.theme?.font) host.font = options.theme.font;
  if (options.theme?.radius) host.radius = options.theme.radius;
  if (options.theme?.density) host.density = options.theme.density;
  if (options.poll !== undefined) host.poll = options.poll;
  if (options.view) host.view = options.view;

  const widgets = options.widgets ?? (options.view ? [] : ["list"]);
  for (const w of widgets) {
    host.appendChild(document.createElement(`tadaaa-${w}`));
  }

  target.replaceChildren(host);

  if (options.filter) {
    queueMicrotask(() => {
      host.store.set({filter: {...host.store.get().filter, ...options.filter}});
    });
  }

  return host;
}

export {TadaaaEmbed};
export type * from "./types";
