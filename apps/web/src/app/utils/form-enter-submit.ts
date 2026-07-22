/**
 * Enter dans un champ texte → soumission de formulaire.
 * Ignore textarea, boutons, et raccourcis avec modificateur.
 */
export function isEnterSubmitEvent(event: KeyboardEvent): boolean {
  if (event.key !== "Enter") return false;
  if (event.defaultPrevented) return false;
  if (event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) {
    return false;
  }

  const target = event.composedPath()[0] as HTMLElement | undefined;
  const tag = (target?.tagName ?? "").toLowerCase();
  if (tag === "textarea") return false;
  if (tag === "button" || tag === "a") return false;
  if (tag.startsWith("sonic-button") || tag.startsWith("sonic-menu")) {
    return false;
  }

  return true;
}
