/** Public legal identity from GET /api/legal (env-backed, no secrets). */

export type LegalPublicConfig = {
  configured: boolean;
  publisherName: string;
  publisherEmail: string;
  publisherAddress: string;
  siret: string;
  hostName: string;
  hostAddress: string;
  hostContact: string;
  privacyEmail: string;
};

export const EMPTY_LEGAL_CONFIG: LegalPublicConfig = {
  configured: false,
  publisherName: "",
  publisherEmail: "",
  publisherAddress: "",
  siret: "",
  hostName: "",
  hostAddress: "",
  hostContact: "",
  privacyEmail: "",
};

export type LegalDocId = "mentions" | "privacy" | "terms" | "cookies";

export const LEGAL_PATHS: Record<LegalDocId, string> = {
  mentions: "/legal/mentions",
  privacy: "/legal/privacy",
  terms: "/legal/terms",
  cookies: "/legal/cookies",
};

export function legalPath(doc: LegalDocId): string {
  return LEGAL_PATHS[doc];
}
