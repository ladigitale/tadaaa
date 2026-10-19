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
 *       {"waitText": "Game over", "timeout": 5000}, // attend un texte affiché (échec si absent)
 *       {"expectText": "Score"},              // assertion : texte affiché (shadow DOM compris)
 *       {"expectNoText": "undefined"},        // assertion : texte absent
 *       {"expect": "sonic-shader", "count": 1}, // assertion : le sélecteur existe (count optionnel)
 *       {"shot": "apres-clic"}                // capture intermédiaire (nom)
 *     ],
 *     "settleMs": 800,                        // attente avant la capture finale
 *     "fps": true,                            // mesure sur 2 s : images/s, durée des images (p50/p95/max), tâches longues, mémoire JS → perf
 *     "outDir": "/tmp/render"                 // dossier des PNG (défaut : ./render-out)
 *   }
 * Sortie JSON : {ok, errors[], warnings[], failedRequests[], assertions[], shaderErrors[], fps?, perf?, screenshots[], text}
 * `ok` = aucune erreur console/page, aucune erreur de shader et aucune assertion en échec.
 * shaderErrors : échecs de compilation/liaison WebGL (journal du compilateur GLSL avec numéros de ligne),
 * relevés directement dans le contexte WebGL, que le viewer les journalise ou non.
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
const out = {ok: true, errors: [], warnings: [], failedRequests: [], assertions: [], shaderErrors: [], screenshots: [], text: ""};
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
  // Compilation GLSL : on enveloppe compileShader / linkProgram pour relever les journaux d'erreur.
  await page.addInitScript(() => {
    window.__gl = [];
    for (const C of [window.WebGLRenderingContext, window.WebGL2RenderingContext]) {
      if (!C) continue;
      const compile = C.prototype.compileShader;
      C.prototype.compileShader = function (sh) {
        compile.call(this, sh);
        try {
          if (!this.getShaderParameter(sh, this.COMPILE_STATUS)) {
            const kind = this.getShaderParameter(sh, this.SHADER_TYPE) === this.FRAGMENT_SHADER ? "fragment" : "vertex";
            window.__gl.push(`${kind} : ${(this.getShaderInfoLog(sh) || "").trim().slice(0, 600)}`);
          }
        } catch {}
      };
      const link = C.prototype.linkProgram;
      C.prototype.linkProgram = function (p) {
        link.call(this, p);
        try {
          if (!this.getProgramParameter(p, this.LINK_STATUS)) window.__gl.push(`link : ${(this.getProgramInfoLog(p) || "").trim().slice(0, 600)}`);
        } catch {}
      };
    }
  });
  // Tâches longues (> 50 ms) : cumul depuis le chargement, pour le profilage.
  await page.addInitScript(() => {
    window.__lt = {count: 0, total: 0};
    try {
      new PerformanceObserver((l) => l.getEntries().forEach((e) => { window.__lt.count++; window.__lt.total += e.duration; })).observe({type: "longtask", buffered: true});
    } catch {}
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
  const TXT = `(() => { const txt = (n) => { if (n.nodeType === 3) return n.textContent; if (n.nodeType !== 1 && n.nodeType !== 11) return ""; if (n.tagName === "STYLE" || n.tagName === "SCRIPT") return ""; let s = ""; if (n.shadowRoot) s += txt(n.shadowRoot); for (const c of n.childNodes) s += txt(c); return s; }; return txt(document.body).replace(/\\s+/g, " ").trim(); })()`;
  const pageText = () => page.evaluate(TXT);
  const fail = (i, s, message) => out.assertions.push({step: i, ...s, message});
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
    } else if (s.waitText) {
      const until = Date.now() + Math.min(Number(s.timeout ?? 5000), 20000);
      let found = false;
      while (Date.now() < until && !(found = (await pageText()).includes(s.waitText))) await page.waitForTimeout(100);
      if (!found) fail(i, s, `texte « ${s.waitText} » jamais affiché`);
    } else if (s.expectText) {
      if (!(await pageText()).includes(s.expectText)) fail(i, s, `texte « ${s.expectText} » absent`);
    } else if (s.expectNoText) {
      if ((await pageText()).includes(s.expectNoText)) fail(i, s, `texte « ${s.expectNoText} » présent`);
    } else if (s.expect) {
      const n = await page.evaluate(new Function("sel", deep + "return all(sel).length"), s.expect);
      if (s.count != null ? n !== s.count : n === 0) fail(i, s, `« ${s.expect} » : ${n} trouvé(s)${s.count != null ? `, ${s.count} attendu(s)` : ""}`);
    } else if (s.shot) await shot(String(s.shot).replace(/[^\w-]/g, "_"));
  }
  await page.waitForTimeout(Math.min(Number(req.settleMs ?? 800), 10000));

  if (req.fps) {
    const lt0 = await page.evaluate(() => ({...window.__lt}));
    const p = await page.evaluate(() => new Promise((ok) => {
      const d = [];
      let last = performance.now();
      const t0 = last;
      const tick = (now) => {
        d.push(now - last);
        last = now;
        if (now - t0 >= 2000) ok(d.slice(1));
        else requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }));
    const sorted = [...p].sort((x, y) => x - y);
    const q = (f) => +(sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * f))] ?? 0).toFixed(1);
    const total = p.reduce((x, y) => x + y, 0);
    const lt1 = await page.evaluate(() => ({...window.__lt, heap: performance.memory?.usedJSHeapSize ?? null}));
    out.fps = total ? +((p.length * 1000) / total).toFixed(1) : 0;
    out.perf = {
      fps: out.fps,
      frameMsP50: q(0.5),
      frameMsP95: q(0.95),
      frameMsMax: +Math.max(0, ...p).toFixed(1),
      droppedFrames: p.filter((x) => x > 33.4).length,
      longTasks: {count: lt1.count - lt0.count, totalMs: Math.round(lt1.total - lt0.total)},
      jsHeapMB: lt1.heap ? +(lt1.heap / 1048576).toFixed(1) : null,
    };
  }
  await shot("final");
  out.text = (await pageText()).slice(0, 1500);
  out.shaderErrors = [...new Set(await page.evaluate(() => window.__gl ?? []))].slice(0, 10);
  out.ok = out.errors.length === 0 && out.assertions.length === 0 && out.shaderErrors.length === 0;
} catch (e) {
  out.ok = false;
  out.errors.push(`rendu : ${String(e.message ?? e).slice(0, 400)}`);
} finally {
  await browser.close();
  server.close();
}
console.log(JSON.stringify(out));
process.exit(out.ok ? 0 : 1);
