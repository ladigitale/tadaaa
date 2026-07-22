import {createMockApiHandler, MOCK_API_PATH_PREFIX} from "./router";
import {getIdbTodoStore, seedIdbIfEmpty} from "./store-idb";

let installed = false;
let ready: Promise<void> | null = null;

function isMockApiRequest(input: RequestInfo | URL): boolean {
  const url =
    typeof input === "string"
      ? input
      : input instanceof URL
        ? input.href
        : input.url;
  try {
    const parsed = new URL(url, location.origin);
    return parsed.pathname.startsWith(MOCK_API_PATH_PREFIX);
  } catch {
    return false;
  }
}

async function ensureReady(): Promise<
  ReturnType<typeof createMockApiHandler>
> {
  if (!ready) {
    ready = seedIdbIfEmpty();
  }
  await ready;
  return createMockApiHandler(getIdbTodoStore());
}

/**
 * Intercepte /mock-api dans la page (IndexedDB).
 * Indispensable sous Apache : sans SW actif, httpd renvoie index.html.
 */
export function installMockApiFetchFallback(): boolean {
  if (installed || typeof window === "undefined") return installed;
  installed = true;

  const nativeFetch = window.fetch.bind(window);

  window.fetch = async (input, init) => {
    if (!isMockApiRequest(input)) {
      return nativeFetch(input, init);
    }

    const handle = await ensureReady();
    const request = new Request(input, init);
    const response = await handle(request);
    if (response) return response;
    return nativeFetch(input, init);
  };

  return true;
}

export function isMockApiFetchFallbackInstalled(): boolean {
  return installed;
}
