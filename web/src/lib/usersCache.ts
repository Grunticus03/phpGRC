import { getUserRoles, type UserSummary } from "./api/rbac";

export type UserCacheValue = UserSummary | null;

type CacheMap = Map<number, UserCacheValue>;

const cache: CacheMap = new Map();
const inflight: Map<number, Promise<UserCacheValue>> = new Map();

function isValidId(id: number): boolean {
  return Number.isInteger(id) && id > 0;
}

async function fetchUser(id: number): Promise<UserCacheValue> {
  try {
    const res = await getUserRoles(id);
    if (res.ok) {
      cache.set(id, res.user);
      return res.user;
    }
    cache.set(id, null);
    return null;
  } catch {
    // Ignore network/permission errors so callers can retry later.
  }
  return null;
}

async function ensureUser(id: number): Promise<UserCacheValue> {
  if (!isValidId(id)) return null;

  if (cache.has(id)) {
    return cache.get(id) ?? null;
  }

  if (inflight.has(id)) {
    return inflight.get(id) as Promise<UserCacheValue>;
  }

  const promise = fetchUser(id).finally(() => {
    inflight.delete(id);
  });
  inflight.set(id, promise);
  return promise;
}

export function primeUsers(users: Iterable<UserSummary>): void {
  for (const user of users) {
    if (isValidId(user.id)) {
      cache.set(user.id, user);
    }
  }
}

export function getCachedUser(id: number): UserCacheValue | undefined {
  return cache.get(id);
}

export async function loadUsers(ids: Iterable<number>): Promise<Map<number, UserCacheValue>> {
  const unique = new Set<number>();
  for (const raw of ids) {
    const id = Number(raw);
    if (isValidId(id)) {
      unique.add(id);
    }
  }

  await Promise.all(Array.from(unique).map((id) => ensureUser(id)));

  const result = new Map<number, UserCacheValue>();
  for (const id of unique) {
    result.set(id, cache.get(id) ?? null);
  }
  return result;
}

export async function getUser(id: number): Promise<UserCacheValue> {
  return ensureUser(id);
}

export function clearUsersCache(): void {
  cache.clear();
  inflight.clear();
}
