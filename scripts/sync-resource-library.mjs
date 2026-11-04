#!/usr/bin/env node
/**
 * Copie la bibliothèque de ressources de base de Creative-stack dans l'API :
 *
 *   node scripts/sync-resource-library.mjs <dossier creative-stack>
 *
 * Source : <creative-stack>/dist/library.json (généré par `yarn build` ou `node scripts/build-library.mjs`
 * dans Creative-stack). Sortie : apps/api/config/artifacts/library.json, lu par App\Service\BaseResources.
 * À relancer à chaque montée de version de creative-stack dans l'app Artefacts.
 */
import { copyFileSync, existsSync, readFileSync } from "fs";
import path from "path";
import { fileURLToPath } from "url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const [dir] = process.argv.slice(2);
if (!dir) {
  console.error("usage : node scripts/sync-resource-library.mjs <dossier creative-stack>");
  process.exit(1);
}
const src = path.join(dir, "dist/library.json");
if (!existsSync(src)) {
  console.error(`❌ ${src} introuvable : lancer « node scripts/build-library.mjs » dans creative-stack.`);
  process.exit(1);
}
const lib = JSON.parse(readFileSync(src, "utf8"));
if (lib.schema !== "library/1" || !Array.isArray(lib.entries)) {
  console.error("❌ format de library.json inattendu");
  process.exit(1);
}
copyFileSync(src, path.join(root, "apps/api/config/artifacts/library.json"));
console.log(`📚 library.json : ${lib.entries.length} ressource(s) de base (creative-stack ${lib.creativeStackVersion})`);
