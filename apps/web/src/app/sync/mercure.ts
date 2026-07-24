import {
  isAccountConnected,
  loadAccountSettings,
} from "../account-settings";
import {
  fetchMercureCredentials,
  fetchMercureSession,
  type MercureCredentials,
} from "../cloud-api/client";
import {tf, tx} from "../i18n";
import {
  notifyDatasetInvite,
  notifyMemberJoined,
} from "../notifications/web-notifications";
import {
  toastDatasetInvite,
  toastMemberJoined,
} from "../notifications/sonic-toasts";
import {confirmDialog} from "../utils/modal-dialog";
import {getActiveDatasetSyncState, runDatasetSync} from "./engine";

let eventSource: EventSource | null = null;
let subscribedKey: string | null = null;
let refreshTimer: number | null = null;
let syncDebounceTimer: number | null = null;
let startInflight: Promise<void> | null = null;
/** Soft-disable after hub 404 / repeated errors (no reconnect spam). */
let disabledUntil = 0;
let consecutiveErrors = 0;
/** Avoid stacking invite dialogs for the same token. */
const seenInviteTokens = new Set<string>();
let inviteDialogOpen = false;

const SYNC_DEBOUNCE_MS = 250;
const TOKEN_REFRESH_MARGIN_MS = 60_000;
const DISABLE_AFTER_ERRORS = 3;
const DISABLE_COOLDOWN_MS = 15 * 60_000;

type MercurePayload = {
  type?: string;
  baseId?: string;
  datasetName?: string;
  memberEmail?: string;
  inviterEmail?: string;
  role?: string;
  urlPath?: string;
  token?: string;
  datasetUpdatedAt?: string;
};

function clearRefreshTimer(): void {
  if (refreshTimer !== null) {
    window.clearTimeout(refreshTimer);
    refreshTimer = null;
  }
}

function stopMercureSubscription(): void {
  clearRefreshTimer();
  if (syncDebounceTimer !== null) {
    window.clearTimeout(syncDebounceTimer);
    syncDebounceTimer = null;
  }
  if (eventSource) {
    eventSource.close();
    eventSource = null;
  }
  subscribedKey = null;
}

function schedulePullFromMercure(): void {
  if (syncDebounceTimer !== null) window.clearTimeout(syncDebounceTimer);
  syncDebounceTimer = window.setTimeout(() => {
    syncDebounceTimer = null;
    void runDatasetSync();
  }, SYNC_DEBOUNCE_MS);
}

function buildEventSourceUrl(
  hubUrl: string,
  topics: string[],
  token: string,
): string {
  const url = new URL(hubUrl);
  for (const topic of topics) {
    url.searchParams.append("topic", topic);
  }
  url.searchParams.set("authorization", token);
  return url.toString();
}

function disableMercureTemporarily(reason: string): void {
  console.warn(`[mercure] ${reason} — realtime sync en pause`);
  stopMercureSubscription();
  disabledUntil = Date.now() + DISABLE_COOLDOWN_MS;
  consecutiveErrors = 0;
}

function navigateToInvite(urlPath: string): void {
  const path = urlPath.startsWith("/") ? urlPath : `/${urlPath}`;
  window.history.pushState({}, "", path);
  window.dispatchEvent(new PopStateEvent("popstate"));
}

/**
 * Always prefer user topic; attach dataset topic only when the cloud accepts that baseId.
 * Local-only datasets must not block personal invite notifications.
 */
async function fetchCredentialsPreferringUserTopic(
  baseId: string | null,
): Promise<MercureCredentials> {
  if (!baseId) {
    return fetchMercureSession();
  }
  try {
    return await fetchMercureCredentials(baseId);
  } catch (error) {
    console.warn(
      "[mercure] dataset topic unavailable — falling back to user topic",
      error,
    );
    return fetchMercureSession();
  }
}

async function presentDatasetInvite(payload: MercurePayload): Promise<void> {
  const urlPath =
    payload.urlPath ||
    (payload.token ? `/invite?token=${encodeURIComponent(payload.token)}` : "");
  if (!payload.datasetName || !payload.inviterEmail || !urlPath) {
    console.warn("[mercure] dataset_invite incomplete", payload);
    return;
  }

  const tokenKey = payload.token || urlPath;
  if (seenInviteTokens.has(tokenKey)) return;

  const roleLabel =
    payload.role === "writer"
      ? tx("invite.role_writer")
      : payload.role === "reader"
        ? tx("invite.role_reader")
        : "";
  const message = roleLabel
    ? tf("notif.dataset_invite_role", {
        email: payload.inviterEmail,
        name: payload.datasetName,
        role: roleLabel,
      })
    : tf("notif.dataset_invite", {
        email: payload.inviterEmail,
        name: payload.datasetName,
      });

  // Defer out of EventSource callback — Lit/modal + toast need a clean turn.
  await new Promise<void>((resolve) => {
    window.setTimeout(resolve, 0);
  });

  seenInviteTokens.add(tokenKey);

  toastDatasetInvite({
    datasetName: payload.datasetName,
    inviterEmail: payload.inviterEmail,
    role: payload.role,
    urlPath,
  });

  notifyDatasetInvite({
    datasetName: payload.datasetName,
    inviterEmail: payload.inviterEmail,
    role: payload.role,
    urlPath,
  });

  if (inviteDialogOpen) return;
  inviteDialogOpen = true;
  try {
    const accept = await confirmDialog({
      title: tx("invite.title"),
      message,
      confirmLabel: tx("invite.open"),
      cancelLabel: tx("common.later"),
    });
    if (accept) {
      navigateToInvite(urlPath);
    }
  } catch (error) {
    console.warn("[mercure] invite dialog failed", error);
  } finally {
    inviteDialogOpen = false;
  }
}

function handleMercurePayload(raw: string): void {
  let payload: MercurePayload = {};
  try {
    payload = JSON.parse(raw) as MercurePayload;
  } catch {
    schedulePullFromMercure();
    return;
  }

  if (payload.type === "member_joined") {
    if (payload.memberEmail && payload.datasetName) {
      const me = loadAccountSettings().user?.email?.toLowerCase();
      if (me && payload.memberEmail.toLowerCase() === me) {
        return;
      }
      notifyMemberJoined({
        datasetName: payload.datasetName,
        memberEmail: payload.memberEmail,
        role: payload.role,
      });
      toastMemberJoined({
        datasetName: payload.datasetName,
        memberEmail: payload.memberEmail,
        role: payload.role,
      });
    }
    return;
  }

  if (payload.type === "dataset_invite") {
    void presentDatasetInvite(payload);
    return;
  }

  // dataset_changed (or legacy ping without type)
  schedulePullFromMercure();
}

/**
 * Subscribe to Mercure for the logged-in user (+ active cloud dataset when any).
 * Safe to call often; reconnects when baseId changes or token nears expiry.
 */
export async function ensureMercureSubscription(): Promise<void> {
  if (!navigator.onLine || !isAccountConnected()) {
    stopMercureSubscription();
    return;
  }

  if (Date.now() < disabledUntil) return;

  if (startInflight) return startInflight;

  startInflight = (async () => {
    const state = await getActiveDatasetSyncState();
    // Try dataset topic when we have a local baseId; API 404 → user topic only.
    const baseId = state?.baseId ?? null;

    try {
      const settings = loadAccountSettings();
      if (!settings.token) return;

      const creds = await fetchCredentialsPreferringUserTopic(baseId);
      const topics =
        creds.topics && creds.topics.length > 0
          ? creds.topics
          : [creds.topic];
      const topicsKey = topics.slice().sort().join("|");
      if (eventSource && subscribedKey === topicsKey) {
        return;
      }

      stopMercureSubscription();

      const es = new EventSource(
        buildEventSourceUrl(creds.hubUrl, topics, creds.token),
      );
      eventSource = es;
      subscribedKey = topicsKey;

      es.onopen = () => {
        consecutiveErrors = 0;
        console.info("[mercure] subscribed", topics);
      };

      es.onmessage = (event) => {
        consecutiveErrors = 0;
        handleMercurePayload(typeof event.data === "string" ? event.data : "");
      };

      es.onerror = () => {
        consecutiveErrors += 1;
        if (consecutiveErrors >= DISABLE_AFTER_ERRORS) {
          disableMercureTemporarily(
            "hub injoignable (404/CORS) — active FrankenPHP (yarn api:up) pour le realtime",
          );
          return;
        }
        if (es.readyState === EventSource.CLOSED) {
          stopMercureSubscription();
          window.setTimeout(() => {
            void ensureMercureSubscription();
          }, 2_000);
        }
      };

      const refreshInMs = Math.max(
        5_000,
        creds.expiresIn * 1000 - TOKEN_REFRESH_MARGIN_MS,
      );
      refreshTimer = window.setTimeout(() => {
        stopMercureSubscription();
        void ensureMercureSubscription();
      }, refreshInMs);
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      // 503 = MERCURE_ENABLED=0 — silencieux
      if (/503|désactivé|disabled|unavailable/i.test(message)) {
        disableMercureTemporarily("Mercure désactivé sur l’API");
        return;
      }
      console.warn("[mercure] subscription failed", error);
      consecutiveErrors += 1;
      if (consecutiveErrors >= DISABLE_AFTER_ERRORS) {
        disableMercureTemporarily("échec d’abonnement répété");
      } else {
        stopMercureSubscription();
      }
    }
  })().finally(() => {
    startInflight = null;
  });

  return startInflight;
}

/** Clear soft-disable (e.g. after login) and reconnect. */
export function resetMercureSubscription(): void {
  disabledUntil = 0;
  consecutiveErrors = 0;
  stopMercureSubscription();
  void ensureMercureSubscription();
}

export function teardownMercureSubscription(): void {
  stopMercureSubscription();
}
