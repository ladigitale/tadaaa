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
import {loadAppSettings} from "./settings";
import {loadAccountSettings, isAccountConnected} from "./account-settings";
import {registerSyncHandler} from "./sync/registry";
import {enqueueMutationForDataset} from "./sync/notify";
import {scheduleAutoSync} from "./sync/engine";
import {getIdbTodoStore} from "./api/store-idb";

export function initApp(): void {
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
  });
  set(todoEditKey.path, {
    text: "",
    description: "",
    priority: "medium",
    tagIds: [],
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
  const settings = loadAppSettings();
  const account = loadAccountSettings();
  set(appConfigKey.path, {
    issueUrlTemplate: settings.issueUrlTemplate,
    issuePattern: settings.issuePattern,
    newDatasetName: "",
    p2pReceiveCode: "",
    accountEmail: account.user?.email ?? "",
    accountPassword: "",
    accountApiBaseUrl: account.apiBaseUrl,
    newCloudDatasetName: "",
    newAccessTokenName: "",
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
    if (isAccountConnected()) scheduleAutoSync();
  });

  // Retour sur l’onglet / app → pull cloud si stale (MCP / autre device).
  const onForeground = () => {
    if (document.visibilityState === "visible" && isAccountConnected()) {
      scheduleAutoSync();
    }
  };
  document.addEventListener("visibilitychange", onForeground);
  window.addEventListener("focus", onForeground);
}

export function bumpTodosRev(): void {
  const filter = read(todosFilterKey.path) as TodosFilter;
  set(todosFilterKey.path, {...filter, _rev: (filter._rev ?? 0) + 1});
}
