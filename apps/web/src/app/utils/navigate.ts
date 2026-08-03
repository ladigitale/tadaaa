/** Navigation pushState compatible avec le router Concorde (LocationHandler). */
export function navigateTo(path: string, replace = false): void {
  if (replace) {
    history.replaceState(null, "", path);
  } else {
    history.pushState(null, "", path);
  }
}
