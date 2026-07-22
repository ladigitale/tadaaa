import type {FieldVersions} from "../api/types";

export function nowIso(): string {
  return new Date().toISOString();
}

export function stampAllFields(
  fields: string[],
  at: string = nowIso(),
): FieldVersions {
  const versions: FieldVersions = {};
  for (const field of fields) {
    versions[field] = at;
  }
  return versions;
}

export function mergeFieldVersions(
  server: FieldVersions | undefined,
  incoming: FieldVersions | undefined,
  incomingFields: Record<string, unknown>,
  applyField: (field: string, value: unknown) => void,
): FieldVersions {
  const versions: FieldVersions = {...(server ?? {})};
  const clientVersions = incoming ?? {};

  for (const [field, value] of Object.entries(incomingFields)) {
    const clientAt = clientVersions[field];
    if (!clientAt) continue;

    const serverAt = versions[field];
    if (!serverAt || clientAt >= serverAt) {
      applyField(field, value);
      versions[field] = clientAt;
    }
  }

  return versions;
}

export function maxFieldVersion(versions: FieldVersions | undefined): string | null {
  if (!versions) return null;
  let max: string | null = null;
  for (const value of Object.values(versions)) {
    if (!max || value > max) max = value;
  }
  return max;
}

export const TODO_SYNC_FIELDS = [
  "text",
  "description",
  "done",
  "archived",
  "priority",
  "tagIds",
  "parentId",
] as const;

export const TAG_SYNC_FIELDS = ["name", "color"] as const;
