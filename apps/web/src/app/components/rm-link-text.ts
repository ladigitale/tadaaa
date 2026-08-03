import {html} from "lit";
import {unsafeHTML} from "lit/directives/unsafe-html.js";
import type {TemplateResult} from "lit";
import {
  buildDetectorUrl,
  getDetectorRegexp,
  loadAppSettings,
  type LinkDetector,
} from "../settings";

const LINK_CLASS =
  "rm-issue-link text-blue-600 underline underline-offset-2 hover:text-blue-800";

export function escapeHtml(text: string): string {
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

type TextPart =
  | {type: "text"; value: string}
  | {type: "link"; token: string; href: string};

function splitWithDetectors(
  text: string,
  detectors: LinkDetector[],
): TextPart[] {
  let parts: TextPart[] = [{type: "text", value: text}];

  for (const detector of detectors) {
    const re = getDetectorRegexp(detector);
    if (!re) continue;
    const next: TextPart[] = [];
    for (const part of parts) {
      if (part.type !== "text") {
        next.push(part);
        continue;
      }
      let lastIndex = 0;
      const value = part.value;
      re.lastIndex = 0;
      for (const match of value.matchAll(re)) {
        const index = match.index ?? 0;
        if (index > lastIndex) {
          next.push({type: "text", value: value.slice(lastIndex, index)});
        }
        const token = match[0];
        const id = match[1] || token.replace(/\D/g, "");
        if (id) {
          next.push({
            type: "link",
            token,
            href: buildDetectorUrl(detector, id),
          });
        } else {
          next.push({type: "text", value: token});
        }
        lastIndex = index + token.length;
      }
      if (lastIndex < value.length) {
        next.push({type: "text", value: value.slice(lastIndex)});
      }
    }
    parts = next;
  }

  return parts;
}

function linkifyPlainText(text: string): string {
  return splitWithDetectors(text, loadAppSettings().linkDetectors)
    .map((part) => {
      if (part.type === "text") return escapeHtml(part.value);
      const href = escapeHtml(part.href);
      const label = escapeHtml(part.token);
      return `<a href="${href}" target="_blank" rel="noopener noreferrer" class="${LINK_CLASS}">${label}</a>`;
    })
    .join("");
}

/**
 * Inline Markdown (safe subset) then link detectors on remaining text.
 * Supports: **bold**, *italic*, _italic_, `code`, [label](url).
 */
function renderInlineMarkdown(text: string): string {
  const tokens: string[] = [];
  const stash = (html: string): string => {
    const key = `\u0000${tokens.length}\u0000`;
    tokens.push(html);
    return key;
  };

  let out = text;
  out = out.replace(/`([^`\n]+)`/g, (_, code: string) =>
    stash(`<code>${escapeHtml(code)}</code>`),
  );
  out = out.replace(
    /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
    (_, label: string, href: string) =>
      stash(
        `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" class="${LINK_CLASS}">${escapeHtml(label)}</a>`,
      ),
  );
  out = out.replace(/\*\*([^*]+)\*\*/g, (_, body: string) =>
    stash(`<strong>${linkifyPlainText(body)}</strong>`),
  );
  out = out.replace(/(^|[^*])\*([^*\n]+)\*/g, (_, prefix: string, body: string) =>
    `${prefix}${stash(`<em>${linkifyPlainText(body)}</em>`)}`,
  );
  out = out.replace(/(^|[^_])_([^_\n]+)_/g, (_, prefix: string, body: string) =>
    `${prefix}${stash(`<em>${linkifyPlainText(body)}</em>`)}`,
  );

  out = linkifyPlainText(out);
  return out.replace(/\u0000(\d+)\u0000/g, (_, i: string) => tokens[Number(i)] ?? "");
}

function isUnorderedItem(line: string): boolean {
  return /^[-*+]\s+/.test(line);
}

function isOrderedItem(line: string): boolean {
  return /^\d+\.\s+/.test(line);
}

/** Full description Markdown → safe HTML (paragraphs + lists + inline). */
export function richTextToHtml(text: string): string {
  const normalized = text.replace(/\r\n/g, "\n").trim();
  if (!normalized) return "";

  const blocks = normalized.split(/\n{2,}/);
  const htmlParts: string[] = [];

  for (const block of blocks) {
    const lines = block.split("\n");
    if (lines.every((line) => isUnorderedItem(line))) {
      htmlParts.push(
        `<ul>${lines
          .map(
            (line) =>
              `<li>${renderInlineMarkdown(line.replace(/^[-*+]\s+/, ""))}</li>`,
          )
          .join("")}</ul>`,
      );
      continue;
    }
    if (lines.every((line) => isOrderedItem(line))) {
      htmlParts.push(
        `<ol>${lines
          .map(
            (line) =>
              `<li>${renderInlineMarkdown(line.replace(/^\d+\.\s+/, ""))}</li>`,
          )
          .join("")}</ol>`,
      );
      continue;
    }
    htmlParts.push(
      `<p>${lines.map((line) => renderInlineMarkdown(line)).join("<br>")}</p>`,
    );
  }

  return htmlParts.join("");
}

/** HTML sûr pour l’attribut `label` de sonic-checkbox (liens uniquement). */
export function rmLinksLabelHtml(text: string): string {
  return linkifyPlainText(text);
}

/** Template Lit : jetons détecteurs → liens (titres, etc.). */
export function rmLinksTemplate(text: string): TemplateResult {
  return html`${splitWithDetectors(text, loadAppSettings().linkDetectors).map(
    (part) => {
      if (part.type === "text") return part.value;
      return html`<a
        class=${LINK_CLASS}
        href=${part.href}
        target="_blank"
        rel="noopener noreferrer"
        @click=${(event: Event) => event.stopPropagation()}
        >${part.token}</a
      >`;
    },
  )}`;
}

/** Template Lit : Markdown + détecteurs pour les descriptions. */
export function richTextTemplate(text: string): TemplateResult {
  return html`<div
    @click=${(event: Event) => {
      const target = event.target as HTMLElement | null;
      if (target?.closest?.("a")) event.stopPropagation();
    }}
  >
    ${unsafeHTML(richTextToHtml(text))}
  </div>`;
}
