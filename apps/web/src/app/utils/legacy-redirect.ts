import {html} from "lit";
import {navigateTo} from "../utils/navigate";
import {
  LEGACY_CONFIG_REDIRECTS,
  SETTINGS_DEFAULT,
} from "../utils/config-paths";

/** Redirect immédiat vers une nouvelle route (bookmarks `/config/*`). */
export function redirectPage(to: string) {
  queueMicrotask(() => navigateTo(to, true));
  return html``;
}

export function legacyConfigRedirect(fromPath: string) {
  const target = LEGACY_CONFIG_REDIRECTS[fromPath] ?? SETTINGS_DEFAULT;
  return redirectPage(target);
}
