import type {
  CreateTagInput,
  CreateTodoInput,
  DbSnapshot,
  ListTodosParams,
  SortDirection,
  Tag,
  TagColor,
  Todo,
  TodoAncestor,
  TodoPriority,
  TodoSortBy,
  TodoStatusFilter,
  TodosListResponse,
  UpdateTagPatch,
  UpdateTodoPatch,
} from "./types";

export const PRIORITY_WEIGHT: Record<TodoPriority, number> = {
  low: 1,
  medium: 2,
  high: 3,
};

export const TAG_COLORS: TagColor[] = [
  "default",
  "primary",
  "neutral",
  "warning",
  "info",
  "success",
  "danger",
  "contrast",
];

export function createId(prefix: string): string {
  return `${prefix}-${crypto.randomUUID()}`;
}

function matchesSearch(todo: Todo, q: string): boolean {
  const needle = q.trim().toLowerCase();
  if (!needle) return true;
  if (todo.text.toLowerCase().includes(needle)) return true;
  const description = todo.description?.trim().toLowerCase() ?? "";
  return description.includes(needle);
}

function matchesStatus(todo: Todo, status: TodoStatusFilter): boolean {
  switch (status) {
    case "active":
      return !todo.archived && !todo.done;
    case "done":
      return !todo.archived && todo.done;
    case "archived":
      return todo.archived;
    case "all":
      return !todo.archived;
    default:
      return true;
  }
}

function compareTodos(
  a: Todo,
  b: Todo,
  sortBy: TodoSortBy,
  sortDir: SortDirection,
): number {
  let result = 0;

  if (sortBy === "priority") {
    result = PRIORITY_WEIGHT[a.priority] - PRIORITY_WEIGHT[b.priority];
    if (result === 0) {
      result = b.createdAt.localeCompare(a.createdAt);
    }
  } else if (sortBy === "text") {
    result = a.text.localeCompare(b.text, "fr", {sensitivity: "base"});
    if (result === 0) {
      result = a.createdAt.localeCompare(b.createdAt);
    }
  } else {
    result = a.createdAt.localeCompare(b.createdAt);
    if (result === 0) {
      result = PRIORITY_WEIGHT[a.priority] - PRIORITY_WEIGHT[b.priority];
    }
  }

  return sortDir === "asc" ? result : -result;
}

export function countChildren(todos: Todo[], parentId: string): number {
  return todos.filter((todo) => todo.parentId === parentId).length;
}

export function withChildCount(todo: Todo, todos: Todo[]): Todo {
  return {...todo, childCount: countChildren(todos, todo.id)};
}

export function getAncestors(todos: Todo[], todoId: string): TodoAncestor[] {
  const byId = new Map(todos.map((todo) => [todo.id, todo]));
  const ancestors: TodoAncestor[] = [];
  let current = byId.get(todoId);
  const seen = new Set<string>();

  while (current?.parentId) {
    if (seen.has(current.parentId)) break;
    seen.add(current.parentId);
    const parent = byId.get(current.parentId);
    if (!parent) break;
    ancestors.unshift({id: parent.id, text: parent.text});
    current = parent;
  }

  return ancestors;
}

export function collectDescendantIds(
  todos: Todo[],
  rootId: string,
): Set<string> {
  const childrenByParent = new Map<string | null, Todo[]>();
  for (const todo of todos) {
    const key = todo.parentId;
    const list = childrenByParent.get(key) ?? [];
    list.push(todo);
    childrenByParent.set(key, list);
  }

  const result = new Set<string>();
  const stack = [rootId];
  while (stack.length > 0) {
    const id = stack.pop()!;
    const children = childrenByParent.get(id) ?? [];
    for (const child of children) {
      if (result.has(child.id)) continue;
      result.add(child.id);
      stack.push(child.id);
    }
  }
  return result;
}

function scopeTodos(
  todos: Todo[],
  parentId: string | null,
  recursive: boolean,
): Todo[] {
  if (!recursive) {
    return todos.filter((todo) => todo.parentId === parentId);
  }

  if (parentId === null) {
    return [...todos];
  }

  const descendantIds = collectDescendantIds(todos, parentId);
  return todos.filter((todo) => descendantIds.has(todo.id));
}

export function filterTodos(
  todos: Todo[],
  params: ListTodosParams,
  knownTagIds: string[] = [],
): Todo[] {
  const status = (params.status ?? "all") as TodoStatusFilter;
  const sortBy = (params.sortBy ?? "createdAt") as TodoSortBy;
  const sortDir = (params.sortDir ?? "desc") as SortDirection;
  const recursive =
    params.recursive === true || String(params.recursive) === "true";
  const rawParent = params.parentId;
  const parentId =
    rawParent === undefined || rawParent === null || rawParent === ""
      ? null
      : rawParent;

  let requestedTags = (params.tagIds ?? [])
    .map((tagId) => tagId.trim())
    .filter(Boolean)
    .filter((tagId) => tagId !== "*" && tagId !== "all");
  const singleTag = params.tagId?.trim() || "";
  if (singleTag && singleTag !== "*" && singleTag !== "all") {
    requestedTags.push(singleTag);
  }

  const known = knownTagIds.map((tagId) => tagId.trim()).filter(Boolean);
  const selectsEveryKnownTag =
    known.length > 0 &&
    requestedTags.length >= known.length &&
    known.every((tagId) => requestedTags.includes(tagId));
  if (selectsEveryKnownTag) {
    requestedTags = [];
  }

  const q = params.q ?? "";
  const scoped = scopeTodos(todos, parentId, recursive);

  return scoped
    .filter((todo) => matchesStatus(todo, status))
    .filter((todo) => {
      if (requestedTags.length === 0) return true;
      return requestedTags.some((tagId) => todo.tagIds.includes(tagId));
    })
    .filter((todo) => matchesSearch(todo, q))
    .sort((a, b) => compareTodos(a, b, sortBy, sortDir));
}

export function paginateTodos(
  todos: Todo[],
  offset = 0,
  limit = 20,
  allTodos?: Todo[],
): TodosListResponse {
  const safeOffset = Math.max(0, offset);
  const safeLimit = Math.max(1, limit);
  const source = allTodos ?? todos;
  const slice = todos
    .slice(safeOffset, safeOffset + safeLimit)
    .map((todo) => withChildCount(todo, source));

  return {
    data: slice,
    total: todos.length,
    offset: safeOffset,
    limit: safeLimit,
  };
}

export function applyTodoPatch(todo: Todo, patch: UpdateTodoPatch): Todo {
  const next: Todo = {
    ...todo,
    text: patch.text ?? todo.text,
    done: patch.done ?? todo.done,
    archived: patch.archived ?? todo.archived,
    priority: patch.priority ?? todo.priority,
    tagIds: [...todo.tagIds],
    parentId: todo.parentId,
  };

  if (patch.description !== undefined) {
    const trimmed = patch.description?.trim() ?? "";
    next.description = trimmed || undefined;
  }

  if (patch.done === false) {
    next.done = false;
  }

  if (patch.tagIds === null) {
    next.tagIds = [];
  } else if (patch.tagIds) {
    next.tagIds = [
      ...new Set(patch.tagIds.map((value) => value.trim()).filter(Boolean)),
    ];
  }

  return next;
}

export function createTodoRecord(
  input: CreateTodoInput,
  todos: Todo[] = [],
): Todo {
  const text = input.text.trim();
  if (!text) {
    throw new Error("Le nom de la tâche est requis");
  }

  const description = input.description?.trim() || undefined;

  const priority = input.priority ?? "medium";
  if (!PRIORITY_WEIGHT[priority]) {
    throw new Error("Priorité de tâche invalide");
  }

  const parentId =
    input.parentId === undefined || input.parentId === null || input.parentId === ""
      ? null
      : input.parentId;

  if (parentId) {
    const parent = todos.find((todo) => todo.id === parentId);
    if (!parent) throw new Error("Tâche parente introuvable");
    if (parent.archived) {
      throw new Error("Impossible d’utiliser une tâche archivée comme parent");
    }
  }

  return {
    id: createId("todo"),
    text,
    ...(description ? {description} : {}),
    done: false,
    archived: false,
    priority,
    tagIds: [
      ...new Set((input.tagIds ?? []).map((value) => value.trim()).filter(Boolean)),
    ],
    parentId,
    createdAt: new Date().toISOString(),
  };
}

/**
 * Déplace une tâche sous un nouveau parent (null = racine).
 */
export function moveTodoRecord(
  todos: Todo[],
  todoId: string,
  parentId: string | null,
): {todos: Todo[]; todo: Todo} {
  const index = todos.findIndex((todo) => todo.id === todoId);
  if (index < 0) throw new Error("Tâche introuvable");

  const source = todos[index];
  if (source.archived) {
    throw new Error("Impossible de déplacer une tâche archivée");
  }

  if (parentId === todoId) {
    throw new Error("Une tâche ne peut pas devenir sous-tâche d’elle-même");
  }

  if (parentId) {
    const parent = todos.find((todo) => todo.id === parentId);
    if (!parent) throw new Error("Tâche parente introuvable");
    if (parent.archived) {
      throw new Error("Impossible d’utiliser une tâche archivée comme parent");
    }
    const descendants = collectDescendantIds(todos, todoId);
    if (descendants.has(parentId)) {
      throw new Error("Impossible de déplacer une tâche sous l’un de ses descendants");
    }
  }

  const next: Todo = {...source, parentId};
  const nextTodos = todos.map((todo) => (todo.id === todoId ? next : todo));
  return {todos: nextTodos, todo: next};
}

export function createTagRecord(input: CreateTagInput, tags: Tag[]): Tag {
  const name = input.name.trim();
  if (!name) {
    throw new Error("Tag name is required");
  }

  const duplicate = tags.some(
    (tag) => tag.name.toLowerCase() === name.toLowerCase(),
  );
  if (duplicate) {
    throw new Error("Tag already exists");
  }
  if (!TAG_COLORS.includes(input.color)) {
    throw new Error("Tag color is invalid");
  }

  return {
    id: createId("tag"),
    name,
    color: input.color,
  };
}

export function updateTagRecord(
  id: string,
  patch: UpdateTagPatch,
  tags: Tag[],
): Tag {
  const current = tags.find((tag) => tag.id === id);
  if (!current) {
    throw new Error("Tag not found");
  }

  const name =
    patch.name !== undefined ? patch.name.trim() : current.name;
  if (!name) {
    throw new Error("Tag name is required");
  }

  const duplicate = tags.some(
    (tag) =>
      tag.id !== id && tag.name.toLowerCase() === name.toLowerCase(),
  );
  if (duplicate) {
    throw new Error("Tag already exists");
  }

  const color = patch.color ?? current.color;
  if (!TAG_COLORS.includes(color)) {
    throw new Error("Tag color is invalid");
  }

  return {...current, name, color};
}

export function countTodosByTag(todos: Todo[], tagId: string): number {
  return todos.filter((todo) => todo.tagIds.includes(tagId)).length;
}

type LegacySubTodo = {
  id: string;
  text: string;
  done: boolean;
};

type LegacyTodo = Omit<Todo, "tagIds" | "priority" | "parentId"> & {
  tagIds?: string[];
  tagId?: string | null;
  priority?: TodoPriority;
  parentId?: string | null;
  subTodos?: LegacySubTodo[];
};

type LegacyTag = Omit<Tag, "color"> & {
  color?: TagColor;
};

function stripComputed(todo: Todo): Todo {
  const {childCount: _c, ancestors: _a, ...rest} = todo;
  return rest;
}

/**
 * Supprime définitivement les tâches archivées.
 * Les enfants orphelins sont rattachés à la racine.
 */
export function purgeArchivedTodos(todos: Todo[]): {
  todos: Todo[];
  purgedCount: number;
} {
  const archivedIds = new Set(
    todos.filter((todo) => todo.archived).map((todo) => todo.id),
  );
  if (archivedIds.size === 0) {
    return {todos, purgedCount: 0};
  }

  const remaining = todos.filter((todo) => !archivedIds.has(todo.id));
  const keptIds = new Set(remaining.map((todo) => todo.id));

  return {
    purgedCount: archivedIds.size,
    todos: remaining.map((todo) => {
      if (!todo.parentId || keptIds.has(todo.parentId)) return todo;
      return {...todo, parentId: null};
    }),
  };
}

export function normalizeSnapshot(snapshot: DbSnapshot): DbSnapshot {
  const tags: Tag[] = snapshot.tags.map((tag) => {
    const legacyTag = tag as LegacyTag;
    const color =
      legacyTag.color && TAG_COLORS.includes(legacyTag.color)
        ? legacyTag.color
        : "default";
    return {
      id: legacyTag.id,
      name: legacyTag.name,
      color,
    };
  });

  const validTagIds = new Set(tags.map((tag) => tag.id));
  const flattened: Todo[] = [];

  for (const todo of snapshot.todos) {
    const legacyTodo = todo as LegacyTodo;
    const rawTagIds =
      legacyTodo.tagIds && legacyTodo.tagIds.length > 0
        ? legacyTodo.tagIds
        : legacyTodo.tagId
          ? [legacyTodo.tagId]
          : [];
    const tagIds = [
      ...new Set(rawTagIds.map((tagId) => tagId.trim()).filter(Boolean)),
    ].filter((tagId) => validTagIds.has(tagId));
    const priority =
      legacyTodo.priority && PRIORITY_WEIGHT[legacyTodo.priority]
        ? legacyTodo.priority
        : "medium";
    const parentId =
      legacyTodo.parentId === undefined || legacyTodo.parentId === ""
        ? null
        : legacyTodo.parentId ?? null;

    const base: Todo = stripComputed({
      id: legacyTodo.id,
      text: legacyTodo.text,
      ...(typeof (legacyTodo as Todo).description === "string" &&
      (legacyTodo as Todo).description?.trim()
        ? {description: (legacyTodo as Todo).description!.trim()}
        : {}),
      done: legacyTodo.done,
      archived: legacyTodo.archived,
      priority,
      tagIds,
      parentId,
      createdAt: legacyTodo.createdAt,
    });
    flattened.push(base);

    const nested = legacyTodo.subTodos ?? [];
    for (const sub of nested) {
      flattened.push({
        id: sub.id.startsWith("todo-") ? sub.id : `todo-${sub.id}`,
        text: sub.text,
        done: sub.done,
        archived: false,
        priority: "medium",
        tagIds: [],
        parentId: base.id,
        createdAt: legacyTodo.createdAt,
      });
    }
  }

  // Dédupliquer si une migration a déjà aplati + relit un snapshot mixte
  const byId = new Map<string, Todo>();
  for (const todo of flattened) {
    byId.set(todo.id, todo);
  }

  return {tags, todos: [...byId.values()]};
}
