import {tf, tx} from "../i18n";
import {
  areWebNotificationsEnabled,
  loadAppSettings,
  saveAppSettings,
} from "../settings";

export type TodoNotifyChange = {
  kind: "checked" | "unchecked" | "deleted";
  id: string;
  text: string;
};

const TITLE = "Tadaaa";
const BATCH_WINDOW_MS = 1_200;

let batchTimer: ReturnType<typeof setTimeout> | null = null;
let pendingTodoChanges: TodoNotifyChange[] = [];

export function notificationPermission(): NotificationPermission | "unsupported" {
  if (typeof Notification === "undefined") return "unsupported";
  return Notification.permission;
}

export async function requestNotificationPermission(): Promise<NotificationPermission | "unsupported"> {
  if (typeof Notification === "undefined") return "unsupported";
  if (Notification.permission === "granted") return "granted";
  if (Notification.permission === "denied") return "denied";
  try {
    return await Notification.requestPermission();
  } catch {
    return Notification.permission;
  }
}

export async function enableWebNotifications(): Promise<boolean> {
  const permission = await requestNotificationPermission();
  if (permission !== "granted") {
    saveAppSettings({...loadAppSettings(), webNotifications: false});
    return false;
  }
  saveAppSettings({...loadAppSettings(), webNotifications: true});
  return true;
}

export function disableWebNotifications(): void {
  saveAppSettings({...loadAppSettings(), webNotifications: false});
}

function canShow(): boolean {
  if (!areWebNotificationsEnabled()) return false;
  if (typeof Notification === "undefined") return false;
  return Notification.permission === "granted";
}

function show(body: string, tag?: string): void {
  if (!canShow()) return;
  try {
    const notification = new Notification(TITLE, {
      body,
      tag: tag ?? `tada-${Date.now()}`,
    });
    notification.onclick = () => {
      window.focus();
      notification.close();
    };
  } catch {
    // Permission revoked mid-session or insecure context.
  }
}

function truncate(text: string, max = 60): string {
  const trimmed = text.trim() || tx("notif.untitled_task");
  if (trimmed.length <= max) return trimmed;
  return `${trimmed.slice(0, max - 1)}…`;
}

function formatTodoBatch(changes: TodoNotifyChange[]): string {
  const checked = changes.filter((c) => c.kind === "checked");
  const unchecked = changes.filter((c) => c.kind === "unchecked");
  const deleted = changes.filter((c) => c.kind === "deleted");

  const parts: string[] = [];

  if (checked.length === 1) {
    parts.push(tf("notif.todo_checked_one", {text: truncate(checked[0].text)}));
  } else if (checked.length > 1) {
    parts.push(tf("notif.todo_checked_many", {n: checked.length}));
  }

  if (unchecked.length === 1) {
    parts.push(tf("notif.todo_unchecked_one", {text: truncate(unchecked[0].text)}));
  } else if (unchecked.length > 1) {
    parts.push(tf("notif.todo_unchecked_many", {n: unchecked.length}));
  }

  if (deleted.length === 1) {
    parts.push(tf("notif.todo_deleted_one", {text: truncate(deleted[0].text)}));
  } else if (deleted.length > 1) {
    parts.push(tf("notif.todo_deleted_many", {n: deleted.length}));
  }

  return parts.join(tx("notif.batch_sep"));
}

function flushTodoBatch(): void {
  batchTimer = null;
  if (pendingTodoChanges.length === 0) return;

  // Dedupe by id keeping the latest kind.
  const byId = new Map<string, TodoNotifyChange>();
  for (const change of pendingTodoChanges) {
    byId.set(change.id, change);
  }
  pendingTodoChanges = [];
  const unique = [...byId.values()];
  if (unique.length === 0) return;

  const body = formatTodoBatch(unique);
  if (!body) return;
  show(body, "tada-todos");
}

/**
 * Queue remote todo check / uncheck / delete notifications.
 * Multiple changes within BATCH_WINDOW_MS become one message.
 */
export function notifyTodoChanges(changes: TodoNotifyChange[]): void {
  if (changes.length === 0 || !canShow()) return;
  pendingTodoChanges.push(...changes);
  if (batchTimer !== null) clearTimeout(batchTimer);
  batchTimer = setTimeout(flushTodoBatch, BATCH_WINDOW_MS);
}

export function notifyMemberJoined(info: {
  datasetName: string;
  memberEmail: string;
  role?: string;
}): void {
  if (!canShow()) return;
  const roleKey =
    info.role === "writer"
      ? "invite.role_writer"
      : info.role === "reader"
        ? "invite.role_reader"
        : null;
  const roleLabel = roleKey ? tx(roleKey) : "";
  const body = roleLabel
    ? tf("notif.member_joined_role", {
        email: info.memberEmail,
        name: info.datasetName,
        role: roleLabel,
      })
    : tf("notif.member_joined", {
        email: info.memberEmail,
        name: info.datasetName,
      });
  show(body, `tada-join-${info.datasetName}`);
}

export function notifyDatasetInvite(info: {
  datasetName: string;
  inviterEmail: string;
  role?: string;
  urlPath: string;
}): void {
  if (!canShow()) return;
  const roleKey =
    info.role === "writer"
      ? "invite.role_writer"
      : info.role === "reader"
        ? "invite.role_reader"
        : null;
  const roleLabel = roleKey ? tx(roleKey) : "";
  const body = roleLabel
    ? tf("notif.dataset_invite_role", {
        email: info.inviterEmail,
        name: info.datasetName,
        role: roleLabel,
      })
    : tf("notif.dataset_invite", {
        email: info.inviterEmail,
        name: info.datasetName,
      });
  try {
    const notification = new Notification(TITLE, {
      body,
      tag: `tada-invite-${info.urlPath}`,
    });
    notification.onclick = () => {
      window.focus();
      notification.close();
      const path = info.urlPath.startsWith("/")
        ? info.urlPath
        : `/${info.urlPath}`;
      window.history.pushState({}, "", path);
      window.dispatchEvent(new PopStateEvent("popstate"));
    };
  } catch {
    // Permission revoked mid-session or insecure context.
  }
}
