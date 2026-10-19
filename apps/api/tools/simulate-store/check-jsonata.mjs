#!/usr/bin/env node
/**
 * Vérifie la syntaxe d'expressions JSONata. Entrée (stdin) : {"items":[{"path":"/data/stores/jeu/reducer","expr":"…"}]}
 * Sortie : {"errors":[{"path","message","position"}]}
 */
import {readFileSync} from "node:fs";
import jsonata from "jsonata";

const {items = []} = JSON.parse(readFileSync(0, "utf8"));
const errors = [];
for (const {path, expr} of items.slice(0, 200)) {
  if (typeof expr !== "string") continue;
  try {
    jsonata(expr);
  } catch (e) {
    errors.push({path, message: String(e.message ?? e).slice(0, 300), position: e.position ?? null, token: e.token ?? null});
  }
}
console.log(JSON.stringify({errors}));
