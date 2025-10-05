// FILE: web/src/components/inputs/DaysSelector.tsx
import React from "react";

export type DaysSelectorProps = {
  id?: string;
  label?: string;
  value: number;              // 1..365
  min?: number;               // default 1
  max?: number;               // default 365
  onChange: (days: number) => void;
  disabled?: boolean;
  className?: string;
};

export default function DaysSelector({
  id = "days",
  label = "Days",
  value,
  min = 1,
  max = 365,
  onChange,
  disabled,
  className,
}: DaysSelectorProps) {
  const clamp = (n: number) => Math.max(min, Math.min(max, Math.trunc(n)));
  const handle = (n: number) => onChange(clamp(n));

  return (
    <div className={className} style={{ display: "grid", gridTemplateColumns: "1fr auto", gap: 8, alignItems: "center" }}>
      <label htmlFor={id} style={{ fontSize: 12, opacity: 0.8 }}>{label}</label>
      <input
        id={id}
        type="number"
        min={min}
        max={max}
        value={value}
        disabled={disabled}
        onChange={(e) => handle(Number(e.target.value))}
        style={{ width: 72 }}
        aria-label={label}
      />
      <input
        type="range"
        min={min}
        max={max}
        value={value}
        disabled={disabled}
        onChange={(e) => handle(Number(e.target.value))}
        aria-label={`${label} slider`}
        style={{ gridColumn: "1 / span 2" }}
      />
    </div>
  );
}
