import {formatBaseId} from "../api/data-package";
import {getIdbTodoStore} from "../api/store-idb";
import {getSyncState} from "./outbox";

export async function isActiveDatasetReadonly(): Promise<boolean> {
  const datasets = await getIdbTodoStore().listDatasets();
  const active = datasets.find((dataset) => dataset.active);
  if (!active) return false;
  const state = await getSyncState(formatBaseId(active.baseId));
  return state.cloudRole === "reader";
}

export async function assertCanEditActiveDataset(): Promise<void> {
  if (await isActiveDatasetReadonly()) {
    throw new Error("Ce jeu cloud est en lecture seule.");
  }
}
