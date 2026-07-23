import type {DbSnapshot, Tag, Todo} from "./types";
import {normalizeSnapshot} from "./store-logic";

/** Identifiant de format (fichier d’export). */
export const TADA_DATA_FORMAT = "tada" as const;

/** Version du schéma d’export / import (incrémenter si breaking). */
export const TADA_DATA_VERSION = 1;

export type TadaDataPackage = {
  format: typeof TADA_DATA_FORMAT;
  version: number;
  /** Identifiant unique stable de la base / du jeu. */
  id: string;
  /** Nom lisible pour identifier le jeu. */
  name: string;
  exportedAt: string;
  todos: Todo[];
  tags: Tag[];
};

export function newBaseId(): string {
  return `base-${crypto.randomUUID()}`;
}

/** Normalise un baseId cloud/local vers la forme `base-<uuid>`. */
export function formatBaseId(raw: string): string {
  const trimmed = raw.trim();
  if (trimmed === "") return trimmed;
  if (trimmed.startsWith("base-")) return trimmed;
  return `base-${trimmed}`;
}

export function createDataPackage(input: {
  id: string;
  name: string;
  snapshot: DbSnapshot;
  exportedAt?: string;
}): TadaDataPackage {
  const snapshot = normalizeSnapshot(input.snapshot);
  return {
    format: TADA_DATA_FORMAT,
    version: TADA_DATA_VERSION,
    id: input.id,
    name: input.name.trim() || "Sans nom",
    exportedAt: input.exportedAt ?? new Date().toISOString(),
    todos: snapshot.todos,
    tags: snapshot.tags,
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readSnapshotFields(value: Record<string, unknown>): DbSnapshot | null {
  if (!Array.isArray(value.todos) || !Array.isArray(value.tags)) return null;
  return normalizeSnapshot({
    todos: value.todos as Todo[],
    tags: value.tags as Tag[],
  });
}

/**
 * Accepte le format versionné, un wrap `{ data }`, ou un snapshot legacy.
 */
export function parseDataPackage(raw: unknown): TadaDataPackage {
  if (!isRecord(raw)) {
    throw new Error("Fichier invalide : JSON objet attendu.");
  }

  const root =
    isRecord(raw.data) &&
    (Array.isArray(raw.data.todos) || typeof raw.data.version === "number")
      ? raw.data
      : raw;

  const snapshot = readSnapshotFields(root);
  if (!snapshot) {
    throw new Error("Fichier invalide : todos/tags manquants.");
  }

  const hasVersion = typeof root.version === "number";
  const version: number = hasVersion ? (root.version as number) : 1;

  if (version > TADA_DATA_VERSION) {
    throw new Error(
      `Version ${version} non supportée (max ${TADA_DATA_VERSION}). Mettez à jour Tadaaa.`,
    );
  }
  if (version < 1) {
    throw new Error(`Version ${version} invalide.`);
  }

  if (
    hasVersion &&
    root.format !== undefined &&
    root.format !== TADA_DATA_FORMAT
  ) {
    throw new Error(`Format « ${String(root.format)} » inconnu (attendu tada).`);
  }

  const id =
    typeof root.id === "string" && root.id.trim()
      ? root.id.trim()
      : newBaseId();
  const name =
    typeof root.name === "string" && root.name.trim()
      ? root.name.trim()
      : "Import";

  return createDataPackage({
    id,
    name,
    snapshot,
    exportedAt:
      typeof root.exportedAt === "string" ? root.exportedAt : undefined,
  });
}

export function packageToSnapshot(pkg: TadaDataPackage): DbSnapshot {
  return normalizeSnapshot({todos: pkg.todos, tags: pkg.tags});
}

export function exportFileName(pkg: TadaDataPackage): string {
  const slug = pkg.name
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 32);
  const shortId = pkg.id.replace(/^base-/, "").slice(0, 8);
  return `tada-${slug || "export"}-${shortId}-v${pkg.version}.json`;
}
