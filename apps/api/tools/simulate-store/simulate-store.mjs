#!/usr/bin/env node
/**
 * Simule le réducteur JSONata d'un store d'artefact sans navigateur.
 *
 * Usage : node scripts/simulate-store.mjs requete.json   (ou JSON sur stdin)
 * Requête :
 *   {
 *     "document": {…artifacts/1…}  | "documentFile": "chemin.json",
 *     "store": "jeu",                                  // data.stores.<nom> ou <sonic-store id>
 *     "actions": [ {"type":"down"}, {"type":"tick","repeat":300,"payload":{…}} ],
 *     "until": "phase = 'over'",                        // JSONata évalué sur l'état (optionnel)
 *     "maxSteps": 5000,
 *     "snapshots": ["phase","lives"],                   // chemins gardés à chaque étape (optionnel)
 *     "tickMs": 33                                      // si présent, ajoute t = pas × tickMs au payload des "tick"
 *   }
 * Sortie JSON : { ok, steps, finalState, snapshots?, metrics:{msPerStep, maxMsStep, stateBytes}, errors[] }
 *
 * Limite : JSONata + `$rand(seed)` ; toute autre fonction enregistrée par le viewer est à
 * ajouter ici, sinon l'erreur « Attempted to invoke a non-function » est remontée.
 */
import {readFileSync} from "node:fs";
import jsonata from "jsonata";

const arg = process.argv[2];
const req = JSON.parse(readFileSync(arg ?? 0, "utf8"));
const doc = req.document ?? JSON.parse(readFileSync(req.documentFile, "utf8"));

function findStore(name) {
  const def = doc?.data?.stores?.[name];
  if (def) return {initial: def.initial ?? {}, reducer: def.reducer ?? "$state"};
  let found = null;
  const walk = (n) => {
    if (!n || found) return;
    if (n.tagName === "sonic-store" && n.attributes?.id === name) {
      const a = n.attributes;
      found = {initial: a.initial ? JSON.parse(a.initial) : {}, reducer: a.reducer ?? "$state"};
    }
    (n.nodes ?? []).forEach(walk);
  };
  (doc.views ?? []).forEach((v) => walk(v.root));
  return found;
}

const out = {ok: true, steps: 0, errors: []};
const store = findStore(req.store);
if (!store) {
  console.log(JSON.stringify({ok: false, errors: [`store « ${req.store} » introuvable`]}));
  process.exit(1);
}

let expr;
try {
  expr = jsonata(store.reducer);
} catch (e) {
  console.log(JSON.stringify({ok: false, errors: [`réducteur : ${e.message} (position ${e.position ?? "?"})`]}));
  process.exit(1);
}
// Fonctions enregistrées par le viewer (Concorde) en plus de JSONata.
expr.registerFunction("rand", (seed) => {
  const n = (Math.imul(seed | 0, 1664525) + 1013904223) >>> 0;
  return {value: n / 4294967296, seed: n};
});
const until = req.until ? jsonata(req.until) : null;
const maxSteps = Math.min(Number(req.maxSteps ?? 5000), 50000);
const snaps = [];
const getPath = (o, p) => p.split(".").reduce((x, k) => (x == null ? x : x[k]), o);

let state = store.initial;
let total = 0;
let maxMs = 0;
let step = 0;
outer: for (const a of req.actions ?? []) {
  const n = Math.max(1, Number(a.repeat ?? 1));
  for (let i = 0; i < n; i++) {
    if (step >= maxSteps) break outer;
    const action = {type: a.type, payload: a.payload ?? {}};
    if (a.type === "tick" && req.tickMs) action.payload = {...action.payload, t: step * req.tickMs};
    const t0 = performance.now();
    try {
      const next = await expr.evaluate({}, {state, action});
      state = next ?? state;
    } catch (e) {
      out.ok = false;
      out.errors.push(`étape ${step} (${a.type}) : ${e.message}`);
      break outer;
    }
    const dt = performance.now() - t0;
    total += dt;
    maxMs = Math.max(maxMs, dt);
    step++;
    if (req.snapshots) snaps.push(Object.fromEntries(req.snapshots.map((p) => [p, getPath(state, p)])));
    if (until && (await until.evaluate(state)) === true) break outer;
  }
}
out.steps = step;
out.finalState = state;
if (req.snapshots) out.snapshots = snaps.length > 200 ? [...snaps.filter((_, i) => i % Math.ceil(snaps.length / 200) === 0), snaps.at(-1)] : snaps;
out.metrics = {
  msPerStep: step ? +(total / step).toFixed(3) : 0,
  maxMsStep: +maxMs.toFixed(3),
  stateBytes: Buffer.byteLength(JSON.stringify(state)),
};
console.log(JSON.stringify(out));
process.exit(out.ok ? 0 : 1);
