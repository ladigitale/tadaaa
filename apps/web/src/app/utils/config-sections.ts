import type {ConfigSection} from "./config-paths";
import {configSectionPath} from "./config-paths";

export type ConfigSectionChoice = {
  id: ConfigSection;
  labelKey: string;
  descriptionKey: string;
  icon: string;
  href: string;
};

export type ConfigSectionGroup = {
  id: string;
  labelKey: string;
  items: ConfigSectionChoice[];
};

/** Menu & scope header : Compte (top) → Personnalisation → Connectivité → Données. */
export const CONFIG_SECTION_GROUPS: ConfigSectionGroup[] = [
  {
    id: "account",
    labelKey: "nav.group.account",
    items: [
      {
        id: "account",
        labelKey: "config.section.account",
        descriptionKey: "config.section.account.help",
        icon: "user",
        href: configSectionPath("account"),
      },
      {
        id: "accountLogin",
        labelKey: "config.section.accountLogin",
        descriptionKey: "config.section.accountLogin.help",
        icon: "log-in",
        href: configSectionPath("accountLogin"),
      },
      {
        id: "accountRegister",
        labelKey: "config.section.accountRegister",
        descriptionKey: "config.section.accountRegister.help",
        icon: "user-plus",
        href: configSectionPath("accountRegister"),
      },
    ],
  },
  {
    id: "personalization",
    labelKey: "nav.group.personalization",
    items: [
      {
        id: "appearance",
        labelKey: "config.section.appearance",
        descriptionKey: "config.section.appearance.help",
        icon: "palette",
        href: configSectionPath("appearance"),
      },
      {
        id: "notifications",
        labelKey: "config.section.notifications",
        descriptionKey: "config.section.notifications.help",
        icon: "bell",
        href: configSectionPath("notifications"),
      },
      {
        id: "install",
        labelKey: "config.section.install",
        descriptionKey: "config.section.install.help",
        icon: "download",
        href: configSectionPath("install"),
      },
      {
        id: "links",
        labelKey: "config.section.links",
        descriptionKey: "config.section.links.help",
        icon: "link",
        href: configSectionPath("links"),
      },
    ],
  },
  {
    id: "connectivity",
    labelKey: "nav.group.connectivity",
    items: [
      {
        id: "mcp",
        labelKey: "config.section.mcp",
        descriptionKey: "config.section.mcp.help",
        icon: "terminal",
        href: configSectionPath("mcp"),
      },
      {
        id: "webhooks",
        labelKey: "config.section.webhooks",
        descriptionKey: "config.section.webhooks.help",
        icon: "flash",
        href: configSectionPath("webhooks"),
      },
      {
        id: "activity",
        labelKey: "config.section.activity",
        descriptionKey: "config.section.activity.help",
        icon: "clock",
        href: configSectionPath("activity"),
      },
      {
        id: "usage",
        labelKey: "config.section.usage",
        descriptionKey: "config.section.usage.help",
        icon: "graph-up",
        href: configSectionPath("usage"),
      },
    ],
  },
  {
    id: "data",
    labelKey: "nav.group.data",
    items: [
      {
        id: "sync",
        labelKey: "config.section.sync",
        descriptionKey: "config.section.sync.help",
        icon: "data-transfer-both",
        href: configSectionPath("sync"),
      },
      {
        id: "cloud",
        labelKey: "config.section.cloud",
        descriptionKey: "config.section.cloud.help",
        icon: "cloud",
        href: configSectionPath("cloud"),
      },
      {
        id: "local",
        labelKey: "config.section.local",
        descriptionKey: "config.section.local.help",
        icon: "database",
        href: configSectionPath("local"),
      },
      {
        id: "export",
        labelKey: "config.section.export",
        descriptionKey: "config.section.export.help",
        icon: "page",
        href: configSectionPath("export"),
      },
      {
        id: "p2p",
        labelKey: "config.section.p2p",
        descriptionKey: "config.section.p2p.help",
        icon: "share-android",
        href: configSectionPath("p2p"),
      },
      {
        id: "maintenance",
        labelKey: "config.section.maintenance",
        descriptionKey: "config.section.maintenance.help",
        icon: "trash",
        href: configSectionPath("maintenance"),
      },
    ],
  },
];

export const CONFIG_SECTIONS: ConfigSectionChoice[] =
  CONFIG_SECTION_GROUPS.flatMap((group) => group.items);

export function groupForSection(
  section: ConfigSection,
): ConfigSectionGroup | undefined {
  return CONFIG_SECTION_GROUPS.find((group) =>
    group.items.some((item) => item.id === section),
  );
}
