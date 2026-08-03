import {read, set} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import type {AccountSettings} from "../account-settings";
import {saveAccountSettings} from "../account-settings";

/** Ensure appConfigKey holds account form fields from the current session. */
export function hydrateAccountForm(account: AccountSettings): void {
  const form = read(appConfigKey.path) as AppConfigForm | undefined;
  set(appConfigKey.path, {
    newDatasetName: form?.newDatasetName ?? "",
    p2pReceiveCode: form?.p2pReceiveCode ?? "",
    accountEmail: account.user?.email ?? form?.accountEmail ?? "",
    accountPassword: "",
    accountWebsite: "",
    accountApiBaseUrl: account.apiBaseUrl,
    newCloudDatasetName: form?.newCloudDatasetName ?? "",
    newAccessTokenName: form?.newAccessTokenName ?? "",
    shareInviteEmail: form?.shareInviteEmail ?? "",
    webhookUrl: form?.webhookUrl ?? "",
    embedName: form?.embedName ?? "",
    embedOrigins: form?.embedOrigins ?? "",
  });
}

export function persistAccountApiBaseUrl(
  account: AccountSettings,
): AccountSettings {
  const form = read(appConfigKey.path) as AppConfigForm;
  const next = {
    ...account,
    apiBaseUrl: form.accountApiBaseUrl.trim(),
  };
  saveAccountSettings(next);
  return next;
}

export function clearAccountPasswordField(): void {
  const form = read(appConfigKey.path) as AppConfigForm;
  set(appConfigKey.path, {...form, accountPassword: ""});
}

export function clearAccountCredentialsFields(): void {
  const form = read(appConfigKey.path) as AppConfigForm;
  set(appConfigKey.path, {
    ...form,
    accountPassword: "",
    accountEmail: "",
  });
}
