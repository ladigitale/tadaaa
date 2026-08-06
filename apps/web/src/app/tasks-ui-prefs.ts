/** Préférences UI tâches (vue, filtres, calendrier) — localStorage, hors sync. */

import type {SortDirection, TodoSortBy} from "./api/types";
import type {TodosFilter} from "./dp";
import type {CalendarMode} from "./utils/calendar";
import {todayDateOnly} from "./utils/dates";

export type TasksViewMode = "list" | "calendar";

export type TasksFilterPrefs = {
  status: TodosFilter["status"];
  tags: string[];
  sort: TodosFilter["sort"];
  sortBy: TodoSortBy;
  sortDir: SortDirection;
};

export type TasksCalendarPrefs = {
  mode: CalendarMode;
  anchor: string;
};

const VIEW_KEY = "tada-tasks-view-mode";
const FILTER_KEY = "tada-tasks-filter";
const CALENDAR_KEY = "tada-tasks-calendar-view";

const DEFAULT_FILTER: TasksFilterPrefs = {
  status: "all",
  tags: [],
  sort: "createdAt:desc",
  sortBy: "createdAt",
  sortDir: "desc",
};

/** Survit aux changements de route même si le storage échoue. */
let rememberedViewMode: TasksViewMode | null = null;

function isViewMode(value: unknown): value is TasksViewMode {
  return value === "list" || value === "calendar";
}

function isStatus(value: unknown): value is TodosFilter["status"] {
  return (
    value === "active" ||
    value === "done" ||
    value === "archived" ||
    value === "all"
  );
}

function isCalendarMode(value: unknown): value is CalendarMode {
  return (
    value === "day" ||
    value === "week" ||
    value === "month" ||
    value === "year"
  );
}

function parseSort(
  sort: string | undefined,
): {sortBy: TodoSortBy; sortDir: SortDirection} | null {
  if (!sort || !sort.includes(":")) return null;
  const [sortBy, sortDir] = sort.split(":") as [TodoSortBy, SortDirection];
  if (
    (sortBy !== "createdAt" &&
      sortBy !== "priority" &&
      sortBy !== "text" &&
      sortBy !== "startAt") ||
    (sortDir !== "asc" && sortDir !== "desc")
  ) {
    return null;
  }
  return {sortBy, sortDir};
}

function normalizeFilterPrefs(raw: Partial<TasksFilterPrefs>): TasksFilterPrefs {
  const tags = Array.isArray(raw.tags)
    ? raw.tags.map(String).filter(Boolean)
    : [];
  const parsed =
    parseSort(
      typeof raw.sort === "string"
        ? raw.sort
        : raw.sortBy && raw.sortDir
          ? `${raw.sortBy}:${raw.sortDir}`
          : undefined,
    ) ?? {sortBy: DEFAULT_FILTER.sortBy, sortDir: DEFAULT_FILTER.sortDir};
  return {
    status: isStatus(raw.status) ? raw.status : DEFAULT_FILTER.status,
    tags,
    sort: `${parsed.sortBy}:${parsed.sortDir}`,
    sortBy: parsed.sortBy,
    sortDir: parsed.sortDir,
  };
}

function readJson<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return null;
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

function writeJson(key: string, value: unknown): void {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* ignore quota / private mode */
  }
}

export const tasksUiPrefs = {
  loadViewMode(): TasksViewMode {
    if (isViewMode(rememberedViewMode)) return rememberedViewMode;
    try {
      const raw = localStorage.getItem(VIEW_KEY);
      if (isViewMode(raw)) {
        rememberedViewMode = raw;
        return raw;
      }
    } catch {
      /* ignore */
    }
    return "list";
  },

  saveViewMode(mode: TasksViewMode): void {
    rememberedViewMode = mode;
    try {
      localStorage.setItem(VIEW_KEY, mode);
    } catch {
      /* ignore */
    }
  },

  loadFilterPrefs(): TasksFilterPrefs {
    const parsed = readJson<Partial<TasksFilterPrefs>>(FILTER_KEY);
    if (!parsed || typeof parsed !== "object") {
      return {...DEFAULT_FILTER, tags: []};
    }
    return normalizeFilterPrefs(parsed);
  },

  saveFilterPrefs(filter: TodosFilter | TasksFilterPrefs): void {
    writeJson(FILTER_KEY, normalizeFilterPrefs(filter));
  },

  /** Champs persistés à fusionner dans le TodosFilter initial. */
  filterDefaults(): Pick<
    TodosFilter,
    "status" | "tags" | "sort" | "sortBy" | "sortDir" | "recursive"
  > {
    const prefs = this.loadFilterPrefs();
    return {
      ...prefs,
      recursive: prefs.tags.length > 0,
    };
  },

  loadCalendarPrefs(): TasksCalendarPrefs {
    const fromLocal = readJson<Partial<TasksCalendarPrefs>>(CALENDAR_KEY);
    if (fromLocal && typeof fromLocal === "object") {
      return {
        mode: isCalendarMode(fromLocal.mode) ? fromLocal.mode : "month",
        anchor:
          typeof fromLocal.anchor === "string" && fromLocal.anchor
            ? fromLocal.anchor
            : todayDateOnly(),
      };
    }
    // Migration : ancienne clé sessionStorage.
    try {
      const raw = sessionStorage.getItem(CALENDAR_KEY);
      if (raw) {
        const parsed = JSON.parse(raw) as Partial<TasksCalendarPrefs>;
        const migrated = {
          mode: isCalendarMode(parsed.mode) ? parsed.mode : "month",
          anchor:
            typeof parsed.anchor === "string" && parsed.anchor
              ? parsed.anchor
              : todayDateOnly(),
        } satisfies TasksCalendarPrefs;
        this.saveCalendarPrefs(migrated);
        sessionStorage.removeItem(CALENDAR_KEY);
        return migrated;
      }
    } catch {
      /* ignore */
    }
    return {mode: "month", anchor: todayDateOnly()};
  },

  saveCalendarPrefs(state: TasksCalendarPrefs): void {
    writeJson(CALENDAR_KEY, {
      mode: isCalendarMode(state.mode) ? state.mode : "month",
      anchor: state.anchor || todayDateOnly(),
    } satisfies TasksCalendarPrefs);
  },
} as const;
