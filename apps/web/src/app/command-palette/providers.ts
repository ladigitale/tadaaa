import type {Tag, Todo} from "../api/types";
import {read} from "../../utils/dataprovider";
import {
  tagsListKey,
  todosArchivedCatalogKey,
  todosCatalogKey,
} from "../dp";
import {tx} from "../i18n";
import {CONFIG_SECTIONS} from "../utils/config-sections";
import {NAV_SECTIONS} from "../utils/nav-sections";
import {navigateTo} from "../utils/navigate";
import {tagsItemEditPath, tagsNewPath} from "../utils/tag-paths";
import {tacheItemPath, tacheNewPath} from "../utils/tache-paths";
import {registerCommandProvider} from "./registry";
import type {CommandItem} from "./types";

let cachedTodos: Todo[] = [];
let cachedTags: Tag[] = [];

function readTodosCatalog(): Todo[] {
  const active = read(todosCatalogKey.path) as Todo[] | null;
  const archived = read(todosArchivedCatalogKey.path) as Todo[] | null;
  const byId = new Map<string, Todo>();
  for (const todo of [
    ...(Array.isArray(active) ? active : []),
    ...(Array.isArray(archived) ? archived : []),
  ]) {
    byId.set(todo.id, todo);
  }
  return [...byId.values()];
}

/** Prefetch dynamic sources once per palette open (keeps typing local). */
export async function warmCommandPaletteCache(): Promise<void> {
  try {
    cachedTodos = readTodosCatalog();
    const tags = read(tagsListKey.path) as Tag[] | null;
    cachedTags = Array.isArray(tags) ? tags : [];
  } catch {
    cachedTodos = [];
    cachedTags = [];
  }
}

function registerBuiltinProviders(): void {
  registerCommandProvider({
    type: "action",
    load: () => {
      const items: CommandItem[] = [
        {
          id: "action-new-task",
          type: "action",
          label: tx("tasks.new"),
          icon: "plus",
          keywords: ["add", "create", "nouvelle", "ajouter", "task", "tache"],
          run: () => navigateTo(tacheNewPath()),
        },
        {
          id: "action-new-tag",
          type: "action",
          label: tx("tags.new"),
          icon: "plus",
          keywords: ["add", "create", "nouvelle", "ajouter", "tag", "etiquette"],
          run: () => navigateTo(tagsNewPath()),
        },
        {
          id: "action-print",
          type: "action",
          label: tx("tasks.print"),
          icon: "printer",
          keywords: [
            "print",
            "imprimer",
            "impression",
            "pdf",
            "liste",
            "calendrier",
            "calendar",
          ],
          run: () => window.print(),
        },
      ];
      return items;
    },
  });

  registerCommandProvider({
    type: "page",
    load: () => {
      const fromNav: CommandItem[] = NAV_SECTIONS.map((page) => ({
        id: `nav-${page.id}`,
        type: "page",
        label: tx(page.labelKey),
        icon: page.icon,
        keywords: [page.href, ...(page.keywords ?? [])],
        run: () => navigateTo(page.href),
      }));

      const fromConfig: CommandItem[] = CONFIG_SECTIONS.map((section) => ({
        id: `page-${section.id}`,
        type: "page",
        label: tx(section.labelKey),
        icon: section.icon,
        keywords: [
          section.href,
          tx(section.descriptionKey),
          section.id,
        ],
        run: () => navigateTo(section.href),
      }));

      return [...fromNav, ...fromConfig];
    },
  });

  registerCommandProvider({
    type: "tag",
    load: () =>
      cachedTags.map(
        (tag): CommandItem => ({
          id: `tag-${tag.id}`,
          type: "tag",
          label: tag.name,
          icon: "label",
          keywords: [tag.id, tag.color],
          showWhenEmpty: false,
          run: () => navigateTo(tagsItemEditPath(tag.id)),
        }),
      ),
  });

  registerCommandProvider({
    type: "task",
    load: () =>
      cachedTodos.map(
        (todo): CommandItem => ({
          id: `task-${todo.id}`,
          type: "task",
          label: todo.text,
          icon: todo.done ? "check-circle" : "circle",
          keywords: [todo.id, todo.description ?? ""].filter(Boolean),
          showWhenEmpty: false,
          run: () => navigateTo(tacheItemPath(todo.id)),
        }),
      ),
  });
}

registerBuiltinProviders();
