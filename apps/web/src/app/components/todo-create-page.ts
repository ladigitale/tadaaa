import "@supersoniks/concorde/input";
import "@supersoniks/concorde/textarea";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {createTodo, fetchTags} from "../api/client";
import type {Tag, TodoPriority} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {todoCreateKey, type TodoCreateForm} from "../dp";
import {tx} from "../i18n";
import {navigateTo} from "../utils/navigate";
import {TACHE_ROOT, tacheItemPath} from "../utils/tache-paths";
import {isEnterSubmitEvent} from "../utils/form-enter-submit";
import {focusPrimaryInput} from "../utils/focus-primary-input";
import {parseDateOnly} from "../utils/dates";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import {showError} from "../utils/modal-dialog";
import "./page-shell";
import "./pop-select";
import "./task-scope-header";
import "./tag-picker";
import type {PopSelectOption} from "./pop-select";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";

function priorityOptions(): PopSelectOption[] {
  return [
    {value: "low", label: tx("tasks.priority.low"), icon: "arrow-down"},
    {value: "medium", label: tx("tasks.priority.medium"), icon: "minus"},
    {value: "high", label: tx("tasks.priority.high"), icon: "arrow-up"},
  ];
}

const emptyCreateForm = (): TodoCreateForm => ({
  text: "",
  description: "",
  priority: "medium",
  tagIds: [],
  startAt: "",
  endAt: "",
});

@customElement("todo-create-page")
export class TodoCreatePage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  /** Parent de la nouvelle tâche (vide = racine). */
  @property({type: String})
  parentId = "";

  @state()
  private tags: Tag[] = [];

  @subscribe(todoCreateKey)
  @state()
  createForm: TodoCreateForm = emptyCreateForm();

  @subscribe(todoCreateKey.priority)
  @state()
  createPriority: TodoPriority = "medium";

  @subscribe(todoCreateKey.tagIds)
  @state()
  createTagIds: string[] = [];

  @state()
  private busy = false;

  connectedCallback() {
    super.connectedCallback();
    set(todoCreateKey.path, emptyCreateForm());
    void this.loadTags();
  }

  protected firstUpdated() {
    void focusPrimaryInput(this);
  }

  private async loadTags() {
    this.tags = await fetchTags();
  }

  private onFormKeyDown = (event: KeyboardEvent) => {
    if (!isEnterSubmitEvent(event)) return;
    event.preventDefault();
    void this.onSubmit();
  };

  private get cancelHref(): string {
    const id = this.parentId?.trim();
    return id ? tacheItemPath(id) : TACHE_ROOT;
  }

  private async onSubmit() {
    const form = read(todoCreateKey.path) as TodoCreateForm;
    const text = form.text?.trim();
    if (!text || this.busy) return;

    const tagIds = (Array.isArray(form.tagIds) ? form.tagIds : []).filter(
      Boolean,
    );
    const parentId = this.parentId?.trim() || null;

    this.busy = true;
    try {
      const startAt = parseDateOnly(form.startAt);
      const endAt = parseDateOnly(form.endAt);
      await createTodo({
        text,
        description: form.description?.trim() || null,
        priority: (form.priority ?? "medium") as TodoPriority,
        tagIds,
        parentId,
        startAt,
        endAt,
      });
      set(todoCreateKey.path, emptyCreateForm());
      navigateTo(this.cancelHref, true);
    } catch (error) {
      await showError(error);
      console.error(error);
    } finally {
      this.busy = false;
    }
  }

  render() {
    return html`
      <page-shell>
        <div class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4">
          <task-scope-header
            .scopeId=${this.parentId}
            action="create"
          ></task-scope-header>
        </div>

        <div
          class="mt-3"
          formDataProvider=${todoCreateKey.path}
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
              type="date"
              name="endAt"
              label=${tx("tasks.form.end_at")}
            ></sonic-input>

            <pop-select
              label=${tx("tasks.form.priority")}
              showLabel
              name="priority"
              mode="radio"
              size="md"
              .value=${this.createPriority}
              .options=${priorityOptions()}
              ?disabled=${this.busy}
              minWidth="12rem"
            ></pop-select>

            ${this.tags.length > 0
              ? html`
                  <div class="form-field">
                    <label class="form-label">${t("tasks.form.tags")}</label>
                    <div class="form-field-control">
                      <tag-picker
                        formPath=${todoCreateKey.path}
                        name="tagIds"
                        .tags=${this.tags}
                        .value=${Array.isArray(this.createTagIds)
                          ? this.createTagIds
                          : []}
                        ?disabled=${this.busy}
                      ></tag-picker>
                    </div>
                  </div>
                `
              : nothing}

            <sonic-form-actions justify="flex-end">
              <sonic-button
                href=${this.cancelHref}
                pushstate
                variant="outline"
                ?disabled=${this.busy}
              >
                ${t("common.cancel")}
              </sonic-button>
              <sonic-button
                type="primary"
                ?disabled=${this.busy}
                @click=${this.onSubmit}
              >
                <sonic-icon
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name="plus"
                  size="sm"
                ></sonic-icon>
                ${t("common.add")}
              </sonic-button>
            </sonic-form-actions>
          </sonic-form-layout>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "todo-create-page": TodoCreatePage;
  }
}
