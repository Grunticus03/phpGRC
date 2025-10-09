export type RoleOption = {
  id: string;
  name: string;
};

export type RoleOptionInput =
  | string
  | number
  | {
      id?: string | number | null | undefined;
      name?: string | number | null | undefined;
    };

function stripDiacritics(value: string): string {
  return value.normalize("NFKD").replace(/[\u0300-\u036f]/g, "");
}

function coerceRoleString(value: unknown): string | null {
  if (typeof value === "string") {
    const trimmed = value.trim();
    return trimmed === "" ? null : trimmed;
  }
  if (typeof value === "number" && Number.isFinite(value)) {
    return String(value);
  }
  return null;
}

export function canonicalRoleId(raw: string): string {
  const ascii = stripDiacritics(raw.trim());
  if (ascii === "") return "";
  return ascii
    .replace(/[\s-]+/g, "_")
    .replace(/[^a-zA-Z0-9_]+/g, "")
    .replace(/_+/g, "_")
    .replace(/^_+|_+$/g, "")
    .toLowerCase();
}

export function roleLabelFromId(id: string): string {
  const cleaned = id.trim().replace(/[_-]+/g, " ");
  if (cleaned === "") return "";
  return cleaned
    .split(/\s+/)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

export function roleOptionsFromList(source: RoleOptionInput[] | undefined | null): RoleOption[] {
  if (!Array.isArray(source)) return [];

  const seen = new Set<string>();
  const options: RoleOption[] = [];

  for (const entry of source) {
    let idSource: string | null = null;
    let labelSource: string | null = null;

    if (typeof entry === "string" || typeof entry === "number") {
      const asString = coerceRoleString(entry);
      if (!asString) continue;
      idSource = asString;
      labelSource = asString;
    } else if (entry && typeof entry === "object") {
      const possibleId = coerceRoleString(entry.id);
      const possibleName = coerceRoleString(entry.name);
      idSource = possibleId ?? possibleName;
      labelSource = possibleName ?? possibleId;
    }

    if (idSource === null) {
      continue;
    }

    const id = canonicalRoleId(idSource);
    if (id === "" || seen.has(id)) {
      continue;
    }
    seen.add(id);
    const labelCandidate = labelSource ?? idSource;
    const label = roleLabelFromId(labelCandidate) || roleLabelFromId(id) || labelCandidate;
    options.push({ id, name: label });
  }

  return options.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: "base" }));
}

export function roleIdsFromNames(input: Array<string | number> | undefined | null): string[] {
  if (!Array.isArray(input)) return [];
  const seen = new Set<string>();
  const ids: string[] = [];

  for (const value of input) {
    const candidate = coerceRoleString(value);
    if (!candidate) continue;
    const id = canonicalRoleId(candidate);
    if (id === "" || seen.has(id)) {
      continue;
    }
    seen.add(id);
    ids.push(id);
  }

  return ids;
}

export function roleLabelsFromIds(ids: string[] | undefined | null): string[] {
  if (!Array.isArray(ids)) return [];
  return ids
    .filter((value): value is string => typeof value === "string" && value.trim() !== "")
    .map((value) => roleLabelFromId(value))
    .filter((label) => label !== "");
}
