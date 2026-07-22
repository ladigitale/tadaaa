/** Chemins UI de la section tâches (menu /tache actif en partial). */
export const TACHE_ROOT = "/tache";

export function tacheItemPath(id: string): string {
  return `${TACHE_ROOT}/item/${id}`;
}

export function tacheItemEditPath(id: string): string {
  return `${TACHE_ROOT}/item/${id}/edit`;
}

export function tacheItemMovePath(id: string): string {
  return `${TACHE_ROOT}/item/${id}/move`;
}

export function tacheItemNewPath(id: string): string {
  return `${TACHE_ROOT}/item/${id}/new`;
}

export function tacheNewPath(): string {
  return `${TACHE_ROOT}/new`;
}
