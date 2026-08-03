import {
  COMMAND_TYPE_ORDER,
  type CommandItem,
  type CommandItemType,
  type CommandProvider,
} from "./types";

const providers: CommandProvider[] = [];

/** Register a source of palette items (call once at module load). */
export function registerCommandProvider(provider: CommandProvider): void {
  providers.push(provider);
}

export function clearCommandProviders(): void {
  providers.length = 0;
}

function defaultShowWhenEmpty(type: CommandItemType): boolean {
  return type === "action" || type === "page";
}

function matchesQuery(item: CommandItem, needle: string): boolean {
  if (!needle) {
    return item.showWhenEmpty ?? defaultShowWhenEmpty(item.type);
  }
  const haystacks = [item.label, ...(item.keywords ?? [])];
  return haystacks.some((part) => part.toLowerCase().includes(needle));
}

export type CommandGroup = {
  type: CommandItemType;
  items: CommandItem[];
};

const LIMIT_PER_TYPE = 24;

/** Load all providers, filter by query, group by type. */
export async function collectCommandGroups(
  query: string,
): Promise<CommandGroup[]> {
  const needle = query.trim().toLowerCase();
  const loaded = await Promise.all(providers.map((p) => Promise.resolve(p.load())));
  const byType = new Map<CommandItemType, CommandItem[]>();

  for (const items of loaded) {
    for (const item of items) {
      if (!matchesQuery(item, needle)) continue;
      const list = byType.get(item.type) ?? [];
      if (list.length >= LIMIT_PER_TYPE) continue;
      list.push(item);
      byType.set(item.type, list);
    }
  }

  return COMMAND_TYPE_ORDER.filter((type) => (byType.get(type)?.length ?? 0) > 0).map(
    (type) => ({
      type,
      items: byType.get(type) ?? [],
    }),
  );
}
