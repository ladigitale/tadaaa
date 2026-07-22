import "@supersoniks/concorde/input";
import "@supersoniks/concorde/textarea";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/form-layout";
import "@supersoniks/concorde/form-actions";
import {html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {fetchTags, fetchTodo, patchTodo} from "../api/client";
import type {Tag, TodoPriority} from "../api/types";
import {read, set} from "../../utils/dataprovider";
import {todoEditKey, type TodoEditForm} from "../dp";
import {navigateTo} from "../utils/navigate";
import {TACHE_ROOT, tacheItemPath} from "../utils/tache-paths";
import {isEnterSubmitEvent} from "../utils/form-enter-submit";
import {formLabelStyles} from "../styles/form-label";
import tailwind from "../../css/tailwind";
import {showError} from "../utils/modal-dialog";
import "./page-shell";
import "./pop-select";
import "./task-scope-header";
import "./tag-picker";
import type {PopSelectOption} from "./pop-select";

const PRIORITY_OPTIONS: PopSelectOption[] = [
  {value: "low", label: "Basse", icon: "arrow-down"},
  {value: "medium", label: "Moyenne", icon: "minus"},
  {value: "high", label: "Haute", icon: "arrow-up"},
];

@customElement("todo-edit-page")
export class TodoEditPage extends LitElement {
  static styles = [tailwind, formLabelStyles];

  @property({type: String})
  todoId = "";

  @state()
  private tags: Tag[] = [];

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
  };

  @subscribe(todoEditKey.priority)
  @state()
  editPriority: TodoPriority = "medium";

  @subscribe(todoEditKey.tagIds)
  @state()
  editTagIds: string[] = [];

  @state()
  private busy = false;

  connectedCallback() {
    super.connectedCallback();
    void this.load();
  }

  protected updated(changed: Map<string, unknown>) {
    if (changed.has("todoId") && this.todoId) {
      void this.load();
    }
  }

  private async load() {
    if (!this.todoId) return;

    this.loading = true;
    this.notFound = false;
    try {
      const [todo, tags] = await Promise.all([
        fetchTodo(this.todoId),
        fetchTags(),
      ]);
      this.tags = tags;
      this.parentTodoId = todo.parentId?.trim() || "";
      const tagIds = [...todo.tagIds];
      // tagIds tout de suite : le tag-picker réhydrate FormCheckable au mount.
      set(todoEditKey.path, {
        text: todo.text,
        description: todo.description ?? "",
        priority: todo.priority ?? "medium",
        tagIds,
      });
    } catch {
      this.notFound = true;
    } finally {
      this.loading = false;
    }
  }

  private get backHref(): string {
    return this.parentTodoId ? tacheItemPath(this.parentTodoId) : TACHE_ROOT;
  }

  private onFormKeyDown = (event: KeyboardEvent) => {
    if (!isEnterSubmitEvent(event)) return;
    event.preventDefault();
    void this.onSubmit();
  };

  private async onSubmit() {
    const form = read(todoEditKey.path) as TodoEditForm;
    const text = form.text?.trim();
    if (!text || !this.todoId || this.busy) return;

    const rawTagIds = form.tagIds;
    const tagIds = (Array.isArray(rawTagIds) ? rawTagIds : [])
      .map((id) => String(id))
      .filter((id) => id && id !== "undefined");

    this.busy = true;
    try {
      await patchTodo(this.todoId, {
        text,
        description: form.description?.trim() || null,
        priority: (form.priority ?? "medium") as TodoPriority,
        tagIds,
      });
      navigateTo(this.backHref, true);
    } catch (error) {
      await showError(error, "Impossible de modifier la tâche");
      console.error(error);
    } finally {
      this.busy = false;
    }
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
          <p class="mt-3 text-sm text-neutral-500">Chargement…</p>
        </page-shell>
      `;
    }

    if (this.notFound) {
      return html`
        <page-shell>
          ${this.renderScopeHeader()}
          <p class="mt-3 text-sm text-neutral-500">Tâche introuvable.</p>
          <sonic-button href=${TACHE_ROOT} pushstate variant="outline">
            Retour aux tâches
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
              label="Nom"
              placeholder="Ex. Suivre RM-42"
            ></sonic-input>

            <sonic-textarea
              name="description"
              label="Description"
              placeholder="Détails optionnels…"
              rows="3"
            ></sonic-textarea>

            <pop-select
              label="Priorité"
              showLabel
              name="priority"
              mode="radio"
              size="md"
              .value=${this.editPriority}
              .options=${PRIORITY_OPTIONS}
              ?disabled=${this.busy}
              minWidth="12rem"
            ></pop-select>

            ${this.tags.length > 0
              ? html`
                  <div class="form-field">
                    <label class="form-label">Étiquettes</label>
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
                Annuler
              </sonic-button>
              <sonic-button
                type="primary"
                ?disabled=${this.busy}
                @click=${this.onSubmit}
              >
                Enregistrer
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
    "todo-edit-page": TodoEditPage;
  }
}
