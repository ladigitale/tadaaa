/** Chemins UI étiquettes. */
export const TAGS_ROOT = "/tags";

export function tagsNewPath(): string {
  return `${TAGS_ROOT}/new`;
}

export function tagsItemEditPath(id: string): string {
  return `${TAGS_ROOT}/item/${encodeURIComponent(id)}/edit`;
}
