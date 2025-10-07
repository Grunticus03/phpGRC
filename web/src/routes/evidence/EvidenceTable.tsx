import { useEffect, useMemo, useState } from "react";
import type { Evidence } from "../../lib/api/evidence";
import { formatBytes, formatDate, type TimeFormat } from "../../lib/format";
import { getCachedUser, loadUsers, type UserCacheValue } from "../../lib/usersCache";

type Props = {
  items: Evidence[];
  fetchState: "idle" | "loading" | "error" | "ok";
  timeFormat: TimeFormat;
};

type OwnerMap = Map<number, UserCacheValue>;

function computeOwnerIds(items: Evidence[]): number[] {
  const unique = new Set<number>();
  for (const item of items) {
    const id = Number(item.owner_id);
    if (Number.isFinite(id) && id > 0) {
      unique.add(id);
    }
  }
  return Array.from(unique).sort((a, b) => a - b);
}

function mergeOwners(prev: OwnerMap, incoming: OwnerMap): OwnerMap {
  let changed = false;
  const next = new Map(prev);
  for (const [id, value] of incoming.entries()) {
    const prevVal = next.get(id);
    if (prevVal !== value) {
      next.set(id, value);
      changed = true;
    }
  }
  return changed ? next : prev;
}

function ownerTitle(id: number, owner: UserCacheValue | undefined): string | undefined {
  if (!owner) return undefined;
  const parts: string[] = [];
  if (owner.name?.trim()) parts.push(owner.name.trim());
  if (owner.email?.trim()) parts.push(`<${owner.email.trim()}>`);
  parts.push(`id ${id}`);
  return parts.join(" ");
}

function ownerLabel(id: number, owner: UserCacheValue | undefined): string {
  if (!owner) return String(id);
  const name = owner.name?.trim();
  if (name) return name;
  const email = owner.email?.trim();
  if (email) return email;
  return String(id);
}

export default function EvidenceTable({ items, fetchState, timeFormat }: Props): JSX.Element {
  const ownerIds = useMemo(() => computeOwnerIds(items), [items]);
  const ownerIdsKey = useMemo(() => ownerIds.join(","), [ownerIds]);

  const [owners, setOwners] = useState<OwnerMap>(() => {
    const initial: OwnerMap = new Map();
    for (const id of ownerIds) {
      const cached = getCachedUser(id);
      if (cached !== undefined) {
        initial.set(id, cached);
      }
    }
    return initial;
  });

  useEffect(() => {
    setOwners((prev) => {
      let changed = false;
      const next = new Map(prev);
      for (const id of ownerIds) {
        if (!next.has(id)) {
          const cached = getCachedUser(id);
          if (cached !== undefined) {
            next.set(id, cached);
            changed = true;
          }
        }
      }
      return changed ? next : prev;
    });
  }, [ownerIds]);

  useEffect(() => {
    if (ownerIds.length === 0) {
      setOwners(new Map());
      return;
    }

    let cancelled = false;
    void loadUsers(ownerIds).then((loaded) => {
      if (cancelled) return;
      setOwners((prev) => mergeOwners(prev, loaded));
    });
    return () => {
      cancelled = true;
    };
  }, [ownerIdsKey, ownerIds]);

  return (
    <div className="table-responsive">
      <table className="table" aria-label="Evidence results">
        <thead>
          <tr>
            <th>Created</th>
            <th>Owner</th>
            <th>Filename</th>
            <th>Size</th>
            <th>MIME</th>
            <th>SHA-256</th>
            <th>ID</th>
            <th>Version</th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && fetchState === "ok" ? (
            <tr>
              <td colSpan={8}>No results</td>
            </tr>
          ) : (
            items.map((item) => {
              const ownerVal = owners.get(item.owner_id);
              const createdLabel = formatDate(item.created_at, timeFormat);
              const sizeLabel = formatBytes(item.size);
              const shaPreview = item.sha256 ? `${item.sha256.slice(0, 12)}…` : "";

              return (
                <tr key={item.id}>
                  <td title={item.created_at}>{createdLabel}</td>
                  <td title={ownerTitle(item.owner_id, ownerVal)}>
                    {ownerVal === undefined ? (
                      <span className="text-muted">Loading...</span>
                    ) : (
                      ownerLabel(item.owner_id, ownerVal)
                    )}
                  </td>
                  <td>{item.filename}</td>
                  <td title={Number.isFinite(item.size) ? `${item.size.toLocaleString()} bytes` : undefined}>{sizeLabel}</td>
                  <td>{item.mime}</td>
                  <td style={{ fontFamily: "monospace" }}>{shaPreview}</td>
                  <td style={{ fontFamily: "monospace" }}>{item.id}</td>
                  <td>{item.version}</td>
                </tr>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
}
