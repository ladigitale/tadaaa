/** App locales — English default, French optional. */

export const APP_LOCALES = ["en", "fr"] as const;
export type AppLocale = (typeof APP_LOCALES)[number];

export const DEFAULT_APP_LOCALE: AppLocale = "en";

/** Concorde language key (see HTML.getLanguage). */
export const SONIC_LANGUAGE_STORAGE_KEY = "SonicSelectedLanguage";

export function isAppLocale(value: string): value is AppLocale {
  return (APP_LOCALES as readonly string[]).includes(value);
}

/** Normalize `en-US` / `fr-FR` → `en` / `fr`. */
export function normalizeAppLocale(raw: string | null | undefined): AppLocale {
  if (!raw) return DEFAULT_APP_LOCALE;
  const primary = raw.trim().toLowerCase().split(/[-_]/)[0] ?? "";
  return isAppLocale(primary) ? primary : DEFAULT_APP_LOCALE;
}

export function getAppLocale(): AppLocale {
  try {
    if (typeof localStorage !== "undefined") {
      const stored = localStorage.getItem(SONIC_LANGUAGE_STORAGE_KEY);
      if (stored) return normalizeAppLocale(stored);
    }
  } catch {
    /* ignore — e.g. service worker */
  }
  try {
    if (typeof document !== "undefined") {
      return normalizeAppLocale(document.documentElement.lang);
    }
  } catch {
    /* ignore */
  }
  return DEFAULT_APP_LOCALE;
}

/**
 * Persist locale for Concorde wording (`Accept-Language` + `html[lang]` observer).
 */
export function setAppLocale(locale: AppLocale): void {
  const next = normalizeAppLocale(locale);
  try {
    localStorage.setItem(SONIC_LANGUAGE_STORAGE_KEY, next);
  } catch {
    /* ignore */
  }
  document.documentElement.lang = next;
}

/** Ensure default English on first visit. */
export function initAppLocale(): AppLocale {
  let stored: string | null = null;
  try {
    stored = localStorage.getItem(SONIC_LANGUAGE_STORAGE_KEY);
  } catch {
    /* ignore */
  }
  if (!stored) {
    setAppLocale(DEFAULT_APP_LOCALE);
    return DEFAULT_APP_LOCALE;
  }
  const locale = normalizeAppLocale(stored);
  document.documentElement.lang = locale;
  return locale;
}

export function localeLabel(locale: AppLocale): string {
  return locale === "fr" ? "Français" : "English";
}
