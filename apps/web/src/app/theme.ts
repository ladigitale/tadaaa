/** UI themes via Concorde CSS variables (`--sc-*`). */

export type AppThemeId =
  | "coraline"
  | "default"
  | "dark"
  | "dracula"
  | "windows"
  | "nord"
  | "synthwave"
  | "matcha"
  | "terminal"
  | "bubblegum";

export type AppThemeMeta = {
  id: AppThemeId;
  label: string;
  description: string;
  /** Preview swatches (base, primary, accent). */
  swatches: [string, string, string];
  dark: boolean;
};

export const APP_THEMES: AppThemeMeta[] = [
  {
    id: "coraline",
    label: "Coraline",
    description: "Deep blue ground, coral type",
    swatches: ["#0f1c3a", "#e85a4a", "#f0c4b8"],
    dark: true,
  },
  {
    id: "default",
    label: "Default",
    description: "Stone light & Tadaaa blue",
    swatches: ["#fafaf9", "#10298e", "#00cc57"],
    dark: false,
  },
  {
    id: "dark",
    label: "Dark",
    description: "Soft contrast for night use",
    swatches: ["#1c1917", "#93c5fd", "#4ade80"],
    dark: true,
  },
  {
    id: "dracula",
    label: "Dracula",
    description: "Classic purple, pink, and cyan",
    swatches: ["#282a36", "#bd93f9", "#50fa7b"],
    dark: true,
  },
  {
    id: "windows",
    label: "Windows 95",
    description: "Gray, teal, and square corners",
    swatches: ["#c0c0c0", "#000080", "#008080"],
    dark: false,
  },
  {
    id: "nord",
    label: "Nord",
    description: "Arctic cool blues and greens",
    swatches: ["#2e3440", "#88c0d0", "#a3be8c"],
    dark: true,
  },
  {
    id: "synthwave",
    label: "Synthwave",
    description: "Retro pink / cyan neon",
    swatches: ["#1a0b2e", "#ff2d95", "#00f0ff"],
    dark: true,
  },
  {
    id: "matcha",
    label: "Matcha",
    description: "Soft greens, tea vibes",
    swatches: ["#f4f7ef", "#4f7a3c", "#3d8b8b"],
    dark: false,
  },
  {
    id: "terminal",
    label: "Terminal",
    description: "Phosphor green on black",
    swatches: ["#0a0e0a", "#33ff66", "#33ccff"],
    dark: true,
  },
  {
    id: "bubblegum",
    label: "Bubblegum",
    description: "Chewing-gum pastels",
    swatches: ["#fff0f7", "#ff4da6", "#5ac8ff"],
    dark: false,
  },
];

export const DEFAULT_THEME_ID: AppThemeId = "default";

const STORAGE_KEY = "tada-theme";

const THEME_IDS = new Set(APP_THEMES.map((t) => t.id));

export function isAppThemeId(value: unknown): value is AppThemeId {
  return typeof value === "string" && THEME_IDS.has(value as AppThemeId);
}

export function loadThemeId(): AppThemeId {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (isAppThemeId(raw)) return raw;
  } catch {
    /* private mode / SSR */
  }
  return DEFAULT_THEME_ID;
}

export function saveThemeId(id: AppThemeId): void {
  localStorage.setItem(STORAGE_KEY, id);
}

/** Apply theme on <html> (and keep sonic-theme in sync for host overrides). */
export function applyTheme(id: AppThemeId): void {
  const root = document.documentElement;
  if (id === DEFAULT_THEME_ID) {
    root.removeAttribute("data-theme");
  } else {
    root.setAttribute("data-theme", id);
  }
  const meta = APP_THEMES.find((t) => t.id === id);
  root.style.colorScheme = meta?.dark ? "dark" : "light";
}

export function setTheme(id: AppThemeId): void {
  saveThemeId(id);
  applyTheme(id);
}

export function initTheme(): void {
  applyTheme(loadThemeId());
}
