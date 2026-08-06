import {
  clearAccountSession,
  getCloudApiRoot,
  loadAccountSettings,
  saveAccountSettings,
  type AccountSettings,
  type CloudUser,
} from "../account-settings";
import {applyCloudLinkDetectors, type LinkDetector} from "../settings";
import {tx} from "../i18n";
import type {LegalPublicConfig} from "../legal";
import {
  resetMercureSubscription,
  teardownMercureSubscription,
} from "../sync/mercure";

export type CloudDatasetRole = "owner" | "writer" | "reader";

export type CloudDatasetInfo = {
  id: string;
  baseId: string;
  name: string;
  updatedAt: string;
  active: boolean;
  role?: CloudDatasetRole;
};

type MeResponse = {
  user: CloudUser;
  quotas?: QuotasReport;
};

export type QuotasReport = {
  storage: {
    usedBytes: number;
    quotaBytes: number | null;
    unlimited: boolean;
    ratio: number | null;
  };
  bandwidth: {
    dayUsedBytes: number;
    dayQuotaBytes: number | null;
    monthUsedBytes: number;
    monthQuotaBytes: number | null;
    unlimited: boolean;
  };
};

function asMemberCollection<T>(result: unknown): T[] {
  if (Array.isArray(result)) return result as T[];
  if (result && typeof result === "object") {
    const obj = result as Record<string, unknown>;
    if (Array.isArray(obj.member)) return obj.member as T[];
    if (Array.isArray(obj["hydra:member"])) {
      return obj["hydra:member"] as T[];
    }
  }
  return [];
}

async function cloudFetch<T>(
  path: string,
  init: RequestInit = {},
  settings: AccountSettings = loadAccountSettings(),
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (!headers.has("Content-Type") && init.body) {
    headers.set("Content-Type", "application/json");
  }
  if (settings.token) {
    headers.set("Authorization", `Bearer ${settings.token}`);
  }

  const response = await fetch(`${getCloudApiRoot(settings)}${path}`, {
    ...init,
    headers,
  });

  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as {
      error?: string;
      detail?: string;
      message?: string;
    };
    if (response.status === 401) {
      clearAccountSession();
    }
    throw new Error(
      body.message ??
        body.error ??
        body.detail ??
        (response.status === 401
          ? tx("account.session_expired")
          : `API cloud ${response.status}`),
    );
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json() as Promise<T>;
}

export async function registerAccount(
  email: string,
  password: string,
  apiBaseUrl?: string,
  website = "",
  acceptedTerms = false,
): Promise<{settings: AccountSettings; pending: boolean; message: string}> {
  const base = apiBaseUrl?.trim() || loadAccountSettings().apiBaseUrl;
  const settings: AccountSettings = {
    apiBaseUrl: base,
    token: null,
    user: null,
  };

  const result = await cloudFetch<{
    status?: string;
    message?: string;
    token?: string;
    user?: CloudUser;
  }>(
    "/auth/register",
    {
      method: "POST",
      body: JSON.stringify({email, password, website, acceptedTerms}),
    },
    settings,
  );

  if (result.status === "pending" || !result.token || !result.user) {
    return {
      settings,
      pending: true,
      message: result.message ?? tx("account.register_pending"),
    };
  }

  const next: AccountSettings = {
    apiBaseUrl: base,
    token: result.token,
    user: result.user,
  };
  saveAccountSettings(next);
  resetMercureSubscription();
  syncLinkDetectorsFromUser(result.user);
  return {settings: next, pending: false, message: tx("account.register_ok")};
}

export async function loginAccount(
  email: string,
  password: string,
  apiBaseUrl?: string,
): Promise<AccountSettings> {
  const base = apiBaseUrl?.trim() || loadAccountSettings().apiBaseUrl;
  const settings: AccountSettings = {
    apiBaseUrl: base,
    token: null,
    user: null,
  };

  const result = await cloudFetch<{token: string}>(
    "/auth/login",
    {
      method: "POST",
      body: JSON.stringify({email, password}),
    },
    settings,
  );

  const me = await cloudFetch<MeResponse>("/auth/me", {}, {
    ...settings,
    token: result.token,
  });

  const next: AccountSettings = {
    apiBaseUrl: base,
    token: result.token,
    user: me.user,
  };
  saveAccountSettings(next);
  resetMercureSubscription();
  syncLinkDetectorsFromUser(me.user);
  return next;
}

export function logoutAccount(): AccountSettings {
  teardownMercureSubscription();
  return clearAccountSession();
}

export async function refreshAccountSession(
  settings: AccountSettings = loadAccountSettings(),
): Promise<AccountSettings> {
  if (!settings.token) return settings;
  const me = await cloudFetch<MeResponse>("/auth/me", {}, settings);
  const next = {...settings, user: me.user};
  saveAccountSettings(next);
  syncLinkDetectorsFromUser(me.user);
  return next;
}

function syncLinkDetectorsFromUser(user: CloudUser): void {
  if (Array.isArray(user.linkDetectors)) {
    applyCloudLinkDetectors(user.linkDetectors);
  }
}

export async function fetchLinkDetectors(
  settings: AccountSettings = loadAccountSettings(),
): Promise<LinkDetector[]> {
  const result = await cloudFetch<{linkDetectors: LinkDetector[]}>(
    "/link-detectors",
    {},
    settings,
  );
  return result.linkDetectors ?? [];
}

export async function replaceLinkDetectors(
  linkDetectors: LinkDetector[],
  settings: AccountSettings = loadAccountSettings(),
): Promise<LinkDetector[]> {
  const result = await cloudFetch<{linkDetectors: LinkDetector[]}>(
    "/link-detectors",
    {
      method: "PUT",
      body: JSON.stringify({linkDetectors}),
    },
    settings,
  );
  const saved = result.linkDetectors ?? [];
  applyCloudLinkDetectors(saved);
  return saved;
}

export async function fetchCloudDatasets(
  settings: AccountSettings = loadAccountSettings(),
): Promise<CloudDatasetInfo[]> {
  const result = await cloudFetch<unknown>("/datasets", {}, settings);
  const activeId = settings.user?.activeDatasetId ?? null;
  return asMemberCollection<CloudDatasetInfo>(result).map((dataset) => ({
    ...dataset,
    baseId: dataset.baseId?.startsWith("base-")
      ? dataset.baseId
      : `base-${dataset.baseId}`,
    active: activeId !== null && dataset.id === activeId,
  }));
}

export async function createCloudDataset(
  name: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<CloudDatasetInfo> {
  const dataset = await cloudFetch<Omit<CloudDatasetInfo, "active">>(
    "/datasets",
    {
      method: "POST",
      body: JSON.stringify({name}),
    },
    settings,
  );
  const activeId = settings.user?.activeDatasetId ?? null;
  return {
    ...dataset,
    active: activeId !== null && dataset.id === activeId,
  };
}

export async function activateCloudDataset(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<CloudDatasetInfo> {
  const dataset = await cloudFetch<Omit<CloudDatasetInfo, "active">>(
    `/datasets/${encodeURIComponent(id)}/activate`,
    {method: "POST"},
    settings,
  );
  const next = await refreshAccountSession(settings);
  return {
    ...dataset,
    active: next.user?.activeDatasetId === dataset.id,
  };
}

export async function deleteCloudDataset(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<void> {
  await cloudFetch<void>(
    `/datasets/${encodeURIComponent(id)}`,
    {method: "DELETE"},
    settings,
  );
}

export async function renameCloudDataset(
  id: string,
  name: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<CloudDatasetInfo> {
  const dataset = await cloudFetch<Omit<CloudDatasetInfo, "active">>(
    `/datasets/${encodeURIComponent(id)}`,
    {
      method: "PATCH",
      body: JSON.stringify({name}),
      headers: {"Content-Type": "application/merge-patch+json"},
    },
    settings,
  );
  const activeId = settings.user?.activeDatasetId ?? null;
  return {
    ...dataset,
    baseId: dataset.baseId?.startsWith("base-")
      ? dataset.baseId
      : `base-${dataset.baseId}`,
    active: activeId !== null && dataset.id === activeId,
  };
}

export async function checkCloudApiHealth(
  settings: AccountSettings = loadAccountSettings(),
): Promise<boolean> {
  try {
    const response = await fetch(`${getCloudApiRoot(settings)}/health`);
    if (!response.ok) return false;
    const body = (await response.json()) as {status?: string};
    return body.status === "ok";
  } catch {
    return false;
  }
}

export async function fetchLegalConfig(
  settings: AccountSettings = loadAccountSettings(),
): Promise<LegalPublicConfig> {
  const response = await fetch(`${getCloudApiRoot(settings)}/legal`);
  if (!response.ok) {
    throw new Error(`API legal ${response.status}`);
  }
  return response.json() as Promise<LegalPublicConfig>;
}

export async function exportMyAccountData(
  settings: AccountSettings = loadAccountSettings(),
): Promise<unknown> {
  return cloudFetch<unknown>("/auth/me/export", {}, settings);
}

export async function deleteMyAccount(
  confirmEmail: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<{deleted: boolean; email: string}> {
  return cloudFetch<{deleted: boolean; email: string}>(
    "/auth/me",
    {
      method: "DELETE",
      body: JSON.stringify({confirmEmail}),
    },
    settings,
  );
}

export type AccessTokenInfo = {
  id: string;
  name: string;
  tokenPrefix: string;
  createdAt: string;
  lastUsedAt: string | null;
};

export type CreatedAccessToken = {
  token: AccessTokenInfo;
  plainToken: string;
  mcpUrl: string;
};

export async function fetchAccessTokens(
  settings: AccountSettings = loadAccountSettings(),
): Promise<AccessTokenInfo[]> {
  const result = await cloudFetch<unknown>("/access-tokens", {}, settings);
  return asMemberCollection<AccessTokenInfo>(result);
}

export async function createAccessToken(
  name: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<CreatedAccessToken> {
  return cloudFetch<CreatedAccessToken>(
    "/access-tokens",
    {
      method: "POST",
      body: JSON.stringify({name}),
    },
    settings,
  );
}

export async function revokeAccessToken(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<void> {
  await cloudFetch<void>(
    `/access-tokens/${encodeURIComponent(id)}`,
    {method: "DELETE"},
    settings,
  );
}

export type WebhookEventInfo = {
  type: string;
  description: string;
};

export type WebhookInfo = {
  id: string;
  url: string;
  secretPrefix: string;
  events: string[];
  datasetId: string | null;
  active: boolean;
  createdAt: string;
  lastDeliveryAt: string | null;
  failureCount: number;
};

export type CreatedWebhook = {
  webhook: WebhookInfo;
  plainSecret: string;
};

export type WebhookDeliveryInfo = {
  id: string;
  eventId: string;
  eventType: string;
  status: "success" | "failed" | string;
  httpStatus: number | null;
  responseMs: number | null;
  error: string | null;
  requestBytes: number;
  createdAt: string;
};

export type ActivityLogInfo = {
  id: string;
  category: string;
  action: string;
  meta: Record<string, unknown>;
  ip: string | null;
  createdAt: string;
};

export type UsageReport = {
  from: string;
  to: string;
  scope?: "user" | "all";
  userId?: string | null;
  totals: Record<string, number>;
  byDay: Array<{
    day: string;
    datasetId: string | null;
    counters: Record<string, number>;
  }>;
};

export async function fetchWebhookEvents(
  settings: AccountSettings = loadAccountSettings(),
): Promise<WebhookEventInfo[]> {
  const result = await cloudFetch<unknown>("/webhooks/events", {}, settings);
  return asMemberCollection<WebhookEventInfo>(result);
}

export async function fetchWebhooks(
  settings: AccountSettings = loadAccountSettings(),
): Promise<WebhookInfo[]> {
  const result = await cloudFetch<unknown>("/webhooks", {}, settings);
  return asMemberCollection<WebhookInfo>(result);
}

export async function createWebhook(
  input: {url: string; events?: string[]; datasetId?: string | null},
  settings: AccountSettings = loadAccountSettings(),
): Promise<CreatedWebhook> {
  return cloudFetch<CreatedWebhook>(
    "/webhooks",
    {method: "POST", body: JSON.stringify(input)},
    settings,
  );
}

export async function updateWebhook(
  id: string,
  patch: {
    url?: string;
    events?: string[];
    active?: boolean;
    datasetId?: string | null;
  },
  settings: AccountSettings = loadAccountSettings(),
): Promise<WebhookInfo> {
  const result = await cloudFetch<{webhook: WebhookInfo}>(
    `/webhooks/${encodeURIComponent(id)}`,
    {method: "PATCH", body: JSON.stringify(patch)},
    settings,
  );
  return result.webhook;
}

export async function deleteWebhook(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<void> {
  await cloudFetch<void>(
    `/webhooks/${encodeURIComponent(id)}`,
    {method: "DELETE"},
    settings,
  );
}

export async function fetchWebhookDeliveries(
  id: string,
  limit = 50,
  settings: AccountSettings = loadAccountSettings(),
): Promise<WebhookDeliveryInfo[]> {
  const result = await cloudFetch<unknown>(
    `/webhooks/${encodeURIComponent(id)}/deliveries?limit=${limit}`,
    {},
    settings,
  );
  return asMemberCollection<WebhookDeliveryInfo>(result);
}

export async function pingWebhook(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<WebhookDeliveryInfo> {
  const result = await cloudFetch<{delivery: WebhookDeliveryInfo}>(
    `/webhooks/${encodeURIComponent(id)}/ping`,
    {method: "POST"},
    settings,
  );
  return result.delivery;
}

export async function fetchActivity(
  category?: string,
  limit = 50,
  settings: AccountSettings = loadAccountSettings(),
): Promise<ActivityLogInfo[]> {
  const params = new URLSearchParams();
  if (category) params.set("category", category);
  params.set("limit", String(limit));
  const result = await cloudFetch<unknown>(
    `/activity?${params.toString()}`,
    {},
    settings,
  );
  return asMemberCollection<ActivityLogInfo>(result);
}

export async function fetchUsage(
  from?: string,
  to?: string,
  options: {userId?: string; settings?: AccountSettings} = {},
): Promise<UsageReport> {
  const settings = options.settings ?? loadAccountSettings();
  const params = new URLSearchParams();
  if (from) params.set("from", from);
  if (to) params.set("to", to);
  if (options.userId !== undefined) {
    params.set("userId", options.userId === "" ? "all" : options.userId);
  }
  const q = params.toString();
  return cloudFetch<UsageReport>(`/usage${q ? `?${q}` : ""}`, {}, settings);
}

export type AdminUserInfo = {
  id: string;
  email: string;
  createdAt: string;
  status: "pending" | "active" | "rejected" | "disabled";
  roles: string[];
  storageQuotaBytes?: number | null;
  bandwidthQuotaMonthBytes?: number | null;
  emailVerifiedAt?: string | null;
};

export async function fetchAdminUsers(
  status?: AdminUserInfo["status"],
  settings: AccountSettings = loadAccountSettings(),
): Promise<AdminUserInfo[]> {
  const query = status ? `?status=${encodeURIComponent(status)}` : "";
  const result = await cloudFetch<unknown>(`/admin/users${query}`, {}, settings);
  return asMemberCollection<AdminUserInfo>(result);
}

function moderationBody(message?: string): RequestInit {
  return {
    method: "POST",
    body: JSON.stringify({message: message?.trim() ?? ""}),
  };
}

export async function approveAdminUser(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
  message = "",
): Promise<AdminUserInfo> {
  const result = await cloudFetch<{user: AdminUserInfo}>(
    `/admin/users/${encodeURIComponent(id)}/approve`,
    moderationBody(message),
    settings,
  );
  return result.user;
}

export async function rejectAdminUser(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
  message = "",
): Promise<AdminUserInfo> {
  const result = await cloudFetch<{user: AdminUserInfo}>(
    `/admin/users/${encodeURIComponent(id)}/reject`,
    moderationBody(message),
    settings,
  );
  return result.user;
}

export async function disableAdminUser(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
  message = "",
): Promise<AdminUserInfo> {
  const result = await cloudFetch<{user: AdminUserInfo}>(
    `/admin/users/${encodeURIComponent(id)}/disable`,
    moderationBody(message),
    settings,
  );
  return result.user;
}

export async function deleteAdminUser(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
  message = "",
): Promise<void> {
  await cloudFetch(
    `/admin/users/${encodeURIComponent(id)}`,
    {
      method: "DELETE",
      body: JSON.stringify({message: message.trim()}),
    },
    settings,
  );
}

export async function updateAdminUserQuotas(
  id: string,
  body: {
    storageQuotaBytes?: number | null;
    bandwidthQuotaMonthBytes?: number | null;
    resetStorage?: boolean;
    resetBandwidth?: boolean;
  },
  settings: AccountSettings = loadAccountSettings(),
): Promise<AdminUserInfo> {
  const result = await cloudFetch<{user: AdminUserInfo}>(
    `/admin/users/${encodeURIComponent(id)}/quotas`,
    {method: "PATCH", body: JSON.stringify(body)},
    settings,
  );
  return result.user;
}

export async function verifyAccountEmail(
  token: string,
  apiBaseUrl?: string,
): Promise<AccountSettings> {
  const base = apiBaseUrl?.trim() || loadAccountSettings().apiBaseUrl;
  const settings: AccountSettings = {
    apiBaseUrl: base,
    token: null,
    user: null,
  };
  const result = await cloudFetch<{
    token: string;
    user: CloudUser;
  }>(
    "/auth/verify-email",
    {method: "POST", body: JSON.stringify({token})},
    settings,
  );
  const next: AccountSettings = {
    apiBaseUrl: base,
    token: result.token,
    user: result.user,
  };
  saveAccountSettings(next);
  resetMercureSubscription();
  syncLinkDetectorsFromUser(result.user);
  return next;
}

export async function resendVerificationEmail(
  email: string,
  apiBaseUrl?: string,
): Promise<string> {
  const base = apiBaseUrl?.trim() || loadAccountSettings().apiBaseUrl;
  const settings: AccountSettings = {
    apiBaseUrl: base,
    token: null,
    user: null,
  };
  const result = await cloudFetch<{message?: string}>(
    "/auth/resend-verification",
    {method: "POST", body: JSON.stringify({email})},
    settings,
  );
  return result.message ?? tx("account.verify.resent");
}

export async function fetchQuotas(
  settings: AccountSettings = loadAccountSettings(),
): Promise<QuotasReport> {
  return cloudFetch<QuotasReport>("/quotas", {}, settings);
}

export type DatasetMemberInfo = {
  id: string;
  userId: string;
  email: string;
  role: CloudDatasetRole;
  createdAt: string;
};

export type DatasetInviteCreated = {
  token: string;
  urlPath: string;
  role: "writer" | "reader";
  expiresAt: string;
};

export type DatasetInviteByEmailResult = DatasetInviteCreated & {
  notified: boolean;
  email: string;
};

export type DatasetInvitePreview = {
  datasetName: string;
  role: "writer" | "reader";
  expiresAt: string;
  usable: boolean;
};

export async function createDatasetInvite(
  datasetId: string,
  role: "writer" | "reader",
  settings: AccountSettings = loadAccountSettings(),
): Promise<DatasetInviteCreated> {
  return cloudFetch<DatasetInviteCreated>(
    `/datasets/${encodeURIComponent(datasetId)}/invites`,
    {
      method: "POST",
      body: JSON.stringify({role}),
    },
    settings,
  );
}

export async function inviteDatasetByEmail(
  datasetId: string,
  email: string,
  role: "writer" | "reader",
  settings: AccountSettings = loadAccountSettings(),
): Promise<DatasetInviteByEmailResult> {
  return cloudFetch<DatasetInviteByEmailResult>(
    `/datasets/${encodeURIComponent(datasetId)}/invites/email`,
    {
      method: "POST",
      body: JSON.stringify({email, role}),
    },
    settings,
  );
}

export async function fetchDatasetMembers(
  datasetId: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<DatasetMemberInfo[]> {
  const result = await cloudFetch<unknown>(
    `/datasets/${encodeURIComponent(datasetId)}/members`,
    {},
    settings,
  );
  return asMemberCollection<DatasetMemberInfo>(result);
}

export async function removeDatasetMember(
  datasetId: string,
  userId: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<void> {
  await cloudFetch<void>(
    `/datasets/${encodeURIComponent(datasetId)}/members/${encodeURIComponent(userId)}`,
    {method: "DELETE"},
    settings,
  );
}

export async function previewDatasetInvite(
  token: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<DatasetInvitePreview> {
  return cloudFetch<DatasetInvitePreview>(
    `/invites/${encodeURIComponent(token)}`,
    {},
    settings,
  );
}

export async function acceptDatasetInvite(
  token: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<{
  dataset: {
    id: string;
    baseId: string;
    name: string;
    role: CloudDatasetRole;
  };
}> {
  return cloudFetch(
    `/invites/${encodeURIComponent(token)}/accept`,
    {method: "POST"},
    settings,
  );
}

export type MercureCredentials = {
  hubUrl: string;
  /** Primary topic (legacy / user). */
  topic: string;
  /** All authorized topics (user + optional dataset). */
  topics?: string[];
  token: string;
  expiresIn: number;
};

export async function fetchMercureCredentials(
  baseId: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<MercureCredentials> {
  return cloudFetch<MercureCredentials>(
    `/mercure?baseId=${encodeURIComponent(baseId)}`,
    {},
    settings,
  );
}

/** User topic only (share / invite events when no active dataset). */
export async function fetchMercureSession(
  settings: AccountSettings = loadAccountSettings(),
): Promise<MercureCredentials> {
  return cloudFetch<MercureCredentials>(`/mercure`, {}, settings);
}

export type NotificationPreferenceRow = {
  type: string;
  enabled: boolean;
  default: boolean;
};

export async function fetchVapidPublicKey(
  settings: AccountSettings = loadAccountSettings(),
): Promise<{publicKey: string | null; enabled: boolean}> {
  return cloudFetch<{publicKey: string | null; enabled: boolean}>(
    `/push/vapid-public-key`,
    {},
    settings,
  );
}

export type PushSubscriptionInfo = {
  id: string;
  endpoint: string;
  endpointHost: string;
  userAgent: string | null;
  createdAt: string;
  lastSeenAt: string | null;
};

export type PushTestResult = {
  ok: boolean;
  code: string;
  sent: number;
  failed: number;
  results: Array<{
    endpointHost: string;
    success: boolean;
    reason: string | null;
    statusCode: number | null;
  }>;
};

export async function fetchPushSubscriptions(
  settings: AccountSettings = loadAccountSettings(),
): Promise<{enabled: boolean; subscriptions: PushSubscriptionInfo[]}> {
  return cloudFetch<{enabled: boolean; subscriptions: PushSubscriptionInfo[]}>(
    `/push/subscriptions`,
    {},
    settings,
  );
}

export async function registerPushSubscription(
  body: {
    endpoint: string;
    keys: {p256dh: string; auth: string};
    userAgent?: string;
  },
  settings: AccountSettings = loadAccountSettings(),
): Promise<{id: string; endpoint: string}> {
  return cloudFetch<{id: string; endpoint: string}>(
    `/push/subscriptions`,
    {method: "POST", body: JSON.stringify(body)},
    settings,
  );
}

export async function revokePushSubscription(
  endpoint: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<void> {
  await cloudFetch<void>(
    `/push/subscriptions`,
    {method: "DELETE", body: JSON.stringify({endpoint})},
    settings,
  );
}

export async function sendPushTest(
  settings: AccountSettings = loadAccountSettings(),
): Promise<PushTestResult> {
  return cloudFetch<PushTestResult>(
    `/push/test`,
    {method: "POST", body: "{}"},
    settings,
  );
}

export async function fetchNotificationPreferences(
  settings: AccountSettings = loadAccountSettings(),
): Promise<NotificationPreferenceRow[]> {
  const result = await cloudFetch<{preferences: NotificationPreferenceRow[]}>(
    `/notification-preferences`,
    {},
    settings,
  );
  return result.preferences ?? [];
}

export async function updateNotificationPreferences(
  preferences: Record<string, boolean>,
  settings: AccountSettings = loadAccountSettings(),
): Promise<NotificationPreferenceRow[]> {
  const result = await cloudFetch<{preferences: NotificationPreferenceRow[]}>(
    `/notification-preferences`,
    {
      method: "PUT",
      body: JSON.stringify({preferences}),
    },
    settings,
  );
  return result.preferences ?? [];
}

export type EmbedKeyInfo = {
  id: string;
  name: string;
  tokenPrefix: string;
  datasetId: string;
  datasetName: string;
  allowedOrigins: string[];
  tagIds: string[];
  includeDone: boolean;
  includeDescription: boolean;
  active: boolean;
  rateLimitPerMinute: number;
  createdAt: string;
  lastUsedAt: string | null;
  lastOrigin: string | null;
  requestCount: number;
  bytesServed: number;
};

export type CreatedEmbedKey = {
  embed: EmbedKeyInfo;
  plainToken: string;
};

export async function fetchEmbedKeys(
  settings: AccountSettings = loadAccountSettings(),
): Promise<EmbedKeyInfo[]> {
  const result = await cloudFetch<unknown>("/embeds", {}, settings);
  return asMemberCollection<EmbedKeyInfo>(result);
}

export async function createEmbedKey(
  input: {
    name: string;
    datasetId: string;
    allowedOrigins?: string[];
    tagIds?: string[];
    includeDone?: boolean;
    includeDescription?: boolean;
    rateLimitPerMinute?: number;
  },
  settings: AccountSettings = loadAccountSettings(),
): Promise<CreatedEmbedKey> {
  return cloudFetch<CreatedEmbedKey>(
    "/embeds",
    {method: "POST", body: JSON.stringify(input)},
    settings,
  );
}

export async function updateEmbedKey(
  id: string,
  patch: Partial<{
    name: string;
    datasetId: string;
    allowedOrigins: string[];
    tagIds: string[];
    includeDone: boolean;
    includeDescription: boolean;
    active: boolean;
    rateLimitPerMinute: number;
  }>,
  settings: AccountSettings = loadAccountSettings(),
): Promise<EmbedKeyInfo> {
  const result = await cloudFetch<{embed: EmbedKeyInfo}>(
    `/embeds/${encodeURIComponent(id)}`,
    {method: "PATCH", body: JSON.stringify(patch)},
    settings,
  );
  return result.embed;
}

export async function rotateEmbedKey(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<CreatedEmbedKey> {
  return cloudFetch<CreatedEmbedKey>(
    `/embeds/${encodeURIComponent(id)}/rotate`,
    {method: "POST"},
    settings,
  );
}

export async function revokeEmbedKey(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<void> {
  await cloudFetch<void>(
    `/embeds/${encodeURIComponent(id)}`,
    {method: "DELETE"},
    settings,
  );
}
