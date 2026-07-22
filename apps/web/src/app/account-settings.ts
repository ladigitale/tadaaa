/** Session compte cloud (JWT) — hors IndexedDB locale. */

export type CloudUser = {
  id: string;
  email: string;
  createdAt: string;
  activeDatasetId: string | null;
  status?: "pending" | "active" | "rejected" | "disabled";
  roles?: string[];
};

export type AccountSettings = {
  /** URL racine de l’API Symfony (sans /api). */
  apiBaseUrl: string;
  token: string | null;
  user: CloudUser | null;
};

/** URL API cloud par défaut (sans suffixe /api). */
export function resolveDefaultApiBaseUrl(): string {
  const fromEnv = import.meta.env.VITE_API_BASE_URL?.trim();
  if (fromEnv) return fromEnv.replace(/\/+$/, "");
  if (import.meta.env.DEV) {
    return "https://tada-api.julien.test";
  }
  return "";
}

/** Retire un /api final et normalise le trailing slash. */
export function normalizeApiBaseUrl(url: string): string {
  let trimmed = url.trim();
  if (!trimmed) return resolveDefaultApiBaseUrl();
  if (import.meta.env.DEV && trimmed.startsWith("http://tada-api.julien.test")) {
    trimmed = trimmed.replace(/^http:/, "https:");
  }
  trimmed = trimmed.replace(/\/+$/, "");
  if (trimmed.endsWith("/api")) {
    trimmed = trimmed.slice(0, -4);
  }
  return trimmed || resolveDefaultApiBaseUrl();
}

export const DEFAULT_API_BASE_URL = resolveDefaultApiBaseUrl();

const STORAGE_KEY = "tada-account";

export function defaultAccountSettings(): AccountSettings {
  return {
    apiBaseUrl: DEFAULT_API_BASE_URL,
    token: null,
    user: null,
  };
}

export function loadAccountSettings(): AccountSettings {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return defaultAccountSettings();
    const parsed = JSON.parse(raw) as Partial<AccountSettings>;
    return {
      apiBaseUrl: normalizeApiBaseUrl(parsed.apiBaseUrl ?? ""),
      token: parsed.token ?? null,
      user: parsed.user ?? null,
    };
  } catch {
    return defaultAccountSettings();
  }
}

export function saveAccountSettings(settings: AccountSettings): void {
  localStorage.setItem(
    STORAGE_KEY,
    JSON.stringify({
      apiBaseUrl: normalizeApiBaseUrl(settings.apiBaseUrl),
      token: settings.token,
      user: settings.user,
    }),
  );
}

export function clearAccountSession(): AccountSettings {
  const next = {
    ...loadAccountSettings(),
    token: null,
    user: null,
  };
  saveAccountSettings(next);
  return next;
}

export function isAccountConnected(
  settings: AccountSettings = loadAccountSettings(),
): boolean {
  return Boolean(settings.token && settings.user?.email);
}

export function isCloudAdmin(
  settings: AccountSettings = loadAccountSettings(),
): boolean {
  return Boolean(settings.user?.roles?.includes("ROLE_ADMIN"));
}

export function getCloudApiRoot(
  settings: AccountSettings = loadAccountSettings(),
): string {
  const base = settings.apiBaseUrl.replace(/\/$/, "");
  if (
    import.meta.env.DEV &&
    typeof location !== "undefined" &&
    location.protocol === "http:" &&
    base.includes("tada-api.julien.test")
  ) {
    return `${location.origin}/tada-cloud/api`;
  }
  return `${base}/api`;
}

/** URL MCP dérivée de l’API cloud (sans /api). */
export function getMcpUrl(
  settings: AccountSettings = loadAccountSettings(),
): string {
  const base = settings.apiBaseUrl.replace(/\/$/, "");
  return `${base}/mcp`;
}
