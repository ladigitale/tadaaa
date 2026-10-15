#!/usr/bin/env node
/**
 * Régénère apps/api/config/artifacts/icons.json : les noms exacts des icônes des
 * bibliothèques de référence de sonic-icon (celles que Concorde charge depuis un CDN).
 * Sert à valider `name` (pas d'icône inventée) et à `find_icons`.
 *
 *   node scripts/build-icon-index.mjs <node_modules>
 *
 * <node_modules> doit contenir iconoir@5.1.4 et heroicons@2.0.4 (les versions que
 * Concorde référence dans icons.ts) :
 *   npm i iconoir@5.1.4 heroicons@2.0.4
 */
import { readdirSync, writeFileSync } from "fs";
import path from "path";
import { fileURLToPath } from "url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const OUT = path.join(root, "apps/api/config/artifacts/icons.json");
const nm = process.argv[2];
if (!nm) {
  console.error("usage : node scripts/build-icon-index.mjs <node_modules>");
  process.exit(1);
}
const names = (dir) => readdirSync(dir).filter((f) => f.endsWith(".svg")).map((f) => f.slice(0, -4)).sort();

const index = {
  libraries: {
    iconoir: {
      version: "5.1.4",
      cdn: "https://cdnjs.cloudflare.com/ajax/libs/iconoir/5.1.4/icons/$name.svg",
      prefixes: [],
      names: names(path.join(nm, "iconoir/icons")),
    },
    heroicons: {
      version: "2.0.4",
      cdn: "https://cdn.jsdelivr.net/npm/heroicons@2.0.4/24/$prefix/$name.svg",
      prefixes: ["outline", "solid"],
      names: names(path.join(nm, "heroicons/24/outline")),
    },
  },
};
writeFileSync(OUT, JSON.stringify(index) + "\n");
console.log(`icons.json : iconoir ${index.libraries.iconoir.names.length}, heroicons ${index.libraries.heroicons.names.length}`);
