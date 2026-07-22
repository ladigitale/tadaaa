import type {Tag, Todo} from "../api/types";
import type {SyncMutationEvent} from "../api/store";
import {buildUpsertPayload} from "./merge";
import {enqueueSyncMutation} from "./outbox";

export async function enqueueMutationForDataset(
  baseId: string,
  event: SyncMutationEvent,
): Promise<void> {
  if (event.op === "delete") {
    await enqueueSyncMutation({
      datasetBaseId: baseId,
      entity: event.entity,
      entityId: event.entityId,
      op: "delete",
      fieldVersions: event.fieldVersions,
    });
  } else if (event.record) {
    await enqueueSyncMutation({
      datasetBaseId: baseId,
      entity: event.entity,
      entityId: event.entityId,
      op: "upsert",
      payload: buildUpsertPayload(event.entity, event.record as Todo | Tag),
      fieldVersions: event.fieldVersions,
    });
  }
}
