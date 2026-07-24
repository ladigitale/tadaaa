/** Progressive Web App install prompt (beforeinstallprompt). */

export type PwaInstallState = {
  /** Native install prompt is available (Chrome/Edge…). */
  canPrompt: boolean;
  /** Already running as installed app. */
  installed: boolean;
};

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{outcome: "accepted" | "dismissed"}>;
};

let deferred: BeforeInstallPromptEvent | null = null;
const listeners = new Set<() => void>();

function emit(): void {
  for (const listener of listeners) listener();
}

export function isPwaInstalled(): boolean {
  if (typeof window === "undefined") return false;
  const standaloneMq = window.matchMedia("(display-mode: standalone)");
  if (standaloneMq.matches) return true;
  // iOS Safari
  const nav = window.navigator as Navigator & {standalone?: boolean};
  return nav.standalone === true;
}

export function getPwaInstallState(): PwaInstallState {
  return {
    canPrompt: deferred !== null,
    installed: isPwaInstalled(),
  };
}

export function subscribePwaInstall(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function initPwaInstallListeners(): void {
  if (typeof window === "undefined") return;

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    deferred = event as BeforeInstallPromptEvent;
    emit();
  });

  window.addEventListener("appinstalled", () => {
    deferred = null;
    emit();
  });

  window.matchMedia("(display-mode: standalone)").addEventListener("change", () => {
    emit();
  });
}

/** Triggers the browser install UI. Returns false if unavailable. */
export async function promptPwaInstall(): Promise<"accepted" | "dismissed" | "unavailable"> {
  if (!deferred) return "unavailable";
  const event = deferred;
  deferred = null;
  emit();
  try {
    await event.prompt();
    const {outcome} = await event.userChoice;
    return outcome;
  } catch {
    return "unavailable";
  }
}
