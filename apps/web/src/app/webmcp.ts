import {getIdbTodoStore} from "./api/store-idb";
import type {TodoStatusFilter} from "./api/types";

/**
 * WebMCP (https://github.com/webmachinelearning/webmcp): read-only tools over the
 * tasks stored in this browser. No-op when the browser has no model context.
 */
type ModelContextTool = {
  name: string;
  description: string;
  inputSchema: Record<string, unknown>;
  annotations?: {readOnlyHint?: boolean};
  execute: (args: Record<string, unknown>) => Promise<{content: {type: "text"; text: string}[]}>;
};

type ModelContext = {registerTool: (tool: ModelContextTool) => unknown};

const asText = (value: unknown) => ({
  content: [{type: "text" as const, text: JSON.stringify(value)}],
});

export function registerWebMcpTools(): void {
  const host = (document as {modelContext?: ModelContext}).modelContext
    ?? (navigator as {modelContext?: ModelContext}).modelContext;
  if (typeof host?.registerTool !== "function") return;

  const store = getIdbTodoStore();
  const tools: ModelContextTool[] = [
    {
      name: "list_todos",
      description:
        "List tasks stored in this browser (active dataset). Optional text search and status filter.",
      inputSchema: {
        type: "object",
        properties: {
          q: {type: "string", description: "Text to search in task names"},
          status: {type: "string", enum: ["active", "done", "archived", "all"]},
          limit: {type: "integer", minimum: 1, maximum: 200},
        },
      },
      annotations: {readOnlyHint: true},
      execute: async ({q, status, limit}) =>
        asText(
          await store.listTodos({
            q: typeof q === "string" ? q : null,
            status: (status as TodoStatusFilter | undefined) ?? "active",
            limit: typeof limit === "number" ? limit : 50,
          }),
        ),
    },
    {
      name: "list_tags",
      description: "List tags (name, color) defined in this browser's active dataset.",
      inputSchema: {type: "object", properties: {}},
      annotations: {readOnlyHint: true},
      execute: async () => asText(await store.listTags()),
    },
    {
      name: "list_datasets",
      description: "List local task datasets in this browser and which one is active.",
      inputSchema: {type: "object", properties: {}},
      annotations: {readOnlyHint: true},
      execute: async () => asText(await store.listDatasets()),
    },
  ];

  for (const tool of tools) {
    try {
      host.registerTool(tool);
    } catch {
      /* already registered or unsupported shape */
    }
  }
}
