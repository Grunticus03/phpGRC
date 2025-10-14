import { useEffect, useMemo, useRef, useState } from "react";
import type { Evidence } from "../../lib/api/evidence";
import { formatBytes, formatDate, type TimeFormat } from "../../lib/format";
import { getCachedUser, loadUsers, type UserCacheValue } from "../../lib/usersCache";
import FilterableHeaderRow, { type FilterableHeaderConfig } from "../../components/table/FilterableHeaderRow";

type Props = {
  headers: HeaderConfig[];
  items: Evidence[];
  fetchState: "idle" | "loading" | "error" | "ok";
  timeFormat: TimeFormat;
  onDownload: (item: Evidence) => void;
  downloadingId: string | null;
  onDelete: (item: Evidence) => void;
  deletingId: string | null;
  bulkDeleting: boolean;
  selectedIds: ReadonlySet<string>;
  onToggleSelect: (item: Evidence, selected: boolean) => void;
  onToggleSelectAll: (selected: boolean) => void;
  selectionDisabled?: boolean;
};

export type HeaderConfig = FilterableHeaderConfig;

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

export default function EvidenceTable({
  headers,
  items,
  fetchState,
  timeFormat,
  onDownload,
  downloadingId,
  onDelete,
  deletingId,
  bulkDeleting,
  selectedIds,
  onToggleSelect,
  onToggleSelectAll,
  selectionDisabled = false,
}: Props): JSX.Element {
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

  const selectedOnPage = useMemo(() => {
    let count = 0;
    for (const item of items) {
      if (selectedIds.has(item.id)) count += 1;
    }
    return count;
  }, [items, selectedIds]);

  const allOnPageSelected = items.length > 0 && selectedOnPage === items.length;
  const someOnPageSelected = selectedOnPage > 0 && !allOnPageSelected;
  const selectAllRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    if (selectAllRef.current) {
      selectAllRef.current.indeterminate = someOnPageSelected;
    }
  }, [someOnPageSelected, allOnPageSelected]);

  if (items.length === 0 && fetchState === "ok") {
    return (
      <div className="alert alert-light border" role="status">
        No evidence to display.
      </div>
    );
  }

  return (
    <div className="table-responsive">
      <table className="table" aria-label="Evidence results">
        <thead>
          <FilterableHeaderRow
            headers={headers}
            leadingCell={
              <th scope="col" className="text-center" style={{ width: "2.5rem" }}>
                <input
                  ref={selectAllRef}
                  type="checkbox"
                  className="form-check-input"
                  checked={items.length > 0 && allOnPageSelected}
                  onChange={(event) => onToggleSelectAll(event.currentTarget.checked)}
                  disabled={selectionDisabled || items.length === 0}
                  aria-label={items.length > 0 && allOnPageSelected ? "Deselect all evidence on this page" : "Select all evidence on this page"}
                />
              </th>
            }
          />
        </thead>
        <tbody>
          {items.map((item) => {
              const ownerVal = owners.get(item.owner_id);
              const createdLabel = formatDate(item.created_at, timeFormat);
              const sizeLabel = formatBytes(item.size);
              const mimeLabel = item.mime_label?.trim() ? item.mime_label : item.mime;
              const shaPreview = item.sha256 ? `${item.sha256.slice(0, 12)}…` : "";
              const isSelected = selectedIds.has(item.id);
              const isDeletingThis = deletingId === item.id;

              const deleteDisabled = bulkDeleting || deletingId !== null;
              const deleteLabel = bulkDeleting || isDeletingThis ? "Deleting…" : "Delete";

              return (
                <tr key={item.id} className={isSelected ? "table-active" : undefined}>
                  <td className="text-center" style={{ width: "2.5rem" }}>
                    <input
                      type="checkbox"
                      className="form-check-input"
                      checked={isSelected}
                      onChange={(event) => onToggleSelect(item, event.currentTarget.checked)}
                      disabled={selectionDisabled}
                      aria-label={`${isSelected ? "Deselect" : "Select"} ${item.filename || item.id}`}
                    />
                  </td>
                  <td title={item.created_at}>{createdLabel}</td>
                  <td className="text-break" style={{ maxWidth: "12rem" }} title={ownerTitle(item.owner_id, ownerVal)}>
                    {ownerVal === undefined ? (
                      <span className="text-muted">Loading...</span>
                    ) : (
                      ownerLabel(item.owner_id, ownerVal)
                    )}
                  </td>
                  <td className="text-break" style={{ maxWidth: "18rem" }}>{item.filename}</td>
                  <td title={Number.isFinite(item.size) ? `${item.size.toLocaleString()} bytes` : undefined}>{sizeLabel}</td>
                  <td className="text-break" style={{ maxWidth: "14rem" }} title={item.mime}>{mimeLabel}</td>
                  <td style={{ fontFamily: "monospace" }}>{shaPreview}</td>
                  <td>{item.version}</td>
                  <td>
                    <button
                      type="button"
                      className="btn btn-outline-primary btn-sm"
                      onClick={() => onDownload(item)}
                      disabled={downloadingId === item.id}
                      aria-label={`Download ${item.filename || item.id}`}
                    >
                      {downloadingId === item.id ? "Downloading…" : "Download"}
                    </button>
                  </td>
                  <td>
                    <button
                      type="button"
                      className="btn btn-outline-danger btn-sm"
                      onClick={() => onDelete(item)}
                      disabled={deleteDisabled}
                      aria-label={`Delete ${item.filename || item.id}`}
                    >
                      {deleteLabel}
                    </button>
                  </td>
                </tr>
              );
            })}
        </tbody>
      </table>
    </div>
  );
}
