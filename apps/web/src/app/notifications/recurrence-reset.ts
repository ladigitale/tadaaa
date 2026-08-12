import {dp, read, set} from "../../utils/dataprovider";
import {endpoints} from "../api/endpoints";
import {getIdbTodoStore} from "../api/store-idb";
import {todosFilterKey, type TodosFilter} from "../dp";

const INTERVAL_MS = 60 * 1000;

let started = false;
let intervalId: ReturnType<typeof setInterval> | null = null;

function bumpTodosUi(): void {
  const filter = read(todosFilterKey.path) as TodosFilter;
  set(todosFilterKey.path, {...filter, _rev: (filter._rev ?? 0) + 1});
  dp(endpoints.keys.refresh.todosCatalog).invalidate();
}

export async function checkRecurrenceResets(): Promise<void> {
  try {
    const resetCount = await getIdbTodoStore().applyRecurrenceResets();
    if (resetCount > 0) {
      bumpTodosUi();
    }
  } catch (error) {
    console.warn("[recurrence] reset check failed", error);
  }
}

export function startRecurrenceResetWatcher(): void {
  if (started) return;
  started = true;
  void checkRecurrenceResets();
  setTimeout(() => void checkRecurrenceResets(), 2500);
  intervalId = setInterval(() => void checkRecurrenceResets(), INTERVAL_MS);

  const onForeground = () => {
    if (document.visibilityState === "visible") {
      void checkRecurrenceResets();
    }
  };
  document.addEventListener("visibilitychange", onForeground);
  window.addEventListener("focus", onForeground);
}

export function stopRecurrenceResetWatcher(): void {
  if (intervalId !== null) {
    clearInterval(intervalId);
    intervalId = null;
  }
  started = false;
}
