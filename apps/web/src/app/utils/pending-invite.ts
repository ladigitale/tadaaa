import type {AccountSettings} from "../account-settings";
import {acceptDatasetInvite} from "../cloud-api/client";
import {openCloudDatasetForEditing} from "../sync/engine";
import {
  configSectionPath,
  type ConfigSection,
} from "./config-paths";
import {navigateTo} from "./navigate";
import {TACHE_ROOT} from "./tache-paths";

const STORAGE_KEY = "tadaaa.pendingInviteToken";

export function savePendingInviteToken(token: string): void {
  const value = token.trim();
  if (!value) return;
  try {
    sessionStorage.setItem(STORAGE_KEY, value);
  } catch {
    /* private mode / quota */
  }
}

export function readPendingInviteToken(): string {
  try {
    return (sessionStorage.getItem(STORAGE_KEY) ?? "").trim();
  } catch {
    return "";
  }
}

export function clearPendingInviteToken(): void {
  try {
    sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    /* ignore */
  }
}

/** Capture `?invite=` from the current URL into sessionStorage. */
export function captureInviteQueryParam(): string {
  const token = new URLSearchParams(window.location.search)
    .get("invite")
    ?.trim();
  if (token) savePendingInviteToken(token);
  return token ?? "";
}

export function inviteAuthPath(
  section: Extract<ConfigSection, "accountLogin" | "accountRegister">,
  token?: string,
): string {
  const invite = (token ?? readPendingInviteToken()).trim();
  const base = configSectionPath(section);
  if (!invite) return base;
  return `${base}?invite=${encodeURIComponent(invite)}`;
}

/**
 * If a pending invite token exists: accept it, open the dataset, navigate to tasks.
 * @returns true when an invite was present and handled (success or thrown).
 */
export async function completePendingInvite(
  account: AccountSettings,
): Promise<boolean> {
  const token = readPendingInviteToken();
  if (!token) return false;

  const result = await acceptDatasetInvite(token, account);
  clearPendingInviteToken();
  const sync = await openCloudDatasetForEditing({
    id: result.dataset.id,
    baseId: result.dataset.baseId,
    name: result.dataset.name,
    role: result.dataset.role,
  });
  if (sync.error) {
    throw new Error(sync.error);
  }
  navigateTo(TACHE_ROOT, true);
  return true;
}

/** After auth: complete pending invite, or fall back to tasks. */
export async function navigateAfterAuth(
  account: AccountSettings,
): Promise<void> {
  const handled = await completePendingInvite(account);
  if (!handled) navigateTo(TACHE_ROOT, true);
}
