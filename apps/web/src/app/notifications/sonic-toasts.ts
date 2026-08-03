import "@supersoniks/concorde/toast";
import {SonicToast} from "@supersoniks/concorde/toast";
import {tf, tx} from "../i18n";

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

/** In-app invite toast (does not depend on browser Notification permission). */
export function toastDatasetInvite(info: {
  datasetName: string;
  inviterEmail: string;
  role?: string;
  urlPath: string;
}): void {
  const roleKey =
    info.role === "writer"
      ? "invite.role_writer"
      : info.role === "reader"
        ? "invite.role_reader"
        : null;
  const roleLabel = roleKey ? tx(roleKey) : "";
  const summary = roleLabel
    ? tf("notif.dataset_invite_role", {
        email: info.inviterEmail,
        name: info.datasetName,
        role: roleLabel,
      })
    : tf("notif.dataset_invite", {
        email: info.inviterEmail,
        name: info.datasetName,
      });

  const path = info.urlPath.startsWith("/") ? info.urlPath : `/${info.urlPath}`;
  const text = `${escapeHtml(summary)} — <a href="${escapeHtml(path)}">${escapeHtml(tx("invite.open"))}</a>`;

  try {
    SonicToast.add({
      id: `invite-${path}`,
      title: tx("invite.title"),
      text,
      status: "info",
      preserve: true,
    });
  } catch (error) {
    console.warn("[toast] dataset invite failed", error);
  }
}

export function toastMemberJoined(info: {
  datasetName: string;
  memberEmail: string;
  role?: string;
}): void {
  const roleKey =
    info.role === "writer"
      ? "invite.role_writer"
      : info.role === "reader"
        ? "invite.role_reader"
        : null;
  const roleLabel = roleKey ? tx(roleKey) : "";
  const text = roleLabel
    ? tf("notif.member_joined_role", {
        email: info.memberEmail,
        name: info.datasetName,
        role: roleLabel,
      })
    : tf("notif.member_joined", {
        email: info.memberEmail,
        name: info.datasetName,
      });

  try {
    SonicToast.add({
      title: tx("cloud.share_title"),
      text: escapeHtml(text),
      status: "success",
    });
  } catch (error) {
    console.warn("[toast] member joined failed", error);
  }
}

const CALENDAR_DRAG_TOAST_ID = "calendar-drag";

/** Live toast while dragging a task in the calendar (upsert by stable id). */
export function toastCalendarDrag(info: {
  title: string;
  text: string;
}): void {
  const title = info.title;
  const text = escapeHtml(info.text);
  try {
    const host = SonicToast.getInstance();
    const existing = host.toasts.some((item) => item.id === CALENDAR_DRAG_TOAST_ID);
    if (existing) {
      host.toasts = host.toasts.map((item) =>
        item.id === CALENDAR_DRAG_TOAST_ID
          ? {
              ...item,
              title,
              text,
              status: "info",
              preserve: true,
            }
          : item,
      );
      return;
    }
    SonicToast.add({
      id: CALENDAR_DRAG_TOAST_ID,
      title,
      text,
      status: "info",
      preserve: true,
    });
  } catch (error) {
    console.warn("[toast] calendar drag failed", error);
  }
}

export function clearCalendarDragToast(): void {
  try {
    const host = SonicToast.getInstance();
    host.toasts = host.toasts.filter((item) => item.id !== CALENDAR_DRAG_TOAST_ID);
  } catch (error) {
    console.warn("[toast] calendar drag clear failed", error);
  }
}
