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
