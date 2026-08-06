import {dp, read, set} from "../utils/dataprovider";
import {initApiConfiguration} from "./api/config";
import {endpoints} from "./api/endpoints";
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
  commandPaletteKey,
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
import {areWebNotificationsEnabled} from "./settings";
import {subscribeServerPush} from "./notifications/push-subscribe";
import {shortcuts} from "./shortcuts";

export function initApp(): void {
  initAppLocale();
  initTheme();
  initPwaInstallListeners();
  shortcuts.install();
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
    startTime: "",
    endAt: "",
    endTime: "",
    recurrence: "none",
  });
  set(todoEditKey.path, {
    text: "",
    description: "",
    priority: "medium",
    tagIds: [],
    startAt: "",
    startTime: "",
    endAt: "",
    endTime: "",
    recurrence: "none",
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
  set(commandPaletteKey.path, {q: ""});
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
  const submit = endpoints.keys.submit;
  set(submit.todoCreate.path, null);
  set(submit.todoEdit.path, null);
  set(submit.tagCreate.path, null);
  set(submit.tagEdit.path, null);
  set(submit.todoMove.path, null);
  set(submit.calendarTodoPatch.path, null);
  set(submit.calendarTodoCopy.path, null);
  set(submit.bulkUpdate.path, null);
  set(submit.purgeArchived.path, null);
  set(submit.datasetCreate.path, null);
  set(submit.datasetActivate.path, null);
  set(submit.dataImport.path, null);
  void registerServiceWorker().then(() => {
    if (areWebNotificationsEnabled() && isAccountConnected()) {
      void subscribeServerPush();
    }
  });

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
      if (areWebNotificationsEnabled()) {
        void subscribeServerPush();
      }
    }
  });

  // Retour sur l’onglet / app → pull cloud si stale (MCP / autre device).
  const onForeground = () => {
    if (document.visibilityState === "visible" && isAccountConnected()) {
      scheduleAutoSync();
      void ensureMercureSubscription();
      if (areWebNotificationsEnabled()) {
        void subscribeServerPush();
      }
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
  bumpTodosCatalog();
}

/** Relance le `@get` de `<todos-catalog-loader>` → catalogues DP. */
export function bumpTodosCatalog(): void {
  dp(endpoints.keys.refresh.todosCatalog).invalidate();
}

/** Relance le `@get` de `<tags-list-loader>` → `tagsListKey`. */
export function bumpTagsList(): void {
  dp(endpoints.keys.refresh.tagsList).invalidate();
}
