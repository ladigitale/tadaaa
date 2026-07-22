/** Réglages UI persistés en localStorage (hors API mock). */

export type AppSettings = {
  /** Modèle d’URL, `{id}` = numéro d’issue capturé. */
  issueUrlTemplate: string;
  /**
   * Regexp (sans flags) du jeton dans le texte.
   * Le 1er groupe capturant doit être l’id numérique.
   */
  issuePattern: string;
};

export const DEFAULT_APP_SETTINGS: AppSettings = {
  issueUrlTemplate: "https://example.com/issues/{id}",
  issuePattern: "RM-(\\d+)",
};

const STORAGE_KEY = "tada-settings";

/** Aperçu live (page config) sans persister. */
let previewOverride: AppSettings | null = null;

export function setAppSettingsPreview(settings: AppSettings | null): void {
  previewOverride = settings;
}

export function loadAppSettings(): AppSettings {
  if (previewOverride) return {...previewOverride};
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return {...DEFAULT_APP_SETTINGS};
    const parsed = JSON.parse(raw) as Partial<AppSettings>;
    return {
      issueUrlTemplate:
        parsed.issueUrlTemplate?.trim() || DEFAULT_APP_SETTINGS.issueUrlTemplate,
      issuePattern:
        parsed.issuePattern?.trim() || DEFAULT_APP_SETTINGS.issuePattern,
    };
  } catch {
    return {...DEFAULT_APP_SETTINGS};
  }
}

export function saveAppSettings(settings: AppSettings): void {
  localStorage.setItem(
    STORAGE_KEY,
    JSON.stringify({
      issueUrlTemplate: settings.issueUrlTemplate.trim(),
      issuePattern: settings.issuePattern.trim(),
    }),
  );
}

export function buildIssueUrl(
  issueId: string,
  settings: AppSettings = loadAppSettings(),
): string {
  return settings.issueUrlTemplate.replaceAll("{id}", issueId);
}

/** Regexp `gi` pour trouver les jetons ; 1er groupe = id. */
export function getIssueTokenRegexp(
  settings: AppSettings = loadAppSettings(),
): RegExp {
  try {
    return new RegExp(settings.issuePattern, "gi");
  } catch {
    return new RegExp(DEFAULT_APP_SETTINGS.issuePattern, "gi");
  }
}

export function validateIssuePattern(pattern: string): string | null {
  const trimmed = pattern.trim();
  if (!trimmed) return "La regexp est requise.";
  try {
    new RegExp(trimmed, "gi");
  } catch (error) {
    return error instanceof Error ? error.message : "Regexp invalide.";
  }
  if (!trimmed.includes("(")) {
    return "Ajoutez un groupe capturant pour l’id (ex. RM-(\\d+)).";
  }
  return null;
}
