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
  | "bubblegum"
  | "cafe"
  | "lavande"
  | "crepuscule"
  | "encre";

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
    description: "Night blue, red ink & brown primary · Rowdies",
    swatches: ["#0a1220", "#8b4a28", "#ff3d4a"],
    dark: true,
  },
  {
    id: "default",
    label: "Default",
    description: "Electric violet & acid lime · Space Grotesk",
    swatches: ["#f4f2ff", "#4f1fff", "#b8f000"],
    dark: false,
  },
  {
    id: "dark",
    label: "Dark",
    description: "Void black & laser accents · Sora",
    swatches: ["#07070c", "#a78bfa", "#c8ff2a"],
    dark: true,
  },
  {
    id: "dracula",
    label: "Dracula",
    description: "Hyper violet & toxic mint · Chakra Petch",
    swatches: ["#191a22", "#d580ff", "#69ff8a"],
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
    description: "Aurora punch on polar night · Oxanium",
    swatches: ["#1b2430", "#5eead4", "#bbf74a"],
    dark: true,
  },
  {
    id: "synthwave",
    label: "Synthwave",
    description: "Hot magenta / ice cyan · Monoton",
    swatches: ["#12001f", "#ff1fa8", "#00f7ff"],
    dark: true,
  },
  {
    id: "matcha",
    label: "Matcha",
    description: "Foam green & yuzu · Mochiy Pop",
    swatches: ["#eef8e4", "#2f8f3a", "#ff9a1a"],
    dark: false,
  },
  {
    id: "terminal",
    label: "Terminal",
    description: "Phosphor CRT · VT323",
    swatches: ["#020805", "#39ff88", "#2ad4ff"],
    dark: true,
  },
  {
    id: "bubblegum",
    label: "Bubblegum",
    description: "Candy shop overload · Bangers",
    swatches: ["#fff5fb", "#ff1aad", "#ffcc00"],
    dark: false,
  },
  {
    id: "cafe",
    label: "Café",
    description: "Burnt caramel chalkboard · Bebas Neue",
    swatches: ["#efe2d0", "#8b2e0e", "#e07010"],
    dark: false,
  },
  {
    id: "lavande",
    label: "Lavande",
    description: "Electric iris · Poiret One",
    swatches: ["#f0e8ff", "#7c2cff", "#4a7cff"],
    dark: false,
  },
  {
    id: "crepuscule",
    label: "Crépuscule",
    description: "Neon dusk · Teko",
    swatches: ["#14081c", "#ff6a1a", "#5a9cff"],
    dark: true,
  },
  {
    id: "encre",
    label: "Encre",
    description: "Stencil ink on paper · Unbounded",
    swatches: ["#f2eee6", "#121a6e", "#186090"],
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
