import {isAccountConnected} from "../account-settings";
import {
  fetchVapidPublicKey,
  registerPushSubscription,
  revokePushSubscription,
} from "../cloud-api/client";

let pushActive = false;

export function isServerPushActive(): boolean {
  return pushActive;
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
    const reg = await navigator.serviceWorker.ready;
    return reg;
  } catch {
    return null;
  }
}

/**
 * Subscribe this device to server Web Push and register the endpoint.
 * No-op when offline, not logged in, or VAPID disabled on API.
 */
export async function subscribeServerPush(): Promise<boolean> {
  pushActive = false;
  if (!isAccountConnected()) return false;
  if (!("PushManager" in window)) return false;
  if (typeof Notification === "undefined" || Notification.permission !== "granted") {
    return false;
  }

  try {
    const {publicKey, enabled} = await fetchVapidPublicKey();
    if (!enabled || !publicKey) return false;

    const registration = await getRegistration();
    if (!registration?.pushManager) return false;

    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey) as BufferSource,
      });
    }

    const json = subscription.toJSON();
    if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
      return false;
    }

    await registerPushSubscription({
      endpoint: json.endpoint,
      keys: {p256dh: json.keys.p256dh, auth: json.keys.auth},
      userAgent: navigator.userAgent,
    });
    pushActive = true;
    return true;
  } catch (error) {
    console.warn("[push] subscribe failed", error);
    pushActive = false;
    return false;
  }
}

export async function unsubscribeServerPush(): Promise<void> {
  pushActive = false;
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) return;

  try {
    const registration = await getRegistration();
    const subscription = await registration?.pushManager.getSubscription();
    if (!subscription) return;

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
}

/** Refresh in-memory flag from existing subscription (e.g. after reload). */
export async function refreshServerPushState(): Promise<boolean> {
  pushActive = false;
  if (!isAccountConnected()) return false;
  try {
    const registration = await getRegistration();
    const subscription = await registration?.pushManager.getSubscription();
    if (!subscription) return false;
    pushActive = true;
    return true;
  } catch {
    return false;
  }
}
