import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { Evidence } from "../../lib/api/evidence";
import { formatBytes, formatDate, type TimeFormat } from "../../lib/format";
import { getCachedUser, loadUsers, type UserCacheValue } from "../../lib/usersCache";
import FilterableHeaderRow, { type FilterableHeaderConfig } from "../../components/table/FilterableHeaderRow";

export type HeaderConfig = FilterableHeaderConfig;
export type EvidenceSortKey = "created" | "owner" | "filename" | "size" | "mime" | "version";

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
  onToggleSelectAll: (selected: boolean, items: Evidence[]) => void;
  selectionDisabled?: boolean;
  searchTerm: string;
  sortKey: EvidenceSortKey | null;
  sortDirection: "asc" | "desc";
};

type OwnerMap = Map<number, UserCacheValue>;

type DecoratedItem = {
  item: Evidence;
  ownerName: string;
  ownerEmail: string;
  ownerDisplay: string;
  createdLabel: string;
  sizeLabel: string;
  mimeLabel: string;
  haystack: string;
};

const stringCollator = new Intl.Collator(undefined, { sensitivity: "base", usage: "sort", numeric: false });

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

function escapeForRegex(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function buildSearchMatcher(raw: string): ((haystack: string) => boolean) | null {
  const trimmed = raw.trim();
  if (trimmed === "") return null;
  const escaped = escapeForRegex(trimmed);
  const pattern = escaped.replace(/\\\*/g, ".*");
  try {
    const regex = new RegExp(pattern, "i");
    return (haystack: string) => regex.test(haystack);
  } catch {
    return null;
  }
}

function decorateItems(items: Evidence[], owners: OwnerMap, timeFormat: TimeFormat): DecoratedItem[] {
  return items.map((item) => {
    const owner = owners.get(item.owner_id);
    const ownerName = owner?.name?.trim() ?? "";
    const ownerEmail = owner?.email?.trim() ?? "";
    const ownerDisplay = ownerName !== "" ? ownerName : ownerEmail !== "" ? ownerEmail : String(item.owner_id);
    const createdLabel = formatDate(item.created_at, timeFormat);
    const sizeLabel = formatBytes(item.size);
    const mimeLabel = item.mime_label?.trim() ? item.mime_label : item.mime;
    const haystack = [
      item.id,
      String(item.owner_id),
      ownerName,
      ownerEmail,
      ownerDisplay,
      owner?.email ?? "",
      owner?.name ?? "",
      item.filename ?? "",
      mimeLabel,
      item.mime ?? "",
      item.sha256 ?? "",
      String(item.version),
      String(item.size),
      sizeLabel,
      createdLabel,
      item.created_at ?? "",
    ]
      .filter((value) => typeof value === "string" && value.trim() !== "")
      .join(" ");

    return {
      item,
      ownerName,
      ownerEmail,
      ownerDisplay,
      createdLabel,
      sizeLabel,
      mimeLabel,
      haystack,
    };
  });
}

function compareDecorated(a: DecoratedItem, b: DecoratedItem, key: EvidenceSortKey): number {
  switch (key) {
    case "created": {
      const timeA = Date.parse(a.item.created_at);
      const timeB = Date.parse(b.item.created_at);
      if (Number.isFinite(timeA) && Number.isFinite(timeB) && timeA !== timeB) {
        return timeA - timeB;
      }
      return a.item.created_at.localeCompare(b.item.created_at);
    }
    case "owner": {
      const nameCompare = stringCollator.compare(a.ownerName, b.ownerName);
      if (nameCompare !== 0) return nameCompare;
      const emailCompare = stringCollator.compare(a.ownerEmail, b.ownerEmail);
      if (emailCompare !== 0) return emailCompare;
      const displayCompare = stringCollator.compare(a.ownerDisplay, b.ownerDisplay);
      if (displayCompare !== 0) return displayCompare;
      return a.item.owner_id - b.item.owner_id;
    }
    case "filename":
      return stringCollator.compare(a.item.filename, b.item.filename) || a.item.id.localeCompare(b.item.id);
    case "size":
      return a.item.size - b.item.size || a.item.id.localeCompare(b.item.id);
    case "mime":
      return stringCollator.compare(a.mimeLabel, b.mimeLabel) || stringCollator.compare(a.item.mime, b.item.mime);
    case "version":
      return a.item.version - b.item.version || a.item.id.localeCompare(b.item.id);
    default:
      return 0;
  }
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
  searchTerm,
  sortKey,
  sortDirection,
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

  const decoratedItems = useMemo(() => decorateItems(items, owners, timeFormat), [items, owners, timeFormat]);
  const searchMatcher = useMemo(() => buildSearchMatcher(searchTerm), [searchTerm]);

  const filteredItems = useMemo(() => {
    if (!searchMatcher) return decoratedItems;
    return decoratedItems.filter(({ haystack }) => searchMatcher(haystack));
  }, [decoratedItems, searchMatcher]);

  const sortedItems = useMemo(() => {
    if (sortKey === null) return filteredItems;
    const factor = sortDirection === "desc" ? -1 : 1;
    return [...filteredItems].sort((a, b) => compareDecorated(a, b, sortKey) * factor);
  }, [filteredItems, sortDirection, sortKey]);

  const visibleItems = useMemo(() => sortedItems.map((entry) => entry.item), [sortedItems]);

  const selectAllRef = useRef<HTMLInputElement | null>(null);
  const copyTimerRef = useRef<number | null>(null);
  const [copiedShaId, setCopiedShaId] = useState<string | null>(null);

  const selectedOnPage = useMemo(() => {
    return visibleItems.reduce((count, item) => (selectedIds.has(item.id) ? count + 1 : count), 0);
  }, [visibleItems, selectedIds]);

  const allOnPageSelected = visibleItems.length > 0 && selectedOnPage === visibleItems.length;
  const someOnPageSelected = selectedOnPage > 0 && !allOnPageSelected;

  useEffect(() => {
    if (selectAllRef.current) {
      selectAllRef.current.indeterminate = someOnPageSelected;
    }
  }, [someOnPageSelected, allOnPageSelected]);

  useEffect(() => {
    return () => {
      if (copyTimerRef.current !== null) {
        window.clearTimeout(copyTimerRef.current);
      }
    };
  }, []);

  const handleCopySha = useCallback(
    (id: string, value: string | null | undefined) => {
      if (!value || value.trim() === "") return;
      const copy = async (): Promise<void> => {
        try {
          if (typeof navigator !== "undefined" && navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
          } else if (typeof document !== "undefined") {
            const textarea = document.createElement("textarea");
            textarea.value = value;
            textarea.style.position = "fixed";
            textarea.style.opacity = "0";
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            document.execCommand("copy");
            document.body.removeChild(textarea);
          }
          setCopiedShaId(id);
          if (copyTimerRef.current !== null) {
            window.clearTimeout(copyTimerRef.current);
          }
          copyTimerRef.current = window.setTimeout(() => {
            setCopiedShaId(null);
            copyTimerRef.current = null;
          }, 2000);
        } catch {
          setCopiedShaId(null);
        }
      };
      void copy();
    },
    []
  );

  if (visibleItems.length === 0 && fetchState === "ok") {
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
              <th scope="col" className="text-start" style={{ width: "6.5rem" }}>
                <div className="d-flex justify-content-start align-items-center gap-2">
                  <input
                    ref={selectAllRef}
                    type="checkbox"
                    className="form-check-input"
                    checked={visibleItems.length > 0 && allOnPageSelected}
                    onChange={(event) => onToggleSelectAll(event.currentTarget.checked, visibleItems)}
                    disabled={selectionDisabled || visibleItems.length === 0}
                    aria-label={
                      visibleItems.length > 0 && allOnPageSelected
                        ? "Deselect all evidence on this page"
                        : "Select all evidence on this page"
                    }
                  />
                </div>
              </th>
            }
          />
        </thead>
        <tbody>
          {sortedItems.map(({ item, ownerName, ownerEmail, ownerDisplay, createdLabel, sizeLabel, mimeLabel }) => {
            const isSelected = selectedIds.has(item.id);
            const isDeletingThis = deletingId === item.id;

            const deleteDisabled = bulkDeleting || deletingId !== null;
            const isDownloading = downloadingId === item.id;
            const showDeleteSpinner = isDeletingThis || bulkDeleting;
            const ownerCell = ownerName !== "" ? ownerName : ownerEmail !== "" ? ownerEmail : ownerDisplay;
            const ownerTooltipParts = [ownerName, ownerEmail].filter(
              (part) => typeof part === "string" && part.trim() !== ""
            );
            if (ownerTooltipParts.length === 0 && ownerDisplay) {
              ownerTooltipParts.push(ownerDisplay);
            }
            const ownerTooltip = ownerTooltipParts.length > 0 ? ownerTooltipParts.join(" • ") : undefined;

            return (
              <tr key={item.id} className={isSelected ? "table-active" : undefined}>
                <td className="text-start" style={{ width: "6.5rem" }}>
                  <div className="d-flex justify-content-start align-items-center gap-2">
                    <input
                      type="checkbox"
                      className="form-check-input"
                      checked={isSelected}
                      onChange={(event) => onToggleSelect(item, event.currentTarget.checked)}
                      disabled={selectionDisabled}
                      aria-label={`${isSelected ? "Deselect" : "Select"} ${item.filename || item.id}`}
                    />
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm"
                      onClick={() => onDownload(item)}
                      disabled={isDownloading}
                      aria-label={
                        isDownloading
                          ? `Downloading ${item.filename || item.id}`
                          : `Download ${item.filename || item.id}`
                      }
                    >
                      {isDownloading ? (
                        <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
                      ) : (
                        <i className="bi bi-download" aria-hidden="true" />
                      )}
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-danger btn-sm"
                      onClick={() => onDelete(item)}
                      disabled={deleteDisabled}
                      aria-label={
                        showDeleteSpinner
                          ? `Deleting ${item.filename || item.id}`
                          : `Delete ${item.filename || item.id}`
                      }
                    >
                      {showDeleteSpinner ? (
                        <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
                      ) : (
                        <i className="bi bi-trash" aria-hidden="true" />
                      )}
                    </button>
                  </div>
                </td>
                <td className="text-nowrap">{createdLabel}</td>
                <td className="text-break" title={ownerTooltip}>{ownerCell || "—"}</td>
                <td className="text-break">
                  <div className="d-inline-flex align-items-center gap-2">
                    <span className="fw-semibold text-break">{item.filename || item.id}</span>
                    {item.sha256 ? (
                      <>
                        <button
                          type="button"
                          className="btn btn-link btn-sm p-0 text-decoration-none"
                          onClick={() => handleCopySha(item.id, item.sha256)}
                          title={item.sha256}
                          aria-label={`Copy SHA-256 for ${item.filename || item.id}`}
                        >
                          <i className="bi bi-hash" aria-hidden="true" />
                        </button>
                        {copiedShaId === item.id ? <span className="text-success small">Copied!</span> : null}
                      </>
                    ) : null}
                  </div>
                </td>
                <td>{sizeLabel}</td>
                <td className="text-break">{mimeLabel}</td>
                <td>{item.version}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
