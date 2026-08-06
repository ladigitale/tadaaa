import {bumpTagsList, bumpTodosRev} from "../init";

export async function refreshConfigAppData(): Promise<void> {
  bumpTodosRev();
  bumpTagsList();
}
