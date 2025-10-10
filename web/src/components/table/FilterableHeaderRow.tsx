import type { ReactNode } from "react";

export type FilterableHeaderConfig = {
  key: string;
  label: string;
  onToggle?: () => void;
  isActive?: boolean;
  filterContent?: ReactNode;
  summaryContent?: ReactNode;
  className?: string;
};

type Props = {
  headers: FilterableHeaderConfig[];
};

export default function FilterableHeaderRow({ headers }: Props): JSX.Element {
  return (
    <tr>
      {headers.map(({ key, label, onToggle, isActive, filterContent, summaryContent, className }) => (
        <th key={key} scope="col" className={className}>
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
          {summaryContent}
          {filterContent}
        </th>
      ))}
    </tr>
  );
}
