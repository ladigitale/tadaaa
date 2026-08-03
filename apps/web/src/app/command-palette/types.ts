/** Result kinds shown as grouped sections in the command palette. */
export type CommandItemType = "action" | "page" | "tag" | "task";

export type CommandItem = {
  id: string;
  type: CommandItemType;
  label: string;
  icon: string;
  /** Extra match strings (route path, synonyms, …). */
  keywords?: string[];
  /**
   * When false, item appears only once the user has typed a query.
   * Default: true for action/page, false for tag/task.
   */
  showWhenEmpty?: boolean;
  run: () => void;
};

export type CommandProvider = {
  type: CommandItemType;
  load: () => CommandItem[] | Promise<CommandItem[]>;
};

/** Display order of type groups in the palette. */
export const COMMAND_TYPE_ORDER: CommandItemType[] = [
  "action",
  "page",
  "tag",
  "task",
];
