#!/usr/bin/env node
/**
 * Proxy MCP stdio → Streamable HTTP.
 * Contourne le refus TLS de Cursor sur le certificat auto-signé *.julien.test.
 *
 * Env :
 *   TADA_MCP_URL   (défaut https://tada-api.julien.test/mcp)
 *   TADA_MCP_TOKEN (Bearer PAT tada_…, requis)
 *   NODE_EXTRA_CA_CERTS (recommandé : .ops/certs/tada-api.julien.test.pem)
 */
import https from "node:https";
import http from "node:http";
import {URL} from "node:url";
import readline from "node:readline";

const MCP_URL = process.env.TADA_MCP_URL || "https://tada-api.julien.test/mcp";
const TOKEN = process.env.TADA_MCP_TOKEN;

if (!TOKEN) {
  console.error("[tada-mcp-proxy] TADA_MCP_TOKEN manquant");
  process.exit(1);
}

let sessionId = null;
let protocolVersion = "2025-11-25";

function parseBody(raw, contentType) {
  const ct = (contentType || "").toLowerCase();
  if (ct.includes("text/event-stream")) {
    const lines = raw.split(/\r?\n/);
    const dataLines = [];
    for (const line of lines) {
      if (line.startsWith("data:")) dataLines.push(line.slice(5).trim());
    }
    const joined = dataLines.join("\n").trim();
    return joined ? JSON.parse(joined) : null;
  }
  const trimmed = raw.trim();
  return trimmed ? JSON.parse(trimmed) : null;
}

function httpPost(payload) {
  const url = new URL(MCP_URL);
  const lib = url.protocol === "http:" ? http : https;
  const body = JSON.stringify(payload);
  const headers = {
    "Content-Type": "application/json",
    Accept: "application/json, text/event-stream",
    Authorization: `Bearer ${TOKEN}`,
    "Content-Length": Buffer.byteLength(body),
  };
  if (sessionId) headers["Mcp-Session-Id"] = sessionId;
  if (protocolVersion) headers["Mcp-Protocol-Version"] = protocolVersion;

  return new Promise((resolve, reject) => {
    const req = lib.request(
      {
        protocol: url.protocol,
        hostname: url.hostname,
        port: url.port || (url.protocol === "https:" ? 443 : 80),
        path: `${url.pathname}${url.search}`,
        method: "POST",
        headers,
      },
      (res) => {
        const sid = res.headers["mcp-session-id"];
        if (typeof sid === "string" && sid) sessionId = sid;
        const chunks = [];
        res.on("data", (c) => chunks.push(c));
        res.on("end", () => {
          const raw = Buffer.concat(chunks).toString("utf8");
          try {
            if (res.statusCode && res.statusCode >= 400 && !raw) {
              reject(new Error(`HTTP ${res.statusCode}`));
              return;
            }
            resolve({
              status: res.statusCode || 0,
              json: raw ? parseBody(raw, res.headers["content-type"]) : null,
            });
          } catch (error) {
            reject(error);
          }
        });
      },
    );
    req.on("error", reject);
    req.write(body);
    req.end();
  });
}

function writeMessage(msg) {
  process.stdout.write(`${JSON.stringify(msg)}\n`);
}

async function forward(msg) {
  const isNotification = msg.id === undefined || msg.id === null;
  const {status, json} = await httpPost(msg);

  if (
    msg.method === "initialize" &&
    json?.result?.protocolVersion &&
    typeof json.result.protocolVersion === "string"
  ) {
    protocolVersion = json.result.protocolVersion;
  }

  if (isNotification) {
    return;
  }

  if (json) {
    writeMessage(json);
    return;
  }

  writeMessage({
    jsonrpc: "2.0",
    id: msg.id ?? null,
    error: {code: -32000, message: `Empty MCP response (HTTP ${status})`},
  });
}

const rl = readline.createInterface({input: process.stdin, crlfDelay: Infinity});

/** File d’attente : le sessionId MCP doit être posé avant tools/list. */
const queue = [];
let pumping = false;

async function pump() {
  if (pumping) return;
  pumping = true;
  while (queue.length > 0) {
    const msg = queue.shift();
    try {
      await forward(msg);
    } catch (error) {
      if (msg.id !== undefined && msg.id !== null) {
        writeMessage({
          jsonrpc: "2.0",
          id: msg.id,
          error: {
            code: -32000,
            message: error instanceof Error ? error.message : String(error),
          },
        });
      } else {
        console.error("[tada-mcp-proxy]", error);
      }
    }
  }
  pumping = false;
}

rl.on("line", (line) => {
  const trimmed = line.trim();
  if (!trimmed) return;
  let msg;
  try {
    msg = JSON.parse(trimmed);
  } catch {
    writeMessage({
      jsonrpc: "2.0",
      id: null,
      error: {code: -32700, message: "Parse error"},
    });
    return;
  }
  queue.push(msg);
  void pump();
});
