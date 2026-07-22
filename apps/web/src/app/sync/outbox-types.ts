import type {Tag, Todo} from "../api/types";

export type SyncEntity = "todo" | "tag";

export type SyncMutation = {
  id: string;
  datasetBaseId: string;
  entity: SyncEntity;
  entityId: string;
  op: "upsert" | "delete";
  payload?: Todo | Tag;
  fieldVersions: Record<string, string>;
  createdAt: string;
  status: "pending" | "inflight" | "failed";
  retries: number;
};

export type SyncState = {
  baseId: string;
  cloudDatasetId?: string;
  lastPulledAt: string | null;
  lastPushedAt: string | null;
  lastSyncAt: string | null;
  lastError: string | null;
  bootstrapped: boolean;
};
