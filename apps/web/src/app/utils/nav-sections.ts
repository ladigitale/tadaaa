import {TAGS_ROOT} from "./tag-paths";
import {TACHE_ROOT} from "./tache-paths";

export type NavSectionChoice = {
  id: string;
  labelKey: string;
  icon: string;
  href: string;
  keywords?: string[];
};

/** Primary app destinations (menu + command palette pages). */
export const NAV_SECTIONS: NavSectionChoice[] = [
  {
    id: "tasks",
    labelKey: "nav.tasks",
    icon: "list",
    href: TACHE_ROOT,
    keywords: ["tasks", "taches", "todo", "/tache"],
  },
  {
    id: "tags",
    labelKey: "nav.tags",
    icon: "label",
    href: TAGS_ROOT,
    keywords: ["tags", "etiquettes", "labels", "/tags"],
  },
];
