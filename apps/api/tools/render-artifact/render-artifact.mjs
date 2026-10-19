#!/usr/bin/env node
/**
 * Rend un document artefact dans le vrai viewer (Chromium headless) et rapporte ce que
 * l'auteur ne voit pas : erreurs console / page, requêtes en échec, fps, capture d'écran.
 *
 * Prérequis : `yarn build` (dist/) et Chromium (CHROME_PATH, sinon celui de Playwright).
 * Usage : node scripts/render-artifact.mjs requete.json   (ou JSON sur stdin)
 * Requête :
 *   {
 *     "document": {…} | "documentFile": "doc.json",
 *     "view": "home",                         // hash de vue (optionnel)
 *     "viewport": {"width": 900, "height": 700},
 *     "steps": [                              // scénario optionnel, joué dans l'ordre
 *       {"wait": 500},
 *       {"press": "Enter"},                   // touche clavier (nom Playwright)
 *       {"hold": "Space", "ms": 300},
 *       {"click": "sonic-button", "index": 0},// sélecteur CSS traversant les shadow roots
 *       {"shot": "apres-clic"}                // capture intermédiaire (nom)
 *     ],
 *     "settleMs": 800,                        // attente avant la capture finale
 *     "fps": true,                            // mesure les images/s (requestAnimationFrame, 2 s)
 *     "outDir": "/tmp/render"                 // dossier des PNG (défaut : ./render-out)
 *   }
 * Sortie JSON : {ok, errors[], warnings[], failedRequests[], fps?, screenshots[], text}
 * `ok` = aucune erreur console/page.
 */
import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import {chromium} from "playwright-core";

const req = JSON.parse(fs.readFileSync(process.argv[2] ?? 0, "utf8"));
const doc = req.document ?? JSON.parse(fs.readFileSync(req.documentFile, "utf8"));
const DIST = path.resolve(process.env.RENDER_VIEWER_DIST ?? path.join(process.cwd(), "dist"));
const outDir = path.resolve(req.outDir ?? "render-out");
const asRoot = typeof process.getuid === "function" && process.getuid() === 0;
fs.mkdirSync(outDir, {recursive: true});

const MIME = {".js": "text/javascript", ".css": "text/css", ".svg": "image/svg+xml", ".wasm": "application/wasm", ".json": "application/json"};
const server = http.createServer((q, r) => {
  const u = new URL(q.url, "http://x");
  if (u.pathname.startsWith("/api/public/artifacts/")) {
    r.writeHead(200, {"Content-Type": "application/json"});
    if (u.pathname.includes("/collections/")) return r.end('{"member":[]}');
    return r.end(JSON.stringify({id: "preview", slug: "preview", title: doc.title ?? "", description: "", visibility: "public", document: doc,
      scriptAssets: [], version: 1, updatedAt: new Date().toISOString(), collections: [], canWrite: false}));
  }
  let f = path.join(DIST, u.pathname);
  if (!f.startsWith(DIST) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) f = path.join(DIST, "index.html");
  r.writeHead(200, {"Content-Type": MIME[path.extname(f)] ?? "text/html"});
  r.end(fs.readFileSync(f));
});
await new Promise((ok) => server.listen(0, "127.0.0.1", ok));
const base = `http://127.0.0.1:${server.address().port}`;

const IGNORED = /favicon|GL Driver Message|GPU stall|swiftshader/i;
const out = {ok: true, errors: [], warnings: [], failedRequests: [], screenshots: [], text: ""};
const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH || undefined,
  args: [...(asRoot ? ["--no-sandbox"] : []), "--disable-dev-shm-usage", "--use-gl=swiftshader", "--enable-unsafe-swiftshader", "--ignore-gpu-blocklist", "--autoplay-policy=no-user-gesture-required"],
});
try {
  const page = await (await browser.newContext({viewport: req.viewport ?? {width: 900, height: 700}})).newPage();
  page.on("pageerror", (e) => out.errors.push(String(e.stack ?? e).slice(0, 600)));
  page.on("console", (m) => {
    const t = m.text();
    if (IGNORED.test(t)) return;
    if (m.type() === "error") out.errors.push(t.slice(0, 600));
    else if (m.type() === "warning") out.warnings.push(t.slice(0, 300));
  });
  page.on("requestfailed", (rq) => {
    if (!IGNORED.test(rq.url())) out.failedRequests.push(`${rq.url().slice(0, 200)} — ${rq.failure()?.errorText ?? ""}`);
  });
  // Aucun accès réseau externe (SSRF) : CDN d'icônes / polices simulés vides, le reste refusé.
  await page.route((url) => !/^(127\.0\.0\.1|localhost)$/.test(url.hostname) && !["data:", "blob:"].includes(url.protocol), (route) =>
    /cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|fonts\.(googleapis|gstatic)\.com/.test(route.request().url())
      ? route.fulfill({status: 200, body: "", contentType: "text/plain"})
      : route.abort());

  await page.goto(`${base}/preview${req.view ? `#${req.view}` : ""}`, {waitUntil: "load"});
  await page.waitForTimeout(600);

  const deep = `const all = (sel, r = document, a = []) => { r.querySelectorAll("*").forEach((e) => { if (e.matches(sel)) a.push(e); if (e.shadowRoot) all(sel, e.shadowRoot, a); }); return a; };`;
  const shot = async (name) => {
    const file = path.join(outDir, `${name}.png`);
    await page.screenshot({path: file});
    out.screenshots.push(file);
  };
  for (const [i, s] of (req.steps ?? []).entries()) {
    if (s.wait != null) await page.waitForTimeout(Math.min(Number(s.wait), 10000));
    else if (s.press) await page.keyboard.press(s.press);
    else if (s.hold) {
      await page.keyboard.down(s.hold);
      await page.waitForTimeout(Math.min(Number(s.ms ?? 200), 5000));
      await page.keyboard.up(s.hold);
    } else if (s.click) {
      const box = await page.evaluate(new Function("a", deep + `const e = all(a[0])[a[1]]; if (!e) return null; e.scrollIntoView({block: "center"}); const r = e.getBoundingClientRect(); return r.width ? {x: r.x + r.width / 2, y: r.y + r.height / 2} : null;`), [s.click, s.index ?? 0]);
      if (!box) out.errors.push(`étape ${i} : « ${s.click} » introuvable ou invisible`);
      else await page.mouse.click(box.x, box.y);
    } else if (s.shot) await shot(String(s.shot).replace(/[^\w-]/g, "_"));
  }
  await page.waitForTimeout(Math.min(Number(req.settleMs ?? 800), 10000));

  if (req.fps) {
    out.fps = await page.evaluate(() => new Promise((ok) => {
      let n = 0;
      const t0 = performance.now();
      const tick = () => {
        n++;
        if (performance.now() - t0 >= 2000) ok(+((n * 1000) / (performance.now() - t0)).toFixed(1));
        else requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }));
  }
  await shot("final");
  out.text = (await page.evaluate(`(() => { const txt = (n) => { if (n.nodeType === 3) return n.textContent; if (n.nodeType !== 1 && n.nodeType !== 11) return ""; if (n.tagName === "STYLE" || n.tagName === "SCRIPT") return ""; let s = ""; if (n.shadowRoot) s += txt(n.shadowRoot); for (const c of n.childNodes) s += txt(c); return s; }; return txt(document.body).replace(/\\s+/g, " ").trim(); })()`)).slice(0, 1500);
  out.ok = out.errors.length === 0;
} catch (e) {
  out.ok = false;
  out.errors.push(`rendu : ${String(e.message ?? e).slice(0, 400)}`);
} finally {
  await browser.close();
  server.close();
}
console.log(JSON.stringify(out));
process.exit(out.ok ? 0 : 1);
