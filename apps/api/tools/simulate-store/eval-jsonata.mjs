#!/usr/bin/env node
/**
 * Évalue des expressions JSONata sur une même donnée (workflows d'artefacts : condition `if` et
 * entrée `input` d'une étape, sortie du workflow).
 * Entrée (stdin) : {"data": {…}, "items": [{"key": "input", "expr": "…"}]}
 * Sortie : {"results": {"<key>": valeur}, "errors": [{"key", "message"}]} — valeur absente = undefined.
 */
import {readFileSync} from "node:fs";
import jsonata from "jsonata";

const {data = {}, items = []} = JSON.parse(readFileSync(0, "utf8"));
const results = {};
const errors = [];
for (const {key, expr} of items.slice(0, 50)) {
  if (typeof key !== "string" || typeof expr !== "string") continue;
  try {
    const value = await jsonata(expr).evaluate(data);
    if (value !== undefined) results[key] = JSON.parse(JSON.stringify(value));
  } catch (e) {
    errors.push({key, message: String(e.message ?? e).slice(0, 300)});
  }
}
console.log(JSON.stringify({results, errors}));
