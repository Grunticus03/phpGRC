export type RoleOption = {
  id: string;
  name: string;
};

export type RoleOptionInput =
  | string
  | {
      id?: string | null | undefined;
      name?: string | null | undefined;
    };

function stripDiacritics(value: string): string {
  return value.normalize("NFKD").replace(/[\u0300-\u036f]/g, "");
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

    if (typeof entry === "string") {
      idSource = entry;
      labelSource = entry;
    } else if (entry && typeof entry === "object") {
      const possibleId = typeof entry.id === "string" ? entry.id : undefined;
      const possibleName = typeof entry.name === "string" ? entry.name : undefined;
      idSource = possibleId && possibleId.trim() !== "" ? possibleId : possibleName ?? null;
      labelSource = possibleName && possibleName.trim() !== "" ? possibleName : possibleId ?? null;
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

export function roleIdsFromNames(input: string[] | undefined | null): string[] {
  if (!Array.isArray(input)) return [];
  const seen = new Set<string>();
  const ids: string[] = [];

  for (const value of input) {
    if (typeof value !== "string") {
      continue;
    }
    const id = canonicalRoleId(value);
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
