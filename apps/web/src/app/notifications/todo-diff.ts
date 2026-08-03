import type {Todo} from "../api/types";
import type {TodoNotifyChange} from "../notifications/web-notifications";

type RemoteTodo = Todo & {deletedAt?: string | null};

/**
 * Compare local snapshot vs pull delta for check / uncheck / soft-delete.
 * Skips brand-new todos (create) and ids in `ignoreIds` (just pushed locally).
 */
export function detectRemoteTodoNotifyChanges(
  localTodos: Todo[],
  remoteTodos: RemoteTodo[],
  ignoreIds: ReadonlySet<string> = new Set(),
): TodoNotifyChange[] {
  const localById = new Map(localTodos.map((todo) => [todo.id, todo]));
  const changes: TodoNotifyChange[] = [];

  for (const remote of remoteTodos) {
    if (ignoreIds.has(remote.id)) continue;

    const local = localById.get(remote.id);
    const text = (remote.text || local?.text || "").trim();

    if (remote.deletedAt) {
      if (local) {
        changes.push({kind: "deleted", id: remote.id, text});
      }
      continue;
    }

    if (!local) continue;

    if (Boolean(remote.done) !== Boolean(local.done)) {
      changes.push({
        kind: remote.done ? "checked" : "unchecked",
        id: remote.id,
        text,
      });
    }
  }

  return changes;
}
