/** Réglages UI persistés en localStorage (hors API mock). */

export type LinkDetector = {
  id: string;
  name: string;
  /** Regexp (sans flags). Le 1er groupe capturant = id pour `{id}` dans urlTemplate. */
  pattern: string;
  /** Modèle d’URL, `{id}` = groupe capturé. */
  urlTemplate: string;
};

export type AppSettings = {
  linkDetectors: LinkDetector[];
  /** Notifications Web (partage, tâches distantes). */
  webNotifications: boolean;
  /** @deprecated Migrated into linkDetectors[0]. */
  issueUrlTemplate?: string;
  /** @deprecated Migrated into linkDetectors[0]. */
  issuePattern?: string;
};

export const DEFAULT_LINK_DETECTOR: LinkDetector = {
  id: "default-issues",
  name: "Issues",
  pattern: "RM-(\\d+)",
  urlTemplate: "https://example.com/issues/{id}",
};

export const DEFAULT_APP_SETTINGS: AppSettings = {
  linkDetectors: [{...DEFAULT_LINK_DETECTOR}],
  webNotifications: false,
};

const STORAGE_KEY = "tada-settings";

/** Aperçu live (page config) sans persister. */
let previewOverride: AppSettings | null = null;

export function setAppSettingsPreview(settings: AppSettings | null): void {
  previewOverride = settings;
}

function newDetectorId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `ld-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

export function createLinkDetector(
  partial: Partial<Omit<LinkDetector, "id">> & {id?: string} = {},
): LinkDetector {
  return {
    id: partial.id?.trim() || newDetectorId(),
    name: partial.name?.trim() || "Link",
    pattern: partial.pattern?.trim() || DEFAULT_LINK_DETECTOR.pattern,
    urlTemplate:
      partial.urlTemplate?.trim() || DEFAULT_LINK_DETECTOR.urlTemplate,
  };
}

function normalizeDetectors(
  raw: unknown,
  options: {allowEmpty?: boolean} = {},
): LinkDetector[] {
  const allowEmpty = options.allowEmpty === true;
  if (!Array.isArray(raw)) {
    return allowEmpty ? [] : [{...DEFAULT_LINK_DETECTOR}];
  }
  const out: LinkDetector[] = [];
  for (const item of raw) {
    if (!item || typeof item !== "object") continue;
    const row = item as Partial<LinkDetector>;
    const name = row.name?.trim();
    const pattern = row.pattern?.trim();
    const urlTemplate = row.urlTemplate?.trim();
    if (!name || !pattern || !urlTemplate) continue;
    out.push({
      id: row.id?.trim() || newDetectorId(),
      name,
      pattern,
      urlTemplate,
    });
  }
  if (out.length === 0) {
    return allowEmpty ? [] : [{...DEFAULT_LINK_DETECTOR}];
  }
  return out;
}

function migrateLegacy(parsed: Partial<AppSettings>): LinkDetector[] {
  if (Array.isArray(parsed.linkDetectors) && parsed.linkDetectors.length > 0) {
    return normalizeDetectors(parsed.linkDetectors);
  }
  const url = parsed.issueUrlTemplate?.trim();
  const pattern = parsed.issuePattern?.trim();
  if (url || pattern) {
    return [
      createLinkDetector({
        id: "default-issues",
        name: "Issues",
        pattern: pattern || DEFAULT_LINK_DETECTOR.pattern,
        urlTemplate: url || DEFAULT_LINK_DETECTOR.urlTemplate,
      }),
    ];
  }
  return [{...DEFAULT_LINK_DETECTOR}];
}

export function loadAppSettings(): AppSettings {
  if (previewOverride) return {...previewOverride, linkDetectors: [...previewOverride.linkDetectors]};
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return {...DEFAULT_APP_SETTINGS, linkDetectors: [{...DEFAULT_LINK_DETECTOR}]};
    const parsed = JSON.parse(raw) as Partial<AppSettings>;
    return {
      linkDetectors: migrateLegacy(parsed),
      webNotifications:
        typeof parsed.webNotifications === "boolean"
          ? parsed.webNotifications
          : DEFAULT_APP_SETTINGS.webNotifications,
    };
  } catch {
    return {...DEFAULT_APP_SETTINGS, linkDetectors: [{...DEFAULT_LINK_DETECTOR}]};
  }
}

export function saveAppSettings(settings: AppSettings): void {
  localStorage.setItem(
    STORAGE_KEY,
    JSON.stringify({
      linkDetectors: normalizeDetectors(settings.linkDetectors, {
        allowEmpty: true,
      }),
      webNotifications: settings.webNotifications,
    }),
  );
}

/** Merge cloud account detectors into local settings (source of truth when connected). */
export function applyCloudLinkDetectors(detectors: LinkDetector[]): AppSettings {
  const next: AppSettings = {
    ...loadAppSettings(),
    linkDetectors: normalizeDetectors(detectors, {allowEmpty: true}),
  };
  saveAppSettings(next);
  return next;
}

export function areWebNotificationsEnabled(
  settings: AppSettings = loadAppSettings(),
): boolean {
  return settings.webNotifications === true;
}

export function buildDetectorUrl(
  detector: LinkDetector,
  id: string,
): string {
  return detector.urlTemplate.replaceAll("{id}", id);
}

/** Regexp `gi` pour un détecteur ; 1er groupe = id. */
export function getDetectorRegexp(detector: LinkDetector): RegExp | null {
  try {
    return new RegExp(detector.pattern, "gi");
  } catch {
    return null;
  }
}

export function validateLinkDetectorPattern(pattern: string): string | null {
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

export function validateLinkDetector(detector: LinkDetector): string | null {
  if (!detector.name.trim()) return "Le nom est requis.";
  const patternError = validateLinkDetectorPattern(detector.pattern);
  if (patternError) return patternError;
  if (!detector.urlTemplate.trim().includes("{id}")) {
    return "Le modèle d’URL doit contenir {id}.";
  }
  return null;
}

/** @deprecated Prefer buildDetectorUrl / linkDetectors. */
export function buildIssueUrl(
  issueId: string,
  settings: AppSettings = loadAppSettings(),
): string {
  const detector = settings.linkDetectors[0] ?? DEFAULT_LINK_DETECTOR;
  return buildDetectorUrl(detector, issueId);
}

/** @deprecated Prefer getDetectorRegexp / linkDetectors. */
export function getIssueTokenRegexp(
  settings: AppSettings = loadAppSettings(),
): RegExp {
  const detector = settings.linkDetectors[0] ?? DEFAULT_LINK_DETECTOR;
  return getDetectorRegexp(detector) ?? new RegExp(DEFAULT_LINK_DETECTOR.pattern, "gi");
}

/** @deprecated Prefer validateLinkDetectorPattern. */
export function validateIssuePattern(pattern: string): string | null {
  return validateLinkDetectorPattern(pattern);
}
