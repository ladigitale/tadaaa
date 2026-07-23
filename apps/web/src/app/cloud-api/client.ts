import {
  clearAccountSession,
  getCloudApiRoot,
  loadAccountSettings,
  saveAccountSettings,
  type AccountSettings,
  type CloudUser,
} from "../account-settings";

export type CloudDatasetInfo = {
  id: string;
  baseId: string;
  name: string;
  updatedAt: string;
  active: boolean;
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
          ? "Session cloud expirée ou invalide — reconnectez-vous."
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
      message:
        result.message ??
        "Demande enregistrée — un administrateur doit valider votre compte.",
    };
  }

  const next: AccountSettings = {
    apiBaseUrl: base,
    token: result.token,
    user: result.user,
  };
  saveAccountSettings(next);
  return {settings: next, pending: false, message: "Compte créé."};
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
  return next;
}

export function logoutAccount(): AccountSettings {
  return clearAccountSession();
}

export async function refreshAccountSession(
  settings: AccountSettings = loadAccountSettings(),
): Promise<AccountSettings> {
  if (!settings.token) return settings;
  const me = await cloudFetch<MeResponse>("/auth/me", {}, settings);
  const next = {...settings, user: me.user};
  saveAccountSettings(next);
  return next;
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
