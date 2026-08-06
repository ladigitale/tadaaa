import "@supersoniks/concorde/input";
import "@supersoniks/concorde/textarea";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import "@supersoniks/concorde/tooltip";
import {html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {
  get,
  patch,
  subscribe,
  type ApiResult,
} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  apiResultError,
  endpoints,
  readApiData,
  type ApiData,
} from "../api/endpoints";
import type {Tag, Todo, TodoPriority, TodoRecurrence} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {tagsListKey, todoEditKey, type TodoEditForm} from "../dp";
import {bumpTodosRev} from "../init";
import {tx} from "../i18n";
import {navigateTo} from "../utils/navigate";
import {TACHE_ROOT, tacheItemPath} from "../utils/tache-paths";
import {isEnterSubmitEvent} from "../utils/form-enter-submit";
import {focusPrimaryInput} from "../utils/focus-primary-input";
import {localInputFromWire, wireFromLocalInput} from "../utils/dates";
import {parseRecurrence} from "../utils/recurrence";
import {shortcuts} from "../shortcuts";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import {showError} from "../utils/modal-dialog";
import "./page-shell";
import "./pop-select";
import "./task-scope-header";
import "./tag-picker";
import type {PopSelectOption} from "./pop-select";

function priorityOptions(): PopSelectOption[] {
  return [
    {value: "low", label: tx("tasks.priority.low"), icon: "arrow-down"},
    {value: "medium", label: tx("tasks.priority.medium"), icon: "minus"},
    {value: "high", label: tx("tasks.priority.high"), icon: "arrow-up"},
  ];
}

function recurrenceOptions(): PopSelectOption[] {
  return [
    {value: "none", label: tx("tasks.recurrence.none"), icon: "minus"},
    {value: "daily", label: tx("tasks.recurrence.daily"), icon: "refresh"},
    {value: "weekly", label: tx("tasks.recurrence.weekly"), icon: "refresh"},
    {value: "monthly", label: tx("tasks.recurrence.monthly"), icon: "refresh"},
  ];
}

@customElement("todo-edit-page")
export class TodoEditPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @property({type: String})
  todoId = "";

  @subscribe(tagsListKey)
  @state()
  tags: Tag[] = [];

  /** Parent de la tâche éditée (retour liste après save). */
  @state()
  private parentTodoId = "";

  @state()
  private loading = true;

  @state()
  private notFound = false;

  @subscribe(todoEditKey)
  @state()
  editForm: TodoEditForm = {
    text: "",
    description: "",
    priority: "medium",
    tagIds: [],
    startAt: "",
    startTime: "",
    endAt: "",
    endTime: "",
    recurrence: "none",
  };

  @subscribe(todoEditKey.priority)
  @state()
  editPriority: TodoPriority = "medium";

  @subscribe(todoEditKey.recurrence)
  @state()
  editRecurrence: TodoRecurrence = "none";

  @subscribe(todoEditKey.tagIds)
  @state()
  editTagIds: string[] = [];

  @state()
  private busy = false;

  @get(endpoints.todos.byId, {skipEmptyPlaceholder: true})
  @state()
  todoPayload: ApiResult<ApiData<Todo>> | null = null;

  @patch(endpoints.todos.patch, endpoints.keys.submit.todoEdit, {
    skipEmptyPlaceholder: true,
  })
  @state()
  savePayload: ApiResult<ApiData<Todo>> | null = null;

  private lastHydratedTodoId = "";
  private pendingSubmit = false;

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("todoId")) {
      this.lastHydratedTodoId = "";
      this.loading = Boolean(this.todoId);
      this.notFound = false;
    }
    if (changed.has("todoPayload") || changed.has("todoId")) {
      this.hydrateFromPayload();
    }
    if (changed.has("savePayload") && this.pendingSubmit) {
      void this.finishSubmit();
    }
  }

  private async finishSubmit() {
    this.pendingSubmit = false;
    set(endpoints.keys.submit.todoEdit.path, null);
    const todo = readApiData(this.savePayload);
    if (!todo) {
      await showError(apiResultError(this.savePayload));
      this.busy = false;
      return;
    }
    bumpTodosRev();
    navigateTo(this.backHref, true);
  }

  private hydrateFromPayload() {
    if (!this.todoId) return;

    const payload = this.todoPayload;
    if (payload == null) {
      this.loading = true;
      return;
    }

    const todo = payload.result?.data;
    if (!todo?.id) {
      this.notFound = true;
      this.loading = false;
      return;
    }

    if (todo.id === this.lastHydratedTodoId) {
      this.loading = false;
      return;
    }

    this.lastHydratedTodoId = todo.id;
    this.notFound = false;
    this.parentTodoId = todo.parentId?.trim() || "";
    const tagIds = [...todo.tagIds];
    const start = localInputFromWire(todo.startAt);
    const end = localInputFromWire(todo.endAt);
    // tagIds tout de suite : le tag-picker réhydrate FormCheckable au mount.
    set(todoEditKey.path, {
      text: todo.text,
      description: todo.description ?? "",
      priority: todo.priority ?? "medium",
      tagIds,
      startAt: start.date,
      startTime: start.time,
      endAt: end.date,
      endTime: end.time,
      recurrence: parseRecurrence(todo.recurrence),
    });
    this.loading = false;
    void focusPrimaryInput(this);
  }

  private get backHref(): string {
    return this.parentTodoId ? tacheItemPath(this.parentTodoId) : TACHE_ROOT;
  }

  private onFormKeyDown = (event: KeyboardEvent) => {
    if (!isEnterSubmitEvent(event)) return;
    event.preventDefault();
    this.onSubmit();
  };

  private onSubmit() {
    const form = read(todoEditKey.path) as TodoEditForm;
    const text = form.text?.trim();
    if (!text || !this.todoId || this.busy) return;

    const rawTagIds = form.tagIds;
    const tagIds = (Array.isArray(rawTagIds) ? rawTagIds : [])
      .map((id) => String(id))
      .filter((id) => id && id !== "undefined");

    this.busy = true;
    this.pendingSubmit = true;
    set(endpoints.keys.submit.todoEdit.path, {
      text,
      description: form.description?.trim() || null,
      priority: (form.priority ?? "medium") as TodoPriority,
      tagIds,
      startAt: wireFromLocalInput(form.startAt, form.startTime),
      endAt: wireFromLocalInput(form.endAt, form.endTime),
      recurrence: parseRecurrence(form.recurrence),
    });
  }

  private renderScopeHeader() {
    return html`
      <div
        class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
      >
        <task-scope-header
          .scopeId=${this.todoId}
          action="edit"
        ></task-scope-header>
      </div>
    `;
  }

  render() {
    if (this.loading) {
      return html`
        <page-shell>
          ${this.renderScopeHeader()}
          <p class="mt-3 text-sm text-neutral-500">${t("common.loading")}</p>
        </page-shell>
      `;
    }

    if (this.notFound) {
      return html`
        <page-shell>
          ${this.renderScopeHeader()}
          <p class="mt-3 text-sm text-neutral-500">${t("tasks.not_found")}</p>
          <sonic-button href=${TACHE_ROOT} pushstate variant="outline">
            ${t("tasks.back")}
          </sonic-button>
        </page-shell>
      `;
    }

    return html`
      <page-shell>
        ${this.renderScopeHeader()}

        <div
          class="mt-3"
          formDataProvider=${todoEditKey.path}
          @keydown=${this.onFormKeyDown}
        >
          <sonic-form-layout>
            <sonic-input
              name="text"
              label=${tx("tasks.form.name")}
              placeholder=${tx("tasks.form.name_ph")}
            ></sonic-input>

            <sonic-textarea
              name="description"
              label=${tx("tasks.form.description")}
              placeholder=${tx("tasks.form.description_ph")}
              rows="3"
            ></sonic-textarea>

            <sonic-input
              type="date"
              name="startAt"
              label=${tx("tasks.form.start_at")}
            ></sonic-input>

            <sonic-input
              type="time"
              name="startTime"
              label=${tx("tasks.form.start_time")}
            ></sonic-input>

            <sonic-input
              type="date"
              name="endAt"
              label=${tx("tasks.form.end_at")}
            ></sonic-input>

            <sonic-input
              type="time"
              name="endTime"
              label=${tx("tasks.form.end_time")}
            ></sonic-input>

            <pop-select
              label=${tx("tasks.form.priority")}
              showLabel
              name="priority"
              mode="radio"
              size="md"
              .value=${this.editPriority}
              .options=${priorityOptions()}
              ?disabled=${this.busy}
              minWidth="12rem"
            ></pop-select>

            <pop-select
              label=${tx("tasks.form.recurrence")}
              showLabel
              name="recurrence"
              mode="radio"
              size="md"
              .value=${this.editRecurrence}
              .options=${recurrenceOptions()}
              ?disabled=${this.busy}
              minWidth="12rem"
            ></pop-select>

            ${this.tags.length > 0
              ? html`
                  <div class="form-field">
                    <label class="form-label">${t("tasks.form.tags")}</label>
                    <div class="form-field-control">
                      <tag-picker
                        formPath=${todoEditKey.path}
                        name="tagIds"
                        .tags=${this.tags}
                        .value=${Array.isArray(this.editTagIds)
                          ? this.editTagIds
                          : []}
                        ?disabled=${this.busy}
                      ></tag-picker>
                    </div>
                  </div>
                `
              : nothing}

            <sonic-form-actions justify="flex-end">
              <sonic-button
                href=${this.backHref}
                pushstate
                variant="outline"
                ?disabled=${this.busy}
              >
                ${t("common.cancel")}
              </sonic-button>
              <sonic-tooltip
                label=${shortcuts.withHint(tx("common.save"), "submitForm")}
                placement="top"
              >
                <sonic-button
                  type="primary"
                  ?disabled=${this.busy}
                  data-aria-label=${shortcuts.withHint(
                    tx("common.save"),
                    "submitForm",
                  )}
                  @click=${this.onSubmit}
                >
                  ${t("common.save")}
                </sonic-button>
              </sonic-tooltip>
            </sonic-form-actions>
          </sonic-form-layout>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "todo-edit-page": TodoEditPage;
  }
}
