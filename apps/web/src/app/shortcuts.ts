import type {CommandPaletteModal} from "./components/command-palette-modal";
import type {TodoSearchModal} from "./components/todo-search-modal";
import {getAppLocale} from "./i18n";
import {navigateTo} from "./utils/navigate";
import {TAGS_ROOT, tagsNewPath} from "./utils/tag-paths";
import {
  TACHE_ROOT,
  tacheItemNewPath,
  tacheNewPath,
} from "./utils/tache-paths";

/** Identifiants stables — source de vérité pour tooltips / binding. */
export type ShortcutId =
  | "commandPalette"
  | "searchTasks"
  | "newItem"
  | "submitForm";

type Chord = {
  key: string;
  /** Ctrl (Windows/Linux) ou ⌘ (macOS) — on affiche toujours « Ctrl » comme le reste de l’UI. */
  mod?: boolean;
  shift?: boolean;
};

type ShortcutDef = {
  id: ShortcutId;
  chords: Chord[];
  /** false = indicatif seulement (ex. Entrée pour soumettre un formulaire). */
  bind?: boolean;
  when?: () => boolean;
  run?: () => void;
};

const DEFS: ShortcutDef[] = [
  {
    id: "commandPalette",
    chords: [{key: "p", mod: true, shift: true}],
    run: () => {
      const modal = document.querySelector("command-palette-modal") as
        | CommandPaletteModal
        | null;
      void modal?.open();
    },
  },
  {
    id: "searchTasks",
    chords: [{key: "k", mod: true}],
    run: () => {
      const modal = document.querySelector("todo-search-modal") as
        | TodoSearchModal
        | null;
      void modal?.open();
    },
  },
  {
    id: "newItem",
    chords: [{key: "Enter"}],
    when: () => resolveNewPath() != null,
    run: () => {
      const path = resolveNewPath();
      if (path) navigateTo(path);
    },
  },
  {
    id: "submitForm",
    chords: [{key: "Enter"}],
    bind: false,
  },
];

const byId = new Map(DEFS.map((def) => [def.id, def]));

function normalizePath(pathname: string): string {
  const trimmed = pathname.replace(/\/+$/, "");
  return trimmed || "/";
}

/** Liste tâches / étiquettes uniquement (pas les formulaires new/edit/move). */
function resolveNewPath(): string | null {
  const path = normalizePath(location.pathname);

  if (path === TAGS_ROOT) return tagsNewPath();
  if (path === TACHE_ROOT) return tacheNewPath();

  const itemMatch = path.match(/^\/tache\/item\/([^/]+)$/);
  if (itemMatch?.[1]) return tacheItemNewPath(itemMatch[1]);

  return null;
}

function isEditableTarget(event: KeyboardEvent): boolean {
  const el = event.composedPath()[0] as HTMLElement | undefined;
  if (!el) return false;
  const tag = (el.tagName ?? "").toLowerCase();
  if (tag === "input" || tag === "textarea" || tag === "select") return true;
  if (tag.startsWith("sonic-input") || tag.startsWith("sonic-textarea")) {
    return true;
  }
  if ((el as HTMLElement).isContentEditable) return true;
  return false;
}

function isBlockingOverlay(): boolean {
  return Boolean(document.querySelector("dialog[open]"));
}

function chordMatches(event: KeyboardEvent, chord: Chord): boolean {
  const key = event.key.length === 1 ? event.key.toLowerCase() : event.key;
  const want = chord.key.length === 1 ? chord.key.toLowerCase() : chord.key;
  if (key !== want) return false;

  const mod = event.ctrlKey || event.metaKey;
  if (Boolean(chord.mod) !== mod) return false;
  if (Boolean(chord.shift) !== event.shiftKey) return false;
  if (event.altKey) return false;
  return true;
}

function formatChord(chord: Chord): string {
  const parts: string[] = [];
  if (chord.mod) parts.push("Ctrl");
  if (chord.shift) parts.push("Shift");
  const key = chord.key;
  if (key === "Enter") {
    parts.push(getAppLocale() === "fr" ? "Entrée" : "Enter");
  } else {
    parts.push(key.length === 1 ? key.toUpperCase() : key);
  }
  return parts.join("+");
}

function defOf(id: ShortcutId): ShortcutDef {
  const def = byId.get(id);
  if (!def) throw new Error(`Unknown shortcut: ${id}`);
  return def;
}

/** Tous les accords (tooltip) : « Ctrl+Shift+P » / « Entrée ». */
function formatAll(id: ShortcutId): string {
  return defOf(id).chords.map(formatChord).join(" · ");
}

/** « Label (Entrée) » pour tooltips / aria. */
function withHint(label: string, id: ShortcutId): string {
  const keys = formatAll(id);
  return keys ? `${label} (${keys})` : label;
}

let installed = false;

function onKeyDown(event: KeyboardEvent) {
  if (event.defaultPrevented) return;

  for (const def of DEFS) {
    if (def.bind === false || !def.run) continue;
    for (const chord of def.chords) {
      if (!chordMatches(event, chord)) continue;

      // Touche nue (Entrée) : jamais dans un champ ; modificateurs OK.
      if (!chord.mod && isEditableTarget(event)) continue;
      if (def.id === "newItem" && isBlockingOverlay()) continue;
      if (def.when && !def.when()) continue;

      event.preventDefault();
      def.run();
      return;
    }
  }
}

function install() {
  if (installed || typeof window === "undefined") return;
  installed = true;
  window.addEventListener("keydown", onKeyDown);
}

export const shortcuts = {
  formatAll,
  withHint,
  install,
} as const;
