import {read, set} from "../utils/dataprovider";
import {initApiConfiguration} from "./api/config";
import {installMockApiFetchFallback} from "./api/mock-api-fetch-fallback";
import {registerServiceWorker} from "./api/register";
import {
  tagCreateKey,
  tagEditKey,
  tagsFilterKey,
  todoCreateKey,
  todoEditKey,
  todoMoveKey,
  todoSearchKey,
  appConfigKey,
  tagsListKey,
  todosFilterKey,
  type TodosFilter,
} from "./dp";
import {loadAccountSettings, isAccountConnected} from "./account-settings";
import {initTheme} from "./theme";
import {initAppLocale} from "./i18n";
import {registerSyncHandler} from "./sync/registry";
import {enqueueMutationForDataset} from "./sync/notify";
import {scheduleAutoSync} from "./sync/engine";
import {
  ensureMercureSubscription,
  resetMercureSubscription,
} from "./sync/mercure";
import {getIdbTodoStore} from "./api/store-idb";
import {initPwaInstallListeners} from "./pwa-install";
import {startDueDateWatcher} from "./notifications/due-dates";

export function initApp(): void {
  initAppLocale();
  initTheme();
  initPwaInstallListeners();
  // Avant tout fetch : sous Apache, /mock-api sans SW = index.html (JSON parse fail).
  installMockApiFetchFallback();
  initApiConfiguration();
  set(todosFilterKey.path, {
    q: "",
    status: "all",
    tags: [],
    sort: "createdAt:desc",
    sortBy: "createdAt",
    sortDir: "desc",
    parentId: "",
    recursive: false,
    _rev: 0,
  });
  set(todoCreateKey.path, {
    text: "",
    description: "",
    priority: "medium",
    tagIds: [],
    startAt: "",
    endAt: "",
  });
  set(todoEditKey.path, {
    text: "",
    description: "",
    priority: "medium",
    tagIds: [],
    startAt: "",
    endAt: "",
  });
  set(tagCreateKey.path, {name: "", color: "default"});
  set(tagEditKey.path, {name: "", color: "default"});
  set(tagsFilterKey.path, {q: ""});
  set(todoMoveKey.path, {q: ""});
  set(todoSearchKey.path, {
    q: "",
    status: "all",
    tags: [],
    sort: "createdAt:desc",
    sortBy: "createdAt",
    sortDir: "desc",
  });
  const account = loadAccountSettings();
  set(appConfigKey.path, {
    newDatasetName: "",
    p2pReceiveCode: "",
    accountEmail: account.user?.email ?? "",
    accountPassword: "",
    accountApiBaseUrl: account.apiBaseUrl,
    newCloudDatasetName: "",
    newAccessTokenName: "",
    shareInviteEmail: "",
  });
  set(tagsListKey.path, []);
  void registerServiceWorker();

  registerSyncHandler((event) => {
    void getIdbTodoStore()
      .listDatasets()
      .then((datasets) => datasets.find((dataset) => dataset.active)?.baseId)
      .then((baseId) => {
        if (!baseId) return;
        return enqueueMutationForDataset(baseId, event).then(() =>
          scheduleAutoSync(),
        );
      });
  });

  window.addEventListener("online", () => {
    if (isAccountConnected()) {
      scheduleAutoSync();
      void ensureMercureSubscription();
    }
  });

  // Retour sur l’onglet / app → pull cloud si stale (MCP / autre device).
  const onForeground = () => {
    if (document.visibilityState === "visible" && isAccountConnected()) {
      scheduleAutoSync();
      void ensureMercureSubscription();
    }
  };
  document.addEventListener("visibilitychange", onForeground);
  window.addEventListener("focus", onForeground);

  if (isAccountConnected()) {
    resetMercureSubscription();
  }

  startDueDateWatcher();
}

export function bumpTodosRev(): void {
  const filter = read(todosFilterKey.path) as TodosFilter;
  set(todosFilterKey.path, {...filter, _rev: (filter._rev ?? 0) + 1});
}
