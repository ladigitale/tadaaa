import type {DbSnapshot} from "./types";
import type {AppLocale} from "../i18n/locale";

/** Sample playground list — tasks that hint at key features. */
export function getSeedData(locale: AppLocale = "en"): DbSnapshot {
  if (locale === "fr") {
    return {
      tags: [
        {id: "tag-demo", name: "démo", color: "info"},
        {id: "tag-essayer", name: "à essayer", color: "success"},
      ],
      todos: [
        {
          id: "todo-1",
          text: "Bienvenue — cochez cette tâche",
          description:
            "Ceci est une **démo libre**. Créez, modifiez, archivez : tout reste dans ce navigateur jusqu’à ce que vous vous connectiez.",
          done: false,
          archived: false,
          priority: "high",
          tagIds: ["tag-demo"],
          parentId: null,
          createdAt: "2026-01-10T09:00:00.000Z",
        },
        {
          id: "todo-2",
          text: "Ouvrez les sous-tâches ci-dessous",
          description: "Les tâches peuvent être imbriquées — cliquez pour descendre dans l’arbre.",
          done: false,
          archived: false,
          priority: "medium",
          tagIds: ["tag-essayer"],
          parentId: null,
          createdAt: "2026-01-11T10:00:00.000Z",
        },
        {
          id: "todo-sub-1",
          text: "Ajoutez une étiquette",
          done: true,
          archived: false,
          priority: "medium",
          tagIds: ["tag-demo"],
          parentId: "todo-2",
          createdAt: "2026-01-11T10:15:00.000Z",
        },
        {
          id: "todo-sub-2",
          text: "Essayez la recherche (Ctrl+K)",
          done: false,
          archived: false,
          priority: "medium",
          tagIds: [],
          parentId: "todo-2",
          createdAt: "2026-01-11T10:30:00.000Z",
        },
        {
          id: "todo-3",
          text: "Lien ticket — voir RM-12345",
          description:
            "Les jetons du type `RM-12345` deviennent des liens (Config → Détecteurs).",
          done: false,
          archived: false,
          priority: "low",
          tagIds: ["tag-essayer"],
          parentId: null,
          createdAt: "2026-01-12T11:00:00.000Z",
        },
        {
          id: "todo-4",
          text: "Ancienne tâche d’exemple (archivée)",
          done: true,
          archived: true,
          priority: "low",
          tagIds: [],
          parentId: null,
          createdAt: "2025-12-01T08:00:00.000Z",
        },
      ],
    };
  }

  return {
    tags: [
      {id: "tag-demo", name: "demo", color: "info"},
      {id: "tag-try", name: "try-me", color: "success"},
    ],
    todos: [
      {
        id: "todo-1",
        text: "Welcome — check this task off",
        description:
          "This is a **free playground**. Create, edit, archive — data stays in this browser until you sign in.",
        done: false,
        archived: false,
        priority: "high",
        tagIds: ["tag-demo"],
        parentId: null,
        createdAt: "2026-01-10T09:00:00.000Z",
      },
      {
        id: "todo-2",
        text: "Open the subtasks below",
        description: "Tasks can nest — click through to explore the tree.",
        done: false,
        archived: false,
        priority: "medium",
        tagIds: ["tag-try"],
        parentId: null,
        createdAt: "2026-01-11T10:00:00.000Z",
      },
      {
        id: "todo-sub-1",
        text: "Add a tag",
        done: true,
        archived: false,
        priority: "medium",
        tagIds: ["tag-demo"],
        parentId: "todo-2",
        createdAt: "2026-01-11T10:15:00.000Z",
      },
      {
        id: "todo-sub-2",
        text: "Try search (Ctrl+K)",
        done: false,
        archived: false,
        priority: "medium",
        tagIds: [],
        parentId: "todo-2",
        createdAt: "2026-01-11T10:30:00.000Z",
      },
      {
        id: "todo-3",
        text: "Ticket link — see RM-12345",
        description:
          "Tokens like `RM-12345` become links (Settings → Link detectors).",
        done: false,
        archived: false,
        priority: "low",
        tagIds: ["tag-try"],
        parentId: null,
        createdAt: "2026-01-12T11:00:00.000Z",
      },
      {
        id: "todo-4",
        text: "Old sample task (archived)",
        done: true,
        archived: true,
        priority: "low",
        tagIds: [],
        parentId: null,
        createdAt: "2025-12-01T08:00:00.000Z",
      },
    ],
  };
}

/** English snapshot — used by Node file store and as default. */
export const SEED_DATA: DbSnapshot = getSeedData("en");

export function getDemoDatasetName(locale: AppLocale = "en"): string {
  return locale === "fr" ? "Démo" : "Demo";
}
