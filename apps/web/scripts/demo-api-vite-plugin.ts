import * as esbuild from "esbuild";
import fs from "fs";
import path from "path";
import {fileURLToPath} from "url";
import type {Connect, Plugin} from "vite";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, "..");
const swEntry = path.join(root, "src/app/api/service-worker.ts");

const hasDemoApi = fs.existsSync(swEntry);

async function buildDemoApiServiceWorker(): Promise<void> {
  if (!hasDemoApi) return;
  await esbuild.build({
    entryPoints: [swEntry],
    outfile: path.join(root, "public/demo-api-sw.js"),
    bundle: true,
    format: "iife",
    platform: "browser",
    target: "es2020",
    minify: false,
    sourcemap: true,
    logLevel: "silent",
  });
}

async function readRequestBody(req: Connect.IncomingMessage): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of req) {
    chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
  }
  return Buffer.concat(chunks).toString("utf8");
}

function nodeHeadersToFetchHeaders(
  incoming: Connect.IncomingMessage["headers"],
): Headers {
  const headers = new Headers();
  for (const [key, value] of Object.entries(incoming)) {
    if (value === undefined || key.startsWith(":")) continue;
    if (Array.isArray(value)) {
      for (const part of value) headers.append(key, part);
    } else {
      headers.set(key, value);
    }
  }
  return headers;
}

export function demoApiVitePlugin(): Plugin {
  if (!hasDemoApi) {
    return {name: "tada-api-skipped"};
  }

  const middleware: Connect.NextHandleFunction = async (req, res, next) => {
    if (!req.url) return next();

    const origin = `https://${req.headers.host ?? "localhost"}`;
    const url = new URL(req.url, origin);
    if (!url.pathname.startsWith("/mock-api")) return next();

    const method = (req.method ?? "GET").toUpperCase();
    const body =
      method === "GET" || method === "HEAD"
        ? undefined
        : await readRequestBody(req);

    const {createMockApiHandler} = await import("../src/app/api/router");
    const {getFileTodoStore, seedFileIfEmpty} = await import(
      "../src/app/api/store-file"
    );

    const store = getFileTodoStore();
    await seedFileIfEmpty();
    const handleMockApiRequest = createMockApiHandler(store);

    const request = new Request(url, {
      method,
      headers: nodeHeadersToFetchHeaders(req.headers),
      body: body || undefined,
    });

    try {
      const response = await handleMockApiRequest(request);
      if (!response) return next();

      res.statusCode = response.status;
      response.headers.forEach((value, key) => {
        if (key.toLowerCase() === "content-length") return;
        res.setHeader(key, value);
      });
      res.end(Buffer.from(await response.arrayBuffer()));
    } catch {
      next();
    }
  };

  return {
    name: "tada-api",
    async buildStart() {
      await buildDemoApiServiceWorker();
    },
    configureServer(server) {
      server.middlewares.use(middleware);
    },
    configurePreviewServer(server) {
      server.middlewares.use(middleware);
    },
  };
}
