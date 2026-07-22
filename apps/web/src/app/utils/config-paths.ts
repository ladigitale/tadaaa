/** Chemins UI configuration. */
export const CONFIG_ROOT = "/config";

export type ConfigSection =
  | "account"
  | "issues"
  | "data"
  | "p2p"
  | "maintenance"
  | "datasets";

export function configSectionPath(section: ConfigSection): string {
  if (section === "issues") return CONFIG_ROOT;
  return `${CONFIG_ROOT}/${section}`;
}
