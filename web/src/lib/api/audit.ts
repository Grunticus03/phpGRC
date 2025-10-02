import { apiGet } from "../api";

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
    const json = await apiGet<unknown>("/api/audit/categories", undefined, signal);

    const arr =
      (Array.isArray(json) ? (json as unknown[]) : null) ??
      readArrayProp(json, "_categories") ??
      readArrayProp(json, "categories") ??
      [];

    return arr.filter((x): x is string => typeof x === "string");
  } catch {
    return [];
  }
}
