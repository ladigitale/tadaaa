import {installMockApiFetchFallback} from "./mock-api-fetch-fallback";

const SW_URL = "/demo-api-sw.js";

/** Hosts où un SW HTTPS peut fonctionner (localhost, ou contexte sécurisé). */
function canRegisterServiceWorker(): boolean {
  if (!window.isSecureContext) return false;
  return (
    location.protocol === "https:" ||
    location.hostname === "localhost" ||
    location.hostname === "127.0.0.1" ||
    location.hostname === "[::1]"
  );
}

/**
 * /mock-api → IndexedDB via fetch (toujours).
 * SW uniquement sur localhost : les certs auto-signés devops (*.julien.test)
 * font échouer register() avec SecurityError SSL, même après acceptation du site.
 */
export async function registerServiceWorker(): Promise<
  ServiceWorkerRegistration | undefined
> {
  installMockApiFetchFallback();

  if (!("serviceWorker" in navigator)) {
    return undefined;
  }

  if (!canRegisterServiceWorker()) {
    return undefined;
  }

  try {
    const registration = await navigator.serviceWorker.register(SW_URL, {
      scope: "/",
    });

    if (registration.waiting) {
      await registration.update();
    }

    await navigator.serviceWorker.ready;
    return registration;
  } catch (error) {
    console.warn(
      "[tada-api] Service Worker indisponible — /mock-api via IndexedDB",
      error,
    );
    return undefined;
  }
}
