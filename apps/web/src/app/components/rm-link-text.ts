import {html} from "lit";
import type {TemplateResult} from "lit";
import {
  buildIssueUrl,
  getIssueTokenRegexp,
  loadAppSettings,
} from "../settings";

const RM_LINK_CLASS =
  "rm-issue-link text-blue-600 underline underline-offset-2 hover:text-blue-800";

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

type TextPart =
  | {type: "text"; value: string}
  | {type: "issue"; token: string; id: string};

function splitIssueParts(text: string): TextPart[] {
  const re = getIssueTokenRegexp();
  const parts: TextPart[] = [];
  let lastIndex = 0;

  for (const match of text.matchAll(re)) {
    const index = match.index ?? 0;
    if (index > lastIndex) {
      parts.push({type: "text", value: text.slice(lastIndex, index)});
    }
    const token = match[0];
    const id = match[1] || token.replace(/\D/g, "");
    if (id) {
      parts.push({type: "issue", token, id});
    } else {
      parts.push({type: "text", value: token});
    }
    lastIndex = index + token.length;
  }

  if (lastIndex < text.length) {
    parts.push({type: "text", value: text.slice(lastIndex)});
  }

  return parts;
}

export function rmIssueUrl(issueId: string): string {
  return buildIssueUrl(issueId, loadAppSettings());
}

/** HTML sûr pour l’attribut `label` de sonic-checkbox (unsafeHTML côté Concorde). */
export function rmLinksLabelHtml(text: string): string {
  return splitIssueParts(text)
    .map((part) => {
      if (part.type === "text") return escapeHtml(part.value);
      const href = escapeHtml(rmIssueUrl(part.id));
      const label = escapeHtml(part.token);
      return `<a href="${href}" target="_blank" rel="noopener noreferrer" class="${RM_LINK_CLASS}">${label}</a>`;
    })
    .join("");
}

/** Template Lit : jetons issue → liens (clic isolé du parent). */
export function rmLinksTemplate(text: string): TemplateResult {
  return html`${splitIssueParts(text).map((part) => {
    if (part.type === "text") return part.value;
    return html`<a
      class=${RM_LINK_CLASS}
      href=${rmIssueUrl(part.id)}
      target="_blank"
      rel="noopener noreferrer"
      @click=${(event: Event) => event.stopPropagation()}
      >${part.token}</a
    >`;
  })}`;
}
