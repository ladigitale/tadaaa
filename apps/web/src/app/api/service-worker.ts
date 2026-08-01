/// <reference lib="webworker" />
import {createMockApiHandler} from "./router";
import {getIdbTodoStore, seedIdbIfEmpty} from "./store-idb";
import {registerSyncHandler} from "../sync/registry";
import {enqueueMutationForDataset} from "../sync/notify";

declare const self: ServiceWorkerGlobalScope;

registerSyncHandler((event) => {
  void getIdbTodoStore()
    .listDatasets()
    .then((datasets) => datasets.find((dataset) => dataset.active)?.baseId)
    .then((baseId) => {
      if (!baseId) return;
      return enqueueMutationForDataset(baseId, event);
    });
});

const handleMockApiRequest = createMockApiHandler(getIdbTodoStore());

self.addEventListener("install", (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      await seedIdbIfEmpty();
      await self.clients.claim();
    })(),
  );
});

self.addEventListener("push", (event) => {
  let title = "Tadaaa";
  let body = "";
  let tag = `tada-${Date.now()}`;
  let url = "/";

  try {
    const data = event.data?.json() as {
      title?: string;
      body?: string;
      tag?: string;
      url?: string;
    } | null;
    if (data) {
      if (typeof data.title === "string" && data.title) title = data.title;
      if (typeof data.body === "string") body = data.body;
      if (typeof data.tag === "string" && data.tag) tag = data.tag;
      if (typeof data.url === "string" && data.url) url = data.url;
    } else {
      body = event.data?.text() ?? "";
    }
  } catch {
    body = event.data?.text() ?? "";
  }

  // Android Chrome often drops empty-body notifications; keep a visible fallback.
  const visibleBody = body.trim() ? body : title;

  event.waitUntil(
    (async () => {
      // Prefer in-app Mercure/local notification when a window is already visible
      // (avoids duplicates). Chrome still requires a visible notification when no
      // focused client exists — otherwise the push can be revoked.
      const clientsList = await self.clients.matchAll({
        type: "window",
        includeUncontrolled: true,
      });
      const hasVisibleClient = clientsList.some(
        (client) => client.visibilityState === "visible",
      );
      if (hasVisibleClient) {
        return;
      }

      // `renotify` is supported by browsers with `tag`, but missing from TS DOM libs.
      await self.registration.showNotification(title, {
        body: visibleBody,
        tag,
        renotify: true,
        icon: "/icons/icon-192.png",
        badge: "/icons/icon-192.png",
        data: {url: sanitizeAppPath(url)},
      } as NotificationOptions);
    })(),
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = sanitizeAppPath(
    typeof event.notification.data?.url === "string"
      ? event.notification.data.url
      : "/",
  );
  event.waitUntil(
    (async () => {
      const clientsList = await self.clients.matchAll({
        type: "window",
        includeUncontrolled: true,
      });
      for (const client of clientsList) {
        if ("focus" in client) {
          await client.focus();
          if ("navigate" in client && targetUrl !== "/") {
            try {
              await (client as WindowClient).navigate(targetUrl);
            } catch {
              // ignore navigate failures
            }
          }
          return;
        }
      }
      await self.clients.openWindow(targetUrl);
    })(),
  );
});

/** Same-origin relative path only — blocks //evil, https://evil, javascript:, etc. */
function sanitizeAppPath(raw: string): string {
  const trimmed = raw.trim();
  if (!trimmed.startsWith("/") || trimmed.startsWith("//")) {
    return "/";
  }
  try {
    const resolved = new URL(trimmed, self.location.origin);
    if (resolved.origin !== self.location.origin) {
      return "/";
    }
    return `${resolved.pathname}${resolved.search}${resolved.hash}`;
  } catch {
    return "/";
  }
}

self.addEventListener("fetch", (event) => {
  const {request} = event;
  const url = new URL(request.url);
  if (!url.pathname.startsWith("/mock-api")) return;

  event.respondWith(
    (async () => {
      const mocked = await handleMockApiRequest(request);
      if (mocked) return mocked;
      return fetch(request);
    })(),
  );
});
