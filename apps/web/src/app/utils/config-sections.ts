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
  labelKey: string;
  items: ConfigSectionChoice[];
};

/** Settings nav & landing: App → Cloud → Local data. */
export const CONFIG_SECTION_GROUPS: ConfigSectionGroup[] = [
  {
    labelKey: "config.group.app",
    items: [
      {
        id: "appearance",
        labelKey: "config.section.appearance",
        descriptionKey: "config.section.appearance.help",
        icon: "palette",
        href: configSectionPath("appearance"),
      },
      {
        id: "issues",
        labelKey: "config.section.issues",
        descriptionKey: "config.section.issues.help",
        icon: "link",
        href: configSectionPath("issues"),
      },
    ],
  },
  {
    labelKey: "config.group.cloud",
    items: [
      {
        id: "account",
        labelKey: "config.section.account",
        descriptionKey: "config.section.account.help",
        icon: "user",
        href: configSectionPath("account"),
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
    labelKey: "config.group.local",
    items: [
      {
        id: "datasets",
        labelKey: "config.section.datasets",
        descriptionKey: "config.section.datasets.help",
        icon: "database",
        href: configSectionPath("datasets"),
      },
      {
        id: "data",
        labelKey: "config.section.data",
        descriptionKey: "config.section.data.help",
        icon: "page",
        href: configSectionPath("data"),
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
