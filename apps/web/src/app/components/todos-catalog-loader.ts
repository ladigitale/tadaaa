import {LitElement} from "lit";
import {customElement, state} from "lit/decorators.js";
import {get, type ApiResult} from "@supersoniks/concorde/decorators";
import {set} from "../../utils/dataprovider";
import {endpoints} from "../api/endpoints";
import type {TodosListResponse} from "../api/types";
import {todosArchivedCatalogKey, todosCatalogKey} from "../dp";

/**
 * Host invisible : charge les catalogues todos via `@get` et publie dans les DP app.
 * Rafraîchir : `bumpTodosCatalog()` / `dp(endpoints.keys.refresh.todosCatalog).invalidate()`.
 * Nécessite un ancêtre `serviceURL` (layout).
 */
@customElement("todos-catalog-loader")
export class TodosCatalogLoader extends LitElement {
  @get(endpoints.todos.catalog, {
    triggerKey: endpoints.keys.refresh.todosCatalog,
  })
  @state()
  activePayload: ApiResult<TodosListResponse> | null = null;

  @get(endpoints.todos.archivedCatalog, {
    triggerKey: endpoints.keys.refresh.todosCatalog,
  })
  @state()
  archivedPayload: ApiResult<TodosListResponse> | null = null;

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("activePayload")) {
      const data = this.activePayload?.result?.data;
      set(todosCatalogKey.path, Array.isArray(data) ? data : []);
    }
    if (changed.has("archivedPayload")) {
      const data = this.archivedPayload?.result?.data;
      set(todosArchivedCatalogKey.path, Array.isArray(data) ? data : []);
    }
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
    "todos-catalog-loader": TodosCatalogLoader;
  }
}
