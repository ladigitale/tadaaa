export type EmbedTag = {
  id: string;
  name: string;
  color: string;
};

export type EmbedTodo = {
  id: string;
  text: string;
  done: boolean;
  priority: string;
  tagIds: string[];
  parentId: string | null;
  startAt: string | null;
  endAt: string | null;
  recurrence: string;
  description?: string | null;
};

export type EmbedStats = {
  open: number;
  done: number;
  overdue: number;
  dated: number;
  total: number;
};

export type EmbedFeed = {
  meta: {
    name: string;
    datasetName: string;
    generatedAt: string;
  };
  todos: EmbedTodo[];
  tags: EmbedTag[];
  stats: EmbedStats;
};

export type EmbedFilter = {
  q: string;
  status: "active" | "done" | "all";
  tags: string[];
};

export type EmbedTheme = {
  theme: "light" | "dark" | "auto";
  accent: string;
  font: string;
  radius: string;
  density: "compact" | "comfortable";
};

export type EmbedState = {
  key: string;
  apiBase: string;
  loading: boolean;
  error: string | null;
  feed: EmbedFeed | null;
  filter: EmbedFilter;
  theme: EmbedTheme;
};
