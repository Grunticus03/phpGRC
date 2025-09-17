export async function listCategories(signal?: AbortSignal): Promise<string[]> {
  function isStringArray(value: unknown): value is string[] {
    return Array.isArray(value) && value.every((v) => typeof v === "string");
  }

  function readArrayProp(obj: unknown, key: string): string[] | null {
    if (obj && typeof obj === "object") {
      const val = (obj as Record<string, unknown>)[key];
      if (isStringArray(val)) return val;
    }
    return null;
  }

  try {
    const res = await fetch("/api/audit/categories", { signal, credentials: "same-origin" });
    const json: unknown = await res.json().catch(() => null);

    const arr =
      (Array.isArray(json) ? json : null) ??
      readArrayProp(json, "_categories") ??
      readArrayProp(json, "categories") ??
      [];

    return arr.filter((x: unknown): x is string => typeof x === "string");
  } catch {
    return [];
  }
}
