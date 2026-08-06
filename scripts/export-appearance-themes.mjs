#!/usr/bin/env node
/**
 * Export Concorde --sc-* themes from apps/web CSS → apps/api/config/appearance/themes.json
 * Run from monorepo root: node scripts/export-appearance-themes.mjs
 */
import fs from "node:fs";
import path from "node:path";
import {fileURLToPath} from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const webCss = path.join(root, "apps/web/src/css");
const outFile = path.join(root, "apps/api/config/appearance/themes.json");

const appCss = fs.readFileSync(path.join(webCss, "app.css"), "utf8");
const themesCss = fs
  .readFileSync(path.join(webCss, "themes.css"), "utf8")
  .replace(/\/\*[\s\S]*?\*\//g, "");

function extractFontUrl(css) {
  const m = css.match(/@import url\("([^"]+)"\)/);
  return m ? m[1] : null;
}

function parseVars(block) {
  const vars = {};
  let dark = false;
  for (const line of block.split("\n")) {
    const scheme = line.match(/color-scheme:\s*(light|dark)/);
    if (scheme) dark = scheme[1] === "dark";
    const vm = line.match(/(--sc-[a-z0-9-]+)\s*:\s*([^;]+);/i);
    if (vm) vars[vm[1]] = vm[2].trim();
  }
  return {vars, dark};
}

function extractRootBlock(css) {
  const re = /:root\s*\{([^}]+)\}/g;
  let m;
  while ((m = re.exec(css))) {
    if (m[1].includes("--sc-base")) return m[1];
  }
  throw new Error("default :root not found");
}

function extractThemeBlocks(css) {
  const themes = {};
  const re =
    /html\[data-theme="([a-z0-9-]+)"\](?:\s*,\s*html\[data-theme="\1"\]\s+sonic-theme)?\s*\{([^}]+)\}/g;
  let m;
  while ((m = re.exec(css))) {
    const id = m[1];
    if (themes[id]) continue;
    themes[id] = parseVars(m[2]);
  }
  return themes;
}

const labels = {
  "coraline": "Coraline",
  "default": "Default",
  "dark": "Dark",
  "dracula": "Dracula",
  "windows": "Windows 95",
  "nord": "Nord",
  "synthwave": "Synthwave",
  "matcha": "Matcha",
  "terminal": "Terminal",
  "bubblegum": "Bubblegum",
  "cafe": "Café",
  "lavande": "Lavande",
  "crepuscule": "Crépuscule",
  "encre": "Encre"
};

const fontsCssUrl = extractFontUrl(appCss);
const defaultParsed = parseVars(extractRootBlock(appCss));
const themeBlocks = extractThemeBlocks(themesCss);

const themes = {
  default: {
    id: "default",
    label: labels.default,
    dark: defaultParsed.dark,
    vars: defaultParsed.vars,
  },
};

for (const [id, parsed] of Object.entries(themeBlocks)) {
  themes[id] = {
    id,
    label: labels[id] || id,
    dark: parsed.dark,
    vars: parsed.vars,
  };
}

const payload = {
  version: 1,
  defaultThemeId: "default",
  fontsCssUrl,
  shell: {
    contentMaxWidth: "72rem",
    menuPosition: "start",
  },
  icons: {
    library: "custom",
    path: "https://cdn.jsdelivr.net/npm/iconoir@7.10.1/icons/$prefix/$name.svg",
    defaultPrefix: "regular",
  },
  themes,
};

fs.mkdirSync(path.dirname(outFile), {recursive: true});
fs.writeFileSync(outFile, JSON.stringify(payload, null, 2) + "\n");
console.log("Wrote", outFile, "(" + Object.keys(themes).length + " themes)");
