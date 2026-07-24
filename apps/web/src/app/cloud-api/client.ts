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
      body: JSON.stringify({email, password}),
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

export type AdminUserInfo = {
  id: string;
  email: string;
  createdAt: string;
  status: "pending" | "active" | "rejected" | "disabled";
  roles: string[];
};

export async function fetchAdminUsers(
  status?: AdminUserInfo["status"],
  settings: AccountSettings = loadAccountSettings(),
): Promise<AdminUserInfo[]> {
  const query = status ? `?status=${encodeURIComponent(status)}` : "";
  const result = await cloudFetch<unknown>(`/admin/users${query}`, {}, settings);
  return asMemberCollection<AdminUserInfo>(result);
}

export async function approveAdminUser(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<AdminUserInfo> {
  const result = await cloudFetch<{user: AdminUserInfo}>(
    `/admin/users/${encodeURIComponent(id)}/approve`,
    {method: "POST"},
    settings,
  );
  return result.user;
}

export async function rejectAdminUser(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<AdminUserInfo> {
  const result = await cloudFetch<{user: AdminUserInfo}>(
    `/admin/users/${encodeURIComponent(id)}/reject`,
    {method: "POST"},
    settings,
  );
  return result.user;
}

export async function disableAdminUser(
  id: string,
  settings: AccountSettings = loadAccountSettings(),
): Promise<AdminUserInfo> {
  const result = await cloudFetch<{user: AdminUserInfo}>(
    `/admin/users/${encodeURIComponent(id)}/disable`,
    {method: "POST"},
    settings,
  );
  return result.user;
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
