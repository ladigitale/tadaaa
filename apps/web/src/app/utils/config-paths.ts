/** Chemins UI configuration. */
export const CONFIG_ROOT = "/config";

export type ConfigSection =
  | "account"
  | "appearance"
  | "issues"
  | "data"
  | "p2p"
  | "maintenance"
  | "datasets";

export function configSectionPath(section: ConfigSection): string {
  return `${CONFIG_ROOT}/${section}`;
}
