#!/usr/bin/env node
/**
 * Régénère apps/api/config/artifacts/catalog.json (catalogue des artefacts : MCP
 * get_artifact_catalog, liste blanche des balises du validateur) à partir des
 * paquets réellement chargés par le viewer Artefacts.
 *
 *   node scripts/build-artifact-catalog.mjs <dossier concorde> <dossier creative-stack>
 *
 * Exemple : node scripts/build-artifact-catalog.mjs \
 *   ../artifacts/node_modules/@supersoniks/concorde ../artifacts/node_modules/@supersoniks/creative-stack
 *
 * Sources :
 * - Concorde : src/core/components/functional/sdui/component-catalog.json (généré par Concorde)
 * - creative-stack : src/addons/<id>/manifest.json (tous les addons, y compris désactivés par défaut)
 * - catalogue actuel : safeHtmlTags conservés ; composants absents des deux sources
 *   mais encore définis par Concorde (EXTRA_FROM_PREVIOUS) repris tels quels.
 */
import { existsSync, readdirSync, readFileSync, writeFileSync } from "fs";
import path from "path";
import { fileURLToPath } from "url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const OUT = path.join(root, "apps/api/config/artifacts/catalog.json");

/** Composants de démo ou internes : jamais proposés aux agents. */
const EXCLUDED = new Set(["sonic-example"]);
/** Définis par Concorde mais absents de son catalogue généré (tagName non littéral). */
const EXTRA_FROM_PREVIOUS = ["sonic-radio", "sonic-switch", "sonic-menu-item"];
/** Types de props non pilotables par un attribut SDUI. */
const UNSETTABLE = /=>|HTMLElement|HTMLInputElement|\bNode\b|DirectiveResult|ReturnType|DataProvider|Array<Message>|ToastContent/;
const MAX_DESC = 240;
const MAX_PROP_DESC = 160;

const [concordeDir, creativeDir] = process.argv.slice(2);
if (!concordeDir || !creativeDir) {
  console.error("usage : node scripts/build-artifact-catalog.mjs <concorde> <creative-stack>");
  process.exit(1);
}

const readJson = (file) => JSON.parse(readFileSync(file, "utf8"));
const concordePkg = readJson(path.join(concordeDir, "package.json"));
const creativePkg = readJson(path.join(creativeDir, "package.json"));
const concordeCatalog = readJson(
  path.join(concordeDir, "src/core/components/functional/sdui/component-catalog.json"),
);
const previous = existsSync(OUT) ? readJson(OUT) : {};
const resolveAlias = readTypeAliases(concordeDir);

function shorten(text, max) {
  if (typeof text !== "string") return undefined;
  const flat = text.replace(/\s+/g, " ").trim();
  if (!flat) return undefined;
  if (flat.length <= max) return flat;
  const cut = flat.slice(0, max);
  const sentence = cut.lastIndexOf(". ");
  return (sentence > max / 2 ? cut.slice(0, sentence + 1) : cut.replace(/\s+\S*$/, "") + "…");
}

/**
 * Valeurs des alias de types de Concorde (`export type ButtonType = "default" | …`),
 * pour annoncer `values` sur les props typées par un alias (status, type, size…).
 */
function readTypeAliases(dir) {
  const files = readdirSync(path.join(dir, "src/core"), { recursive: true })
    .map(String)
    .filter((f) => f.endsWith(".ts") && !f.endsWith(".spec.ts"));
  const source = files.map((f) => readFileSync(path.join(dir, "src/core", f), "utf8")).join("\n");
  const raw = new Map();
  for (const m of source.matchAll(/export type (\w+)\s*=\s*([^;]+);/g)) raw.set(m[1], m[2]);
  const consts = new Map();
  for (const m of source.matchAll(/(?:export )?const (\w+)\s*=\s*(\[[^\]]*\]|\{[^}]*\})/g)) consts.set(m[1], m[2]);
  const literals = (text) => [...text.matchAll(/"([^"]*)"/g)].map((x) => x[1]);
  const cache = new Map();
  const resolve = (name, depth = 0) => {
    if (cache.has(name)) return cache.get(name);
    const def = raw.get(name)?.trim();
    if (!def || depth > 5) return undefined;
    let values;
    const keyOf = def.match(/^keyof typeof (\w+)$/);
    const itemOf = def.match(/^\(typeof (\w+)\)\[number\]$/);
    if (keyOf && consts.get(keyOf[1])?.startsWith("{")) {
      values = [...consts.get(keyOf[1]).matchAll(/^\s*"?([\w-]+)"?\s*:/gm)].map((x) => x[1]);
    } else if (itemOf && consts.get(itemOf[1])?.startsWith("[")) {
      values = literals(consts.get(itemOf[1]));
    } else {
      values = [];
      for (const part of def.split("|").map((p) => p.trim()).filter(Boolean)) {
        if (/^"[^"]*"$/.test(part)) values.push(part.slice(1, -1));
        else if (part === "null" || part === "undefined") continue;
        else if (/^\w+$/.test(part) && resolve(part, depth + 1)) values.push(...resolve(part, depth + 1));
        else return undefined;
      }
    }
    const clean = [...new Set(values)].filter((v) => v !== "");
    const result = clean.length >= 2 ? clean : undefined;
    cache.set(name, result);
    return result;
  };
  return resolve;
}

function aliasValues(rawType, resolveAlias) {
  const parts = String(rawType ?? "")
    .split("|")
    .map((p) => p.trim())
    .filter((p) => p && p !== "undefined" && p !== "null");
  if (parts.length !== 1 || !/^[A-Z]\w*$/.test(parts[0])) return undefined;
  return resolveAlias(parts[0]);
}

function normalizeType(raw, values) {
  const t = String(raw ?? "").replace(/\|\s*(undefined|null)\b/g, "").trim();
  if (values?.length) return "string";
  if (/^boolean\b/.test(t) || t === "boolean") return "boolean";
  if (/^number\b/.test(t)) return "number";
  if (/\[\]$|^Array</.test(t)) return "array";
  if (/^(object|Record<)/.test(t)) return "object";
  return "string";
}

function concordeComponent(c) {
  const props = {};
  for (const p of c.props ?? []) {
    if (UNSETTABLE.test(String(p.type ?? ""))) continue;
    const key = p.attribute || p.name;
    if (!key || props[key]) continue;
    const values = p.values?.length ? p.values : aliasValues(p.type, resolveAlias);
    const entry = { type: normalizeType(p.type, values) };
    if (values?.length) entry.values = values;
    if (p.default !== undefined && p.default !== "" && p.default !== "undefined") entry.default = p.default;
    const description = shorten(p.description, MAX_PROP_DESC);
    if (description) entry.description = description;
    props[key] = entry;
  }
  const out = { name: c.tag, props };
  const description = shorten(c.description, MAX_DESC);
  if (description) out.description = description;
  return out;
}

const components = new Map();
for (const c of concordeCatalog.components ?? []) {
  if (!c.tag || EXCLUDED.has(c.tag)) continue;
  components.set(c.tag, concordeComponent(c));
}

const previousByName = new Map((previous.components ?? []).map((c) => [c.name, c]));
for (const name of EXTRA_FROM_PREVIOUS) {
  if (!components.has(name) && previousByName.has(name)) components.set(name, previousByName.get(name));
}

const addonsDir = path.join(creativeDir, "src/addons");
const addons = [];
const addonNotes = [];
for (const dir of readdirSync(addonsDir, { withFileTypes: true }).filter((d) => d.isDirectory())) {
  const file = path.join(addonsDir, dir.name, "manifest.json");
  if (!existsSync(file)) continue;
  const manifest = readJson(file);
  addons.push(manifest.id);
  addonNotes.push(
    `${manifest.id} (${(manifest.components ?? []).map((c) => c.name).join(", ")})` +
      (manifest.requires?.length ? ` [requires: ${manifest.requires.join(", ")}]` : ""),
  );
  for (const c of manifest.components ?? []) {
    if (components.has(c.name)) {
      console.error(`❌ ${c.name} défini à la fois par Concorde et par l'addon ${manifest.id}`);
      process.exit(1);
    }
    const { name, props = {}, description, example } = c;
    const entry = { name, props, addon: manifest.id };
    if (description) entry.description = description;
    if (example) entry.example = example;
    components.set(name, entry);
  }
}

const sorted = [...components.values()].sort((a, b) => a.name.localeCompare(b.name));
const catalog = {
  concordeVersion: concordePkg.version,
  creativeStackVersion: creativePkg.version,
  generatedFrom: `@supersoniks/concorde ${concordePkg.version} component-catalog.json + @supersoniks/creative-stack ${creativePkg.version} manifests (${addons.sort().join(", ")})`,
  components: sorted,
  safeHtmlTags: previous.safeHtmlTags ?? [],
  notes:
    "SDUINode uses tagName+attributes+nodes only. js/css/markup/innerHTML/prefix/suffix rejected for XSS. " +
    "Optional top-level scripts: IDs from scripts-catalog.json only (CDN jsDelivr pinned). " +
    "Concorde components: see props (values = allowed values). " +
    `creative-stack addons: ${addonNotes.sort().join("; ")}. ` +
    "Capabilities required for camera / microphone / MIDI / screen.",
};

writeFileSync(OUT, JSON.stringify(catalog, null, 2) + "\n");
const lost = [...previousByName.keys()].filter((n) => !components.has(n));
console.log(
  `📚 catalog.json : Concorde ${concordePkg.version}, creative-stack ${creativePkg.version}, ${sorted.length} composants` +
    (lost.length ? ` — retirés : ${lost.join(", ")}` : ""),
);
