export async function listCategories(signal?: AbortSignal): Promise<string[]> {
  try {
    const res = await fetch("/api/audit/categories", { signal, credentials: "same-origin" });
    const json: unknown = await res.json().catch(() => null);

    const arr =
      Array.isArray(json)
        ? json
        : Array.isArray((json as any)?._categories)
        ? (json as any)._categories
        : Array.isArray((json as any)?.categories)
        ? (json as any).categories
        : [];

    return arr.filter((x: unknown): x is string => typeof x === "string");
  } catch {
    return [];
  }
}
