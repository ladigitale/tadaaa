/** Avatars : Gravatar (SHA-256) + fallback initiales déterministes. */

const encoder = new TextEncoder();

export async function sha256Hex(value: string): Promise<string> {
  const buffer = await crypto.subtle.digest("SHA-256", encoder.encode(value));
  return Array.from(new Uint8Array(buffer))
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");
}

export function normalizeEmail(email: string): string {
  return email.trim().toLowerCase();
}

/** URL Gravatar ; `d=404` pour basculer sur le fallback local si absent. */
export async function gravatarUrl(
  email: string,
  size = 80,
): Promise<string> {
  const hash = await sha256Hex(normalizeEmail(email));
  return `https://www.gravatar.com/avatar/${hash}?s=${size}&d=404&r=g`;
}

/** Initiales affichables (1–2 lettres). */
export function emailInitials(email: string): string {
  const local = normalizeEmail(email).split("@")[0] ?? "?";
  const parts = local.split(/[._+\-]+/).filter(Boolean);
  if (parts.length >= 2) {
    return (parts[0]![0]! + parts[1]![0]!).toUpperCase();
  }
  return local.slice(0, 2).toUpperCase() || "?";
}

/** Couleur de fond stable à partir de l’email (HSL). */
export function emailAvatarColor(email: string): string {
  const normalized = normalizeEmail(email);
  let hash = 0;
  for (let i = 0; i < normalized.length; i++) {
    hash = (hash * 31 + normalized.charCodeAt(i)) >>> 0;
  }
  const hue = hash % 360;
  return `hsl(${hue} 42% 42%)`;
}
