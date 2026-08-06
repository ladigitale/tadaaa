import {LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {get, type ApiResult} from "@supersoniks/concorde/decorators";
import {set} from "../../utils/dataprovider";
import {endpoints, type ApiData} from "../api/endpoints";
import type {Tag} from "../api/types";
import {tagsListKey} from "../dp";

/**
 * Host invisible : charge `/tags` via `@get` et publie dans `tagsListKey`.
 * Rafraîchir : `bumpTagsList()` / `dp(endpoints.keys.refresh.tagsList).invalidate()`.
 * Nécessite un ancêtre `serviceURL` (layout).
 */
@customElement("tags-list-loader")
export class TagsListLoader extends LitElement {
  @get(endpoints.tags.list, {triggerKey: endpoints.keys.refresh.tagsList})
  @state()
  payload: ApiResult<ApiData<Tag[]>> | null = null;

  protected updated(changed: Map<string, unknown>) {
    if (!changed.has("payload")) return;
    const tags = this.payload?.result?.data;
    set(tagsListKey.path, Array.isArray(tags) ? tags : []);
  }

  createRenderRoot() {
    return this;
  }

  render() {
    return null;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "tags-list-loader": TagsListLoader;
  }
}
