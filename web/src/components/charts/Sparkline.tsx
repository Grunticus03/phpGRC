import React from "react";

export type SparklineProps = {
  values: number[];               // array of numbers, commonly 0..1
  width?: number;                 // px
  height?: number;                // px
  strokeWidth?: number;           // px
  ariaLabel?: string;             // accessible label
  className?: string;
};

/**
 * Minimal, dependency-free SVG sparkline.
 * - Scales X evenly across samples.
 * - Scales Y between min..max; if flat series, draws a midline.
 * - Stroke uses currentColor. No fill.
 */
export default function Sparkline({
  values,
  width = 160,
  height = 40,
  strokeWidth = 2,
  ariaLabel = "sparkline",
  className,
}: SparklineProps): JSX.Element {
  const n = Array.isArray(values) ? values.length : 0;

  const w = Math.max(1, Math.floor(width));
  const h = Math.max(1, Math.floor(height));
  const sw = Math.max(1, Math.floor(strokeWidth));

  if (n === 0) {
    return (
      <svg
        role="img"
        aria-label={ariaLabel}
        width={w}
        height={h}
        viewBox={`0 0 ${w} ${h}`}
        className={className}
        data-empty="true"
      />
    );
  }

  let min = Number.POSITIVE_INFINITY;
  let max = Number.NEGATIVE_INFINITY;
  for (let i = 0; i < n; i += 1) {
    const v = Number.isFinite(values[i]) ? (values[i] as number) : 0;
    if (v < min) min = v;
    if (v > max) max = v;
  }
  if (!Number.isFinite(min)) min = 0;
  if (!Number.isFinite(max)) max = 0;

  const range = max - min;
  const dx = n > 1 ? w / (n - 1) : 0;

  const points: Array<{ x: number; y: number }> = [];
  for (let i = 0; i < n; i += 1) {
    const v = Number.isFinite(values[i]) ? (values[i] as number) : 0;
    const t = range > 0 ? (v - min) / range : 0.5; // flat series → midline
    const x = Math.round((i * dx + Number.EPSILON) * 100) / 100;
    const y = Math.round(((1 - t) * h + Number.EPSILON) * 100) / 100;
    points.push({ x, y });
  }

  const d =
    points.length === 1
      ? `M 0 ${points[0]!.y} L ${w} ${points[0]!.y}`
      : points
          .map((p, idx) => `${idx === 0 ? "M" : "L"} ${p.x} ${p.y}`)
          .join(" ");

  return (
    <svg
      role="img"
      aria-label={ariaLabel}
      width={w}
      height={h}
      viewBox={`0 0 ${w} ${h}`}
      className={className}
    >
      <path d={d} fill="none" stroke="currentColor" strokeWidth={sw} vectorEffect="non-scaling-stroke" />
    </svg>
  );
}
