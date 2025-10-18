import type { ReactNode } from "react";

export type FilterableHeaderConfig = {
  key: string;
  label: string;
  onToggle?: () => void;
  isActive?: boolean;
  filterContent?: ReactNode;
  summaryContent?: ReactNode;
  className?: string;
  sortState?: "asc" | "desc" | null;
  onSort?: () => void;
  sortAriaLabel?: string;
  sortDisabled?: boolean;
};

type Props = {
  headers: FilterableHeaderConfig[];
  leadingCell?: ReactNode;
  trailingCell?: ReactNode;
};

const iconForSortState = (state: "asc" | "desc" | null | undefined): string => {
  if (state === "asc") return "bi-arrow-up";
  if (state === "desc") return "bi-arrow-down";
  return "bi-arrow-down-up";
};

export default function FilterableHeaderRow({ headers, leadingCell, trailingCell }: Props): JSX.Element {
  return (
    <tr>
      {leadingCell}
      {headers.map(
        ({ key, label, onToggle, isActive, filterContent, summaryContent, className, sortState, onSort, sortAriaLabel, sortDisabled }) => (
        <th key={key} scope="col" className={className}>
            <div className="d-inline-flex align-items-center gap-1">
              {onToggle ? (
                <button
                  type="button"
                  className={`btn btn-link p-0 fw-semibold text-start ${isActive ? "" : "link-underline-opacity-0"}`}
                  onClick={onToggle}
                >
                  {label}
                </button>
              ) : (
                <span className="fw-semibold">{label}</span>
              )}
              {onSort ? (
                <button
                  type="button"
                  className="btn btn-link p-0 text-muted sort-toggle"
                  onClick={onSort}
                  aria-label={sortAriaLabel ?? `Sort by ${label}`}
                  disabled={sortDisabled}
                >
                  <i className={`bi ${iconForSortState(sortState)}`} aria-hidden="true" />
                </button>
              ) : null}
            </div>
          {summaryContent}
          {filterContent}
        </th>
        )
      )}
      {trailingCell}
    </tr>
  );
}
