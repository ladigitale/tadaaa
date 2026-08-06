/** Sister-app return URL after Tadaaa login (SSO handoff). */
const STORAGE_KEY = "tadaaa.sisterReturnTo";

export function captureSisterReturnParam(): string {
  const value = new URLSearchParams(window.location.search)
    .get("return_to")
    ?.trim();
  if (value) {
    try {
      sessionStorage.setItem(STORAGE_KEY, value);
    } catch {
      /* ignore */
    }
  }
  return value ?? "";
}

export function readSisterReturnTo(): string {
  try {
    return (sessionStorage.getItem(STORAGE_KEY) ?? "").trim();
  } catch {
    return "";
  }
}

export function clearSisterReturnTo(): void {
  try {
    sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    /* ignore */
  }
}

/** Allow localhost + http(s) URLs only (sister SPAs). */
export function isAllowedSisterReturnUrl(url: string): boolean {
  try {
    const u = new URL(url);
    if (u.protocol !== "http:" && u.protocol !== "https:") return false;
    if (u.hostname === "localhost" || u.hostname === "127.0.0.1") return true;
    // Same site as Tadaaa (e.g. belts.example.com when app is on app.example.com)
    const here = window.location.hostname;
    const parts = here.split(".");
    if (parts.length >= 2) {
      const parent = parts.slice(-2).join(".");
      if (u.hostname === here || u.hostname.endsWith("." + parent) || u.hostname === parent) {
        return true;
      }
    }
    return false;
  } catch {
    return false;
  }
}
