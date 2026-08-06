/** Side-effect: registers built-in command palette providers. */
import "./providers";

export {collectCommandGroups, registerCommandProvider} from "./registry";
export {warmCommandPaletteCache} from "./providers";
export type {CommandGroup} from "./registry";
export type {CommandItem, CommandItemType, CommandProvider} from "./types";
export {COMMAND_TYPE_ORDER} from "./types";
