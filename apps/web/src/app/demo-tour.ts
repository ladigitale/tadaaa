/** First-visit demo tour — localStorage only (not synced). */

const STORAGE_KEY = "tada-demo-tour-seen";

export function hasSeenDemoTour(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === "1";
  } catch {
    return true;
  }
}

export function markDemoTourSeen(): void {
  try {
    localStorage.setItem(STORAGE_KEY, "1");
  } catch {
    /* ignore */
  }
}

export function openDemoTour(): void {
  const el = document.querySelector("demo-tour-modal") as
    | {open: () => void}
    | null;
  el?.open();
}
