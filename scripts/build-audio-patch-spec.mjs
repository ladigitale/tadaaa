#!/usr/bin/env node
/**
 * Génère apps/api/config/artifacts/audio-patch.json : les modules du patch audio de la
 * creative-stack (types, paramètres, modulables ou non) pour le contrôle de `sonic-patch`
 * dans le validateur (ArtifactPatchLint). Source unique : patch/modules.ts de creative-stack.
 *
 *   node scripts/build-audio-patch-spec.mjs <dossier creative-stack>
 */
import { readFileSync, writeFileSync } from "fs";
import path from "path";
import { fileURLToPath } from "url";
import { transformSync } from "esbuild";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const OUT = path.join(root, "apps/api/config/artifacts/audio-patch.json");
const [creativeDir] = process.argv.slice(2);
if (!creativeDir) {
  console.error("usage : node scripts/build-audio-patch-spec.mjs <creative-stack>");
  process.exit(1);
}
const file = path.join(creativeDir, "src/addons/audio/patch/modules.ts");
const js = transformSync(readFileSync(file, "utf8"), { loader: "ts", format: "esm" }).code;
const mod = await import(`data:text/javascript;base64,${Buffer.from(js).toString("base64")}`);

const modules = {};
for (const [tag, spec] of Object.entries(mod.MODULES)) {
  const params = {};
  for (const [name, p] of Object.entries(spec.params)) {
    params[name] = {
      ...(p.audio ? { audio: true } : {}),
      ...(p.values ? { values: p.values } : {}),
    };
  }
  modules[tag] = { kind: spec.kind, params };
}
// curve="exp" : vers freq-hz d'un module qui a aussi un detune modulable (appliqué au detune).
// (sonic-filter : detune natif du BiquadFilter, absent de MODULES.)
const exp = Object.entries(modules).filter(([t, m]) => m.params["freq-hz"]?.audio && (m.params.detune?.audio || t === "sonic-filter")).map(([t]) => t);
const out = { generatedFrom: "creative-stack src/addons/audio/patch/modules.ts", modules, expTargets: exp, voiceSources: mod.VOICE_SOURCES, structureTags: mod.STRUCTURE_TAGS };
writeFileSync(OUT, JSON.stringify(out, null, 1) + "\n");
console.log(`${OUT} : ${Object.keys(modules).length} modules, exp : ${exp.join(", ")}`);
