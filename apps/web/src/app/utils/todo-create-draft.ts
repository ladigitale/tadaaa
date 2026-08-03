import type {TodoCreateForm} from "../dp";

const STORAGE_KEY = "tada-todo-create-draft";

/** Prefill one-shot for `/tache/new` (consumed on mount). */
export function stashTodoCreateDraft(
  draft: Partial<TodoCreateForm>,
): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(draft));
  } catch {
    /* ignore */
  }
}

export function takeTodoCreateDraft(): Partial<TodoCreateForm> | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    sessionStorage.removeItem(STORAGE_KEY);
    const parsed = JSON.parse(raw) as Partial<TodoCreateForm>;
    return parsed && typeof parsed === "object" ? parsed : null;
  } catch {
    return null;
  }
}
