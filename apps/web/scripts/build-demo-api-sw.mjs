import * as esbuild from "esbuild";
import fs from "fs";
import path from "path";
import {fileURLToPath} from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");
const entry = path.join(root, "src/app/api/service-worker.ts");

if (!fs.existsSync(entry)) {
  console.log("⊘ demo-api-sw ignoré (pas de src/app/api/service-worker.ts)");
} else {
  await esbuild.build({
    entryPoints: [entry],
    outfile: path.join(root, "public/demo-api-sw.js"),
    bundle: true,
    format: "iife",
    platform: "browser",
    target: "es2020",
    minify: false,
    sourcemap: true,
    logLevel: "info",
  });
  console.log("✓ public/demo-api-sw.js");
}
