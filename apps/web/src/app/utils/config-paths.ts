/** Chemins UI configuration (pages globales hors hub). */

export type ConfigSection =
  | "appearance"
  | "notifications"
  | "install"
  | "links"
  | "account"
  | "accountLogin"
  | "accountRegister"
  | "mcp"
  | "webhooks"
  | "activity"
  | "usage"
  | "sync"
  | "cloud"
  | "local"
  | "export"
  | "p2p"
  | "maintenance";

export const SECTION_PATHS: Record<ConfigSection, string> = {
  appearance: "/settings/appearance",
  notifications: "/settings/notifications",
  install: "/settings/install",
  links: "/settings/links",
  account: "/account",
  accountLogin: "/account/login",
  accountRegister: "/account/register",
  mcp: "/connectivity/mcp",
  webhooks: "/connectivity/webhooks",
  activity: "/connectivity/activity",
  usage: "/connectivity/usage",
  sync: "/data/sync",
  cloud: "/data/cloud",
  local: "/data/local",
  export: "/data/export",
  p2p: "/data/p2p",
  maintenance: "/data/maintenance",
};

export const SETTINGS_DEFAULT = SECTION_PATHS.appearance;

export function configSectionPath(section: ConfigSection): string {
  return SECTION_PATHS[section];
}

/** Anciennes URLs `/config/*` → nouvelles routes. */
export const LEGACY_CONFIG_REDIRECTS: Record<string, string> = {
  "/config": SETTINGS_DEFAULT,
  "/config/": SETTINGS_DEFAULT,
  "/config/appearance": SECTION_PATHS.appearance,
  "/config/account": SECTION_PATHS.account,
  "/config/issues": SECTION_PATHS.links,
  "/config/webhooks": SECTION_PATHS.webhooks,
  "/config/activity": SECTION_PATHS.activity,
  "/config/usage": SECTION_PATHS.usage,
  "/config/datasets": SECTION_PATHS.local,
  "/config/data": SECTION_PATHS.export,
  "/config/p2p": SECTION_PATHS.p2p,
  "/config/maintenance": SECTION_PATHS.maintenance,
};
