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

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl =
    typeof event.notification.data?.url === "string"
      ? event.notification.data.url
      : "/";
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
