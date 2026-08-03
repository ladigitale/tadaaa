import {execSync} from "node:child_process";
import {spawn} from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import {fileURLToPath} from "node:url";
import type {Connect, Plugin} from "vite";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(__dirname, "..");
const starterRoot = path.join(projectRoot, "src/starter");

const OPEN_PREFIX = "/__starter/open";

export function isEditorAvailable(): boolean {
  const editor = process.env.STARTER_EDITOR ?? "cursor";
  try {
    execSync(`${editor} --version`, {stdio: "pipe", timeout: 5000});
    return true;
  } catch {
    return false;
  }
}

function isPathUnderStarter(relPath: string): boolean {
  const normalized = path.normalize(relPath).replace(/\\/g, "/");
  if (normalized.startsWith("..") || path.isAbsolute(normalized)) return false;
  const abs = path.resolve(projectRoot, normalized);
  const starterAbs = path.resolve(starterRoot);
  return abs.startsWith(starterAbs + path.sep) || abs === starterAbs;
}

function openInEditor(absPath: string, line: number, column: number): void {
  const editor = process.env.STARTER_EDITOR ?? "cursor";
  const goto = `${absPath}:${line}:${column}`;
  const args = process.env.STARTER_EDITOR_ARGS
    ? [...process.env.STARTER_EDITOR_ARGS.split(/\s+/), goto, projectRoot]
    : ["-r", "-g", goto, projectRoot];

  const child = spawn(editor, args, {
    detached: true,
    stdio: "ignore",
  });
  child.unref();
}

/**
 * Dev middleware: open starter source files in Cursor (`cursor -r -g`).
 * Links are shown only when the editor CLI is detected at dev server start.
 */
export function starterOpenInEditorPlugin(): Plugin {
  if (!fs.existsSync(starterRoot)) {
    return {name: "starter-open-in-editor-skipped"};
  }

  let editorAvailable = false;

  const middleware: Connect.NextHandleFunction = (req, res, next) => {
    if (!req.url || req.method !== "GET" || !req.url.startsWith(OPEN_PREFIX)) {
      return next();
    }

    if (!editorAvailable) {
      res.statusCode = 503;
      res.setHeader("Content-Type", "text/plain; charset=utf-8");
      res.end("Editor not available");
      return;
    }

    try {
      const url = new URL(req.url, "http://localhost");
      const relPath = url.searchParams.get("path") ?? "";
      const line = Math.max(1, Number(url.searchParams.get("line") ?? "1") || 1);
      const column = Math.max(
        1,
        Number(url.searchParams.get("column") ?? "1") || 1,
      );

      if (!relPath || !isPathUnderStarter(relPath)) {
        res.statusCode = 403;
        res.setHeader("Content-Type", "text/plain; charset=utf-8");
        res.end("Forbidden");
        return;
      }

      const absPath = path.resolve(projectRoot, relPath);
      if (!fs.existsSync(absPath)) {
        res.statusCode = 404;
        res.setHeader("Content-Type", "text/plain; charset=utf-8");
        res.end("Not found");
        return;
      }

      openInEditor(absPath, line, column);
      res.statusCode = 204;
      res.end();
    } catch {
      res.statusCode = 500;
      res.setHeader("Content-Type", "text/plain; charset=utf-8");
      res.end("Error");
    }
  };

  return {
    name: "starter-open-in-editor",
    config() {
      editorAvailable = isEditorAvailable();
      if (!editorAvailable) {
        console.log(
          "[starter] ↗ source links hidden — Cursor CLI not found (Command Palette → Install 'cursor' command)",
        );
      }
      return {
        define: {
          __STARTER_EDITOR_AVAILABLE__: JSON.stringify(editorAvailable),
        },
      };
    },
    configureServer(server) {
      server.middlewares.use(middleware);
    },
  };
}
