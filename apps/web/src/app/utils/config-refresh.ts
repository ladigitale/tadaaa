import {set} from "../../utils/dataprovider";
import {fetchTags} from "../api/client";
import {tagsListKey} from "../dp";
import {bumpTodosRev} from "../init";

export async function refreshConfigAppData(): Promise<void> {
  bumpTodosRev();
  try {
    set(tagsListKey.path, await fetchTags());
  } catch {
    set(tagsListKey.path, []);
  }
}
