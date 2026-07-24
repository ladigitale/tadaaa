import {fetchTodos} from "../api/client";
import type {Todo} from "../api/types";
import {tf, tx} from "../i18n";
import {addDays, parseDateOnly, todayDateOnly} from "../utils/dates";
import {tacheItemPath} from "../utils/tache-paths";
import {areWebNotificationsEnabled} from "../settings";
import {SonicToast} from "@supersoniks/concorde/toast";
import "@supersoniks/concorde/toast";

export type DueKind = "overdue" | "today" | "tomorrow";

type DueHit = {
  todo: Todo;
  kind: DueKind;
  endAt: string;
};

const STORAGE_PREFIX = "tada-due-notified:";
const INTERVAL_MS = 30 * 60 * 1000;

let started = false;
let intervalId: ReturnType<typeof setInterval> | null = null;

function notifiedKey(todoId: string, endAt: string, kind: DueKind): string {
  return `${STORAGE_PREFIX}${todoId}:${endAt}:${kind}`;
}

function wasNotified(todoId: string, endAt: string, kind: DueKind): boolean {
  try {
    return localStorage.getItem(notifiedKey(todoId, endAt, kind)) === "1";
  } catch {
    return false;
  }
}

function markNotified(todoId: string, endAt: string, kind: DueKind): void {
  try {
    localStorage.setItem(notifiedKey(todoId, endAt, kind), "1");
  } catch {
    /* ignore */
  }
}

function collectDueHits(todos: Todo[], today: string): DueHit[] {
  const tomorrow = addDays(today, 1);
  const hits: DueHit[] = [];

  for (const todo of todos) {
    if (todo.archived || todo.done) continue;
    const endAt = parseDateOnly(todo.endAt ?? null);
    if (!endAt) continue;

    let kind: DueKind | null = null;
    if (endAt < today) kind = "overdue";
    else if (endAt === today) kind = "today";
    else if (endAt === tomorrow) kind = "tomorrow";
    if (!kind) continue;
    if (wasNotified(todo.id, endAt, kind)) continue;

    hits.push({todo, kind, endAt});
  }

  return hits;
}

function truncate(text: string, max = 60): string {
  const trimmed = text.trim() || tx("notif.untitled_task");
  if (trimmed.length <= max) return trimmed;
  return `${trimmed.slice(0, max - 1)}…`;
}

function formatDueBatch(hits: DueHit[]): string {
  const overdue = hits.filter((h) => h.kind === "overdue");
  const today = hits.filter((h) => h.kind === "today");
  const tomorrow = hits.filter((h) => h.kind === "tomorrow");
  const parts: string[] = [];

  if (overdue.length === 1) {
    parts.push(tf("notif.overdue_one", {text: truncate(overdue[0].todo.text)}));
  } else if (overdue.length > 1) {
    parts.push(tf("notif.overdue_many", {n: overdue.length}));
  }

  if (today.length === 1) {
    parts.push(tf("notif.due_today_one", {text: truncate(today[0].todo.text)}));
  } else if (today.length > 1) {
    parts.push(tf("notif.due_today_many", {n: today.length}));
  }

  if (tomorrow.length === 1) {
    parts.push(
      tf("notif.due_tomorrow_one", {text: truncate(tomorrow[0].todo.text)}),
    );
  } else if (tomorrow.length > 1) {
    parts.push(tf("notif.due_tomorrow_many", {n: tomorrow.length}));
  }

  return parts.join(tx("notif.due_batch_sep"));
}

function canShowBrowserNotification(): boolean {
  if (!areWebNotificationsEnabled()) return false;
  if (typeof Notification === "undefined") return false;
  return Notification.permission === "granted";
}

function showBrowserNotification(body: string, href: string): void {
  if (!canShowBrowserNotification()) return;
  try {
    const notification = new Notification("Tadaaa", {
      body,
      tag: "tada-due-dates",
    });
    notification.onclick = () => {
      window.focus();
      notification.close();
      history.pushState(null, "", href);
      window.dispatchEvent(new PopStateEvent("popstate"));
    };
  } catch {
    /* ignore */
  }
}

function showToast(body: string, href: string): void {
  try {
    const safeHref = href.startsWith("/") ? href : `/${href}`;
    SonicToast.add({
      id: `due-${Date.now()}`,
      title: tx("notif.due_title"),
      text: `${body.replace(/</g, "&lt;")} — <a href="${safeHref}">${tx("calendar.today")}</a>`,
      status: "warning",
      preserve: true,
    });
  } catch (error) {
    console.warn("[due-dates] toast failed", error);
  }
}

export async function checkDueDates(): Promise<void> {
  try {
    const list = await fetchTodos({
      status: "all",
      recursive: true,
      parentId: "",
      limit: 5000,
    });
    const today = todayDateOnly();
    const hits = collectDueHits(list.data, today);
    if (hits.length === 0) return;

    const body = formatDueBatch(hits);
    if (!body) return;

    const primary = hits[0];
    const href = tacheItemPath(primary.todo.id);

    showToast(body, href);
    showBrowserNotification(body, href);

    for (const hit of hits) {
      markNotified(hit.todo.id, hit.endAt, hit.kind);
    }
  } catch (error) {
    console.warn("[due-dates] check failed", error);
  }
}

export function startDueDateWatcher(): void {
  if (started) return;
  started = true;
  void checkDueDates();
  intervalId = setInterval(() => void checkDueDates(), INTERVAL_MS);

  const onForeground = () => {
    if (document.visibilityState === "visible") {
      void checkDueDates();
    }
  };
  document.addEventListener("visibilitychange", onForeground);
  window.addEventListener("focus", onForeground);
}

export function stopDueDateWatcher(): void {
  if (intervalId !== null) {
    clearInterval(intervalId);
    intervalId = null;
  }
  started = false;
}
