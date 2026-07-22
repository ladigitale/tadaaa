import type {DbSnapshot, Tag, Todo, UpdateTagPatch, UpdateTodoPatch} from "../api/types";
import {
  applyTodoPatch,
  normalizeSnapshot,
  updateTagRecord,
} from "../api/store-logic";
import {
  mergeFieldVersions,
  nowIso,
  stampAllFields,
  TAG_SYNC_FIELDS,
  TODO_SYNC_FIELDS,
} from "./field-versions";
import type {SyncEntity} from "./outbox-types";

export function stampTodoCreate(todo: Todo): Todo {
  const at = nowIso();
  return {
    ...todo,
    fieldVersions: stampAllFields([...TODO_SYNC_FIELDS, "createdAt"], at),
  };
}

export function stampTodoPatch(
  todo: Todo,
  patch: UpdateTodoPatch,
): {todo: Todo; changedFields: string[]} {
  const at = nowIso();
  const versions = {...(todo.fieldVersions ?? {})};
  const changedFields: string[] = [];

  const mark = (field: string) => {
    versions[field] = at;
    changedFields.push(field);
  };

  if (patch.text !== undefined) mark("text");
  if (patch.description !== undefined) mark("description");
  if (patch.done !== undefined) mark("done");
  if (patch.archived !== undefined) mark("archived");
  if (patch.priority !== undefined) mark("priority");
  if (patch.tagIds !== undefined) mark("tagIds");

  const next = applyTodoPatch(todo, patch);
  return {
    todo: {...next, fieldVersions: versions},
    changedFields,
  };
}

export function stampTodoMove(todo: Todo): Todo {
  const at = nowIso();
  return {
    ...todo,
    fieldVersions: {...(todo.fieldVersions ?? {}), parentId: at},
  };
}

export function stampTagCreate(tag: Tag): Tag {
  const at = nowIso();
  return {
    ...tag,
    fieldVersions: stampAllFields([...TAG_SYNC_FIELDS], at),
  };
}

export function stampTagPatch(
  tag: Tag,
  patch: UpdateTagPatch,
  allTags: Tag[],
): {tag: Tag; changedFields: string[]} {
  const at = nowIso();
  const versions = {...(tag.fieldVersions ?? {})};
  const changedFields: string[] = [];

  if (patch.name !== undefined) {
    versions.name = at;
    changedFields.push("name");
  }
  if (patch.color !== undefined) {
    versions.color = at;
    changedFields.push("color");
  }

  const next = updateTagRecord(tag.id, patch, allTags);

  return {
    tag: {...next, fieldVersions: versions},
    changedFields,
  };
}

export function stampTodoDelete(): Record<string, string> {
  return {deletedAt: nowIso()};
}

export function stampTagDelete(): Record<string, string> {
  return {deletedAt: nowIso()};
}

export function buildUpsertPayload(
  entity: SyncEntity,
  record: Todo | Tag,
): Todo | Tag {
  return entity === "todo" ? {...(record as Todo)} : {...(record as Tag)};
}

export function applyRemoteTodo(
  snapshot: DbSnapshot,
  remote: Todo & {deletedAt?: string | null},
): DbSnapshot {
  if (remote.deletedAt) {
    return {
      ...snapshot,
      todos: snapshot.todos.filter((todo) => todo.id !== remote.id),
    };
  }

  const index = snapshot.todos.findIndex((todo) => todo.id === remote.id);
  const incomingFields: Record<string, unknown> = {
    text: remote.text,
    description: remote.description ?? null,
    done: remote.done,
    archived: remote.archived,
    priority: remote.priority,
    tagIds: remote.tagIds,
    parentId: remote.parentId,
  };

  if (index < 0) {
    return {
      ...snapshot,
      todos: [
        ...snapshot.todos,
        {
          id: remote.id,
          text: remote.text,
          description: remote.description,
          done: remote.done,
          archived: remote.archived,
          priority: remote.priority,
          tagIds: remote.tagIds,
          parentId: remote.parentId,
          createdAt: remote.createdAt,
          fieldVersions: remote.fieldVersions ?? {},
        },
      ],
    };
  }

  const current = snapshot.todos[index];
  const versions = mergeFieldVersions(
    current.fieldVersions,
    remote.fieldVersions,
    incomingFields,
    () => {},
  );

  const merged: Todo = {...current};
  mergeFieldVersions(current.fieldVersions, remote.fieldVersions, incomingFields, (field, value) => {
    if (field === "text") merged.text = String(value);
    if (field === "description") {
      const trimmed = typeof value === "string" ? value.trim() : "";
      merged.description = trimmed || undefined;
    }
    if (field === "done") merged.done = Boolean(value);
    if (field === "archived") merged.archived = Boolean(value);
    if (field === "priority") merged.priority = value as Todo["priority"];
    if (field === "tagIds") merged.tagIds = Array.isArray(value) ? value.map(String) : [];
    if (field === "parentId") {
      merged.parentId = typeof value === "string" && value !== "" ? value : null;
    }
  });
  merged.fieldVersions = versions;

  const todos = [...snapshot.todos];
  todos[index] = merged;
  return {...snapshot, todos};
}

export function applyRemoteTag(
  snapshot: DbSnapshot,
  remote: Tag & {deletedAt?: string | null},
): DbSnapshot {
  if (remote.deletedAt) {
    return {
      ...snapshot,
      tags: snapshot.tags.filter((tag) => tag.id !== remote.id),
      todos: snapshot.todos.map((todo) => ({
        ...todo,
        tagIds: todo.tagIds.filter((tagId) => tagId !== remote.id),
      })),
    };
  }

  const index = snapshot.tags.findIndex((tag) => tag.id === remote.id);
  const incomingFields: Record<string, unknown> = {
    name: remote.name,
    color: remote.color,
  };

  if (index < 0) {
    return {
      ...snapshot,
      tags: [...snapshot.tags, {...remote, fieldVersions: remote.fieldVersions ?? {}}],
    };
  }

  const current = snapshot.tags[index];
  const merged: Tag = {...current};
  const versions = mergeFieldVersions(
    current.fieldVersions,
    remote.fieldVersions,
    incomingFields,
    (field, value) => {
      if (field === "name") merged.name = String(value);
      if (field === "color") merged.color = value as Tag["color"];
    },
  );
  merged.fieldVersions = versions;

  const tags = [...snapshot.tags];
  tags[index] = merged;
  return {...snapshot, tags};
}

export function normalizeSnapshotWithVersions(snapshot: DbSnapshot): DbSnapshot {
  return normalizeSnapshot({
    todos: snapshot.todos.map((todo) => ({
      ...todo,
      fieldVersions: todo.fieldVersions ?? {},
    })),
    tags: snapshot.tags.map((tag) => ({
      ...tag,
      fieldVersions: tag.fieldVersions ?? {},
    })),
  });
}
