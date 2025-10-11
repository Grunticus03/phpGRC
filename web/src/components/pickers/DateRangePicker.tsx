import { useEffect, useMemo, useState } from "react";

const WEEKDAY_LABELS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

function isoFromDate(date: Date): string {
  const year = date.getUTCFullYear();
  const month = String(date.getUTCMonth() + 1).padStart(2, "0");
  const day = String(date.getUTCDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function parseIsoDate(value: string): Date | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const [yearStr, monthStr, dayStr] = value.split("-");
  const year = Number(yearStr);
  const month = Number(monthStr);
  const day = Number(dayStr);
  if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
    return null;
  }
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
    return null;
  }
  return date;
}

function isWithin(start: string, end: string, candidate: string): boolean {
  if (!start || !end) return false;
  return candidate > start && candidate < end;
}

export type DateRangePickerProps = {
  start: string;
  end: string;
  onChange: (start: string, end: string) => void;
  onComplete: (start: string, end: string) => void;
  onClear: () => void;
};

export default function DateRangePicker({ start, end, onChange, onComplete, onClear }: DateRangePickerProps): JSX.Element {
  const today = isoFromDate(new Date());
  const initialIso = start || end || today;
  const initialDate = parseIsoDate(initialIso) ?? new Date();
  const [viewYear, setViewYear] = useState(() => initialDate.getUTCFullYear());
  const [viewMonth, setViewMonth] = useState(() => initialDate.getUTCMonth());
  const [pendingStart, setPendingStart] = useState(start);
  const [pendingEnd, setPendingEnd] = useState(end);

  const monthFormatter = useMemo(
    () =>
      new Intl.DateTimeFormat(undefined, {
        month: "long",
        year: "numeric",
        timeZone: "UTC",
      }),
    []
  );

  const dayFormatter = useMemo(
    () =>
      new Intl.DateTimeFormat(undefined, {
        weekday: "short",
        month: "short",
        day: "numeric",
        year: "numeric",
        timeZone: "UTC",
      }),
    []
  );

  useEffect(() => {
    setPendingStart(start);
    setPendingEnd(end);
  }, [start, end]);

  const monthLabel = useMemo(
    () => monthFormatter.format(new Date(Date.UTC(viewYear, viewMonth, 1))),
    [monthFormatter, viewYear, viewMonth]
  );

  const weeks = useMemo(() => {
    const firstDay = new Date(Date.UTC(viewYear, viewMonth, 1));
    const startWeekday = firstDay.getUTCDay();
    const daysInMonth = new Date(Date.UTC(viewYear, viewMonth + 1, 0)).getUTCDate();
    const cells: Array<{ iso: string; day: number } | null> = [];

    for (let i = 0; i < startWeekday; i += 1) {
      cells.push(null);
    }
    for (let day = 1; day <= daysInMonth; day += 1) {
      const iso = isoFromDate(new Date(Date.UTC(viewYear, viewMonth, day)));
      cells.push({ iso, day });
    }
    while (cells.length % 7 !== 0) {
      cells.push(null);
    }
    const chunked: Array<Array<{ iso: string; day: number } | null>> = [];
    for (let i = 0; i < cells.length; i += 7) {
      chunked.push(cells.slice(i, i + 7));
    }
    return chunked;
  }, [viewYear, viewMonth]);

  const goMonth = (delta: number) => {
    const base = new Date(Date.UTC(viewYear, viewMonth + delta, 1));
    setViewYear(base.getUTCFullYear());
    setViewMonth(base.getUTCMonth());
  };

  const formatDayLabel = (iso: string, day: number): string => {
    const date = parseIsoDate(iso);
    if (date) {
      return dayFormatter.format(date);
    }
    return `Day ${day}`;
  };

  const handleSelect = (iso: string) => {
    if (!pendingStart || (pendingStart && pendingEnd)) {
      setPendingStart(iso);
      setPendingEnd("");
      onChange(iso, "");
      return;
    }

    if (pendingStart && !pendingEnd) {
      if (iso < pendingStart) {
        setPendingStart(iso);
        setPendingEnd("");
        onChange(iso, "");
        return;
      }
      setPendingEnd(iso);
      onChange(pendingStart, iso);
      onComplete(pendingStart, iso);
    }
  };

  const handleClear = () => {
    setPendingStart("");
    setPendingEnd("");
    onChange("", "");
    onClear();
  };

  return (
    <div className="border rounded p-3 d-inline-block">
      <div className="d-flex justify-content-between align-items-center mb-2 gap-2">
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => goMonth(-1)}>
          ‹
        </button>
        <div className="fw-semibold">{monthLabel}</div>
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => goMonth(1)}>
          ›
        </button>
      </div>
      <table className="table table-sm mb-2" style={{ width: "100%", minWidth: "16rem" }}>
        <thead>
          <tr>
            {WEEKDAY_LABELS.map((label) => (
              <th key={label} className="text-center small">
                {label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {weeks.map((week, index) => (
            <tr key={index}>
              {week.map((cell, idx) => {
                if (!cell) {
                  return <td key={idx} />;
                }
                const isStart = pendingStart === cell.iso;
                const isEnd = pendingEnd === cell.iso;
                const inRange = isWithin(pendingStart, pendingEnd, cell.iso);
                const isToday = cell.iso === today;
                const style: React.CSSProperties = {
                  width: "2.4rem",
                  height: "2.4rem",
                  borderRadius: "999px",
                  border: "none",
                  backgroundColor: "transparent",
                  color: "#212529",
                };
                if (inRange) {
                  style.backgroundColor = "#e0edff";
                }
                if (isStart || isEnd) {
                  style.backgroundColor = "#0d6efd";
                  style.color = "#ffffff";
                  style.fontWeight = 600;
                } else if (isToday) {
                  style.border = "1px solid #0d6efd";
                }
                return (
                  <td key={cell.iso} className="text-center">
                    <button
                      type="button"
                      className="btn btn-sm p-0"
                      style={style}
                      aria-pressed={isStart || isEnd}
                      onClick={() => handleSelect(cell.iso)}
                      aria-label={formatDayLabel(cell.iso, cell.day)}
                    >
                      {cell.day}
                    </button>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
      <div className="d-flex justify-content-between align-items-center gap-2">
        <div className="small text-muted">
          {pendingStart && !pendingEnd && "Select an end date"}
          {pendingStart && pendingEnd && `${pendingStart} → ${pendingEnd}`}
          {!pendingStart && !pendingEnd && "Select start date"}
        </div>
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={handleClear}>
          Clear
        </button>
      </div>
    </div>
  );
}
