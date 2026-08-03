import type {SyncMutationEvent} from "../api/store";

type SyncHandler = (event: SyncMutationEvent) => void;

let handler: SyncHandler | null = null;

export function registerSyncHandler(next: SyncHandler | null): void {
  handler = next;
}

export function emitSyncMutation(event: SyncMutationEvent): void {
  handler?.(event);
}
