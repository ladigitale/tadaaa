import {isAccountConnected} from "../account-settings";
import {
  fetchVapidPublicKey,
  registerPushSubscription,
  revokePushSubscription,
} from "../cloud-api/client";

export type PushStatusCode =
  | "ready"
  | "checking"
  | "local_only"
  | "need_account"
  | "need_permission"
  | "permission_denied"
  | "unsupported"
  | "server_disabled"
  | "no_service_worker"
  | "not_subscribed"
  | "register_failed"
  | "offline";

export type PushStatus = {
  code: PushStatusCode;
  /** Browser Notification.permission */
  permission: NotificationPermission | "unsupported";
  accountConnected: boolean;
  settingEnabled: boolean;
  vapidEnabled: boolean;
  hasServiceWorker: boolean;
  hasPushSubscription: boolean;
  registeredOnServer: boolean;
  detail?: string;
};

let pushActive = false;
let lastStatus: PushStatus | null = null;
const statusListeners = new Set<(status: PushStatus) => void>();

export function isServerPushActive(): boolean {
  return pushActive;
}

export function getLastPushStatus(): PushStatus | null {
  return lastStatus;
}

export function subscribePushStatus(
  listener: (status: PushStatus) => void,
): () => void {
  statusListeners.add(listener);
  if (lastStatus) listener(lastStatus);
  return () => {
    statusListeners.delete(listener);
  };
}

function emitStatus(status: PushStatus): PushStatus {
  lastStatus = status;
  pushActive = status.code === "ready";
  for (const listener of statusListeners) {
    try {
      listener(status);
    } catch {
      /* ignore */
    }
  }
  return status;
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) {
    output[i] = raw.charCodeAt(i);
  }
  return output;
}

async function getRegistration(): Promise<ServiceWorkerRegistration | null> {
  if (!("serviceWorker" in navigator)) return null;
  try {
    return await navigator.serviceWorker.ready;
  } catch {
    return null;
  }
}

function baseStatus(
  partial: Partial<PushStatus> & {code: PushStatusCode},
): PushStatus {
  const permission =
    typeof Notification === "undefined"
      ? "unsupported"
      : Notification.permission;
  return {
    permission,
    accountConnected: isAccountConnected(),
    settingEnabled: false,
    vapidEnabled: false,
    hasServiceWorker: false,
    hasPushSubscription: false,
    registeredOnServer: false,
    ...partial,
  };
}

/**
 * Probe live push readiness (permission, SW, VAPID, browser subscription).
 * Does not mutate subscriptions.
 */
export async function probePushStatus(options?: {
  settingEnabled?: boolean;
}): Promise<PushStatus> {
  const settingEnabled = options?.settingEnabled ?? false;
  emitStatus(
    baseStatus({code: "checking", settingEnabled}),
  );

  if (typeof Notification === "undefined" || !("PushManager" in window)) {
    return emitStatus(
      baseStatus({code: "unsupported", settingEnabled}),
    );
  }

  const permission = Notification.permission;
  if (permission === "denied") {
    return emitStatus(
      baseStatus({code: "permission_denied", settingEnabled, permission}),
    );
  }

  if (!settingEnabled) {
    return emitStatus(
      baseStatus({
        code: permission === "granted" ? "local_only" : "need_permission",
        settingEnabled: false,
        permission,
      }),
    );
  }

  if (!isAccountConnected()) {
    return emitStatus(
      baseStatus({
        code: "need_account",
        settingEnabled: true,
        permission,
      }),
    );
  }

  if (!navigator.onLine) {
    return emitStatus(
      baseStatus({
        code: "offline",
        settingEnabled: true,
        permission,
        accountConnected: true,
      }),
    );
  }

  try {
    const {publicKey, enabled} = await fetchVapidPublicKey();
    if (!enabled || !publicKey) {
      return emitStatus(
        baseStatus({
          code: "server_disabled",
          settingEnabled: true,
          permission,
          accountConnected: true,
          vapidEnabled: false,
        }),
      );
    }

    const registration = await getRegistration();
    if (!registration?.pushManager) {
      return emitStatus(
        baseStatus({
          code: "no_service_worker",
          settingEnabled: true,
          permission,
          accountConnected: true,
          vapidEnabled: true,
        }),
      );
    }

    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      return emitStatus(
        baseStatus({
          code: "not_subscribed",
          settingEnabled: true,
          permission,
          accountConnected: true,
          vapidEnabled: true,
          hasServiceWorker: true,
          hasPushSubscription: false,
        }),
      );
    }

    return emitStatus(
      baseStatus({
        code: "ready",
        settingEnabled: true,
        permission,
        accountConnected: true,
        vapidEnabled: true,
        hasServiceWorker: true,
        hasPushSubscription: true,
        registeredOnServer: true,
      }),
    );
  } catch (error) {
    return emitStatus(
      baseStatus({
        code: "register_failed",
        settingEnabled: true,
        permission,
        accountConnected: true,
        detail: error instanceof Error ? error.message : String(error),
      }),
    );
  }
}

/**
 * Subscribe this device to server Web Push and register the endpoint.
 */
export async function subscribeServerPush(): Promise<boolean> {
  pushActive = false;
  if (!isAccountConnected()) {
    await probePushStatus({settingEnabled: true});
    return false;
  }
  if (!("PushManager" in window)) {
    await probePushStatus({settingEnabled: true});
    return false;
  }
  if (typeof Notification === "undefined" || Notification.permission !== "granted") {
    await probePushStatus({settingEnabled: true});
    return false;
  }

  try {
    const {publicKey, enabled} = await fetchVapidPublicKey();
    if (!enabled || !publicKey) {
      await probePushStatus({settingEnabled: true});
      return false;
    }

    const registration = await getRegistration();
    if (!registration?.pushManager) {
      await probePushStatus({settingEnabled: true});
      return false;
    }

    let subscription = await registration.pushManager.getSubscription();
    if (subscription) {
      const json = subscription.toJSON();
      if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
        await subscription.unsubscribe().catch(() => undefined);
        subscription = null;
      }
    }
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey) as BufferSource,
      });
    }

    const json = subscription.toJSON();
    if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
      await probePushStatus({settingEnabled: true});
      return false;
    }

    await registerPushSubscription({
      endpoint: json.endpoint,
      keys: {p256dh: json.keys.p256dh, auth: json.keys.auth},
      userAgent: navigator.userAgent,
    });
    pushActive = true;
    emitStatus(
      baseStatus({
        code: "ready",
        settingEnabled: true,
        permission: Notification.permission,
        accountConnected: true,
        vapidEnabled: true,
        hasServiceWorker: true,
        hasPushSubscription: true,
        registeredOnServer: true,
      }),
    );
    return true;
  } catch (error) {
    console.warn("[push] subscribe failed", error);
    pushActive = false;
    emitStatus(
      baseStatus({
        code: "register_failed",
        settingEnabled: true,
        permission: Notification.permission,
        accountConnected: true,
        detail: error instanceof Error ? error.message : String(error),
      }),
    );
    return false;
  }
}

export async function unsubscribeServerPush(): Promise<void> {
  pushActive = false;
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
    await probePushStatus({settingEnabled: false});
    return;
  }

  try {
    const registration = await getRegistration();
    const subscription = await registration?.pushManager.getSubscription();
    if (!subscription) {
      await probePushStatus({settingEnabled: false});
      return;
    }

    const endpoint = subscription.endpoint;
    try {
      await subscription.unsubscribe();
    } catch {
      /* ignore */
    }
    if (isAccountConnected() && endpoint) {
      try {
        await revokePushSubscription(endpoint);
      } catch {
        /* ignore */
      }
    }
  } catch (error) {
    console.warn("[push] unsubscribe failed", error);
  }
  await probePushStatus({settingEnabled: false});
}

/** Refresh in-memory flag from existing subscription (e.g. after reload). */
export async function refreshServerPushState(): Promise<boolean> {
  const status = await probePushStatus({settingEnabled: true});
  return status.code === "ready";
}
