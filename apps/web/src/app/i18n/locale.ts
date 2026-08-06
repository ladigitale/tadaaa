/** App locales — English fallback, French optional. */

export const APP_LOCALES = ["en", "fr"] as const;
export type AppLocale = (typeof APP_LOCALES)[number];

export const DEFAULT_APP_LOCALE: AppLocale = "en";

/**
 * Concorde language key (`HTML.getLanguage` prefers this over `html[lang]`).
 * Written only when the user picks a language in appearance settings.
 */
export const SONIC_LANGUAGE_STORAGE_KEY = "SonicSelectedLanguage";

/** Marks that `SonicSelectedLanguage` was set by the user (not auto-init). */
export const LOCALE_EXPLICIT_STORAGE_KEY = "tadaaa.locale.explicit";

export function isAppLocale(value: string): value is AppLocale {
  return (APP_LOCALES as readonly string[]).includes(value);
}

/** Normalize `en-US` / `fr-FR` → `en` / `fr`; unknown → English. */
export function normalizeAppLocale(raw: string | null | undefined): AppLocale {
  if (!raw) return DEFAULT_APP_LOCALE;
  const primary = raw.trim().toLowerCase().split(/[-_]/)[0] ?? "";
  return isAppLocale(primary) ? primary : DEFAULT_APP_LOCALE;
}

/**
 * Best available app locale from the browser preference list.
 * Walks `navigator.languages` then `navigator.language`; first match wins.
 * Falls back to English when nothing maps (e.g. `de`, `es`).
 */
export function resolveBrowserLocale(
  languages: readonly string[] = readNavigatorLanguages(),
): AppLocale {
  for (const raw of languages) {
    if (!raw) continue;
    const primary = raw.trim().toLowerCase().split(/[-_]/)[0] ?? "";
    if (isAppLocale(primary)) return primary;
  }
  return DEFAULT_APP_LOCALE;
}

function readNavigatorLanguages(): string[] {
  try {
    if (typeof navigator === "undefined") return [];
    const list = navigator.languages?.length
      ? [...navigator.languages]
      : [];
    if (navigator.language) list.push(navigator.language);
    return list;
  } catch {
    return [];
  }
}

function hasExplicitLocalePreference(): boolean {
  try {
    if (typeof localStorage === "undefined") return false;
    return localStorage.getItem(LOCALE_EXPLICIT_STORAGE_KEY) === "1";
  } catch {
    return false;
  }
}

function readExplicitLocale(): AppLocale | null {
  if (!hasExplicitLocalePreference()) return null;
  try {
    if (typeof localStorage === "undefined") return null;
    const stored = localStorage.getItem(SONIC_LANGUAGE_STORAGE_KEY);
    if (!stored) return null;
    return normalizeAppLocale(stored);
  } catch {
    return null;
  }
}

/** Explicit user choice if any, else browser → available locale (en fallback). */
export function getAppLocale(): AppLocale {
  return readExplicitLocale() ?? resolveBrowserLocale();
}

/**
 * Persist locale for Concorde wording (`Accept-Language` + `html[lang]` observer).
 * Call only on voluntary user choice (appearance settings).
 */
export function setAppLocale(locale: AppLocale): void {
  const next = normalizeAppLocale(locale);
  try {
    localStorage.setItem(SONIC_LANGUAGE_STORAGE_KEY, next);
    localStorage.setItem(LOCALE_EXPLICIT_STORAGE_KEY, "1");
  } catch {
    /* ignore */
  }
  document.documentElement.lang = next;
}

/**
 * Apply locale at boot: stored user choice if present, otherwise browser mapping.
 * Does not persist the browser pick.
 *
 * Migration: pre-flag installs only auto-wrote `en`. A stored `fr` means the user
 * chose French — promote to explicit. A lone `en` is cleared so the browser can win.
 */
export function initAppLocale(): AppLocale {
  const explicit = readExplicitLocale();
  if (explicit) {
    document.documentElement.lang = explicit;
    return explicit;
  }

  try {
    if (typeof localStorage !== "undefined") {
      const legacy = localStorage.getItem(SONIC_LANGUAGE_STORAGE_KEY);
      if (legacy && normalizeAppLocale(legacy) === "fr") {
        setAppLocale("fr");
        return "fr";
      }
      localStorage.removeItem(SONIC_LANGUAGE_STORAGE_KEY);
      localStorage.removeItem(LOCALE_EXPLICIT_STORAGE_KEY);
    }
  } catch {
    /* ignore */
  }

  const locale = resolveBrowserLocale();
  document.documentElement.lang = locale;
  return locale;
}

export function localeLabel(locale: AppLocale): string {
  return locale === "fr" ? "Français" : "English";
}
