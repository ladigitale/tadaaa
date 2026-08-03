import type {APIConfiguration} from "@supersoniks/concorde/utils/api";
import {DataProviderKey} from "@supersoniks/concorde/dataProviderKey";
import {set} from "../../utils/dataprovider";
import {MOCK_API_PATH_PREFIX} from "./router";

export const apiConfigKey = new DataProviderKey<APIConfiguration>(
  "tadaApiConfiguration",
);

export function getMockApiServiceUrl(origin = location.origin): string {
  return new URL(MOCK_API_PATH_PREFIX, origin).href.replace(/\/$/, "");
}

export const defaultApiConfiguration = (
  origin = location.origin,
): APIConfiguration => ({
  serviceURL: getMockApiServiceUrl(origin),
  token: null,
  userName: null,
  password: null,
  authToken: null,
  tokenProvider: null,
  credentials: "same-origin",
});

export function initApiConfiguration(origin = location.origin): void {
  set(apiConfigKey.path, defaultApiConfiguration(origin));
}
