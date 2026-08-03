/**
 * Resolve Concorde generate-routes whether deps are hoisted (monorepo)
 * or local (Docker prod mount of apps/web only).
 */
import { createRequire } from "module";
import { spawnSync } from "child_process";
import { dirname, join } from "path";

const require = createRequire(import.meta.url);
// package.json is not in exports; vite-config is a stable entry to locate the package root.
const viteConfig = require.resolve("@supersoniks/concorde/vite-config");
const script = join(dirname(viteConfig), "../scripts/generate-routes.js");
const result = spawnSync(process.execPath, [script, ...process.argv.slice(2)], {
  stdio: "inherit",
});
process.exit(result.status ?? 1);
