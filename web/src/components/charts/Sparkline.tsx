import React from "react";

export type SparkPoint = { x: string; y: number };

export type SparklineProps = {
  data: SparkPoint[];
  width?: number;   // px
  height?: number;  // px
  strokeWidth?: number;
  ariaLabel?: string;
  className?: string;
};

function toNumber(n: unknown, fallback = 0): number {
  return typeof n === "number" && isFinite(n) ? n : fallback;
}

function pathFrom(data: SparkPoint[], w: number, h: number, pad = 4): string {
  const n = data.length;
  const innerW = Math.max(0, w - pad * 2);
  const innerH = Math.max(0, h - pad * 2);

  if (n === 0 || innerW === 0 || innerH === 0) {
    const y = pad + innerH / 2;
    return `M ${pad} ${y} L ${pad + innerW} ${y}`;
  }

  // Determine Y domain
  let minY = Number.POSITIVE_INFINITY;
  let maxY = Number.NEGATIVE_INFINITY;
  for (const p of data) {
    const v = toNumber(p.y, 0);
    if (v < minY) minY = v;
    if (v > maxY) maxY = v;
  }
  if (!isFinite(minY) || !isFinite(maxY)) {
    minY = 0;
    maxY = 1;
  }
  if (minY === maxY) {
    // Flat line in the middle when all values equal
    const yFlat = pad + innerH / 2;
    let d = `M ${pad} ${yFlat}`;
    for (let i = 1; i < n; i++) {
      const x = pad + (innerW * i) / Math.max(1, n - 1);
      d += ` L ${x} ${yFlat}`;
    }
    return d;
  }

  const scaleX = (i: number) => pad + (innerW * i) / Math.max(1, n - 1);
  const scaleY = (v: number) =>
    pad + innerH - ((toNumber(v, 0) - minY) / (maxY - minY)) * innerH;

  let d = `M ${scaleX(0)} ${scaleY(data[0].y)}`;
  for (let i = 1; i < n; i++) {
    d += ` L ${scaleX(i)} ${scaleY(data[i].y)}`;
  }
  return d;
}

/**
 * Minimal, dependency-free SVG sparkline.
 * - Color inherits from currentColor.
 * - Accessible: role="img" with aria-label.
 */
export function Sparkline({
  data,
  width = 120,
  height = 28,
  strokeWidth = 2,
  ariaLabel = "Trend sparkline",
  className,
}: SparklineProps) {
  const d = React.useMemo(() => pathFrom(data, width, height, 4), [data, width, height]);

  // Last point marker
  const n = data.length;
  const hasPoint = n > 0 && width > 8 && height > 8;
  let lastCX = 0;
  let lastCY = 0;
  if (hasPoint) {
    const innerW = Math.max(0, width - 8);
    const innerH = Math.max(0, height - 8);
    const minY = Math.min(...data.map((p) => p.y));
    const maxY = Math.max(...data.map((p) => p.y));
    const scaleX = (i: number) => 4 + (innerW * i) / Math.max(1, n - 1);
    const scaleY =
      minY === maxY
        ? () => 4 + innerH / 2
        : (v: number) => 4 + innerH - ((v - minY) / (maxY - minY)) * innerH;
    lastCX = scaleX(n - 1);
    lastCY = scaleY(data[n - 1].y);
  }

  return (
    <svg
      width={width}
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      role="img"
      aria-label={ariaLabel}
      className={className}
    >
      <path d={d} fill="none" stroke="currentColor" strokeWidth={strokeWidth} />
      {hasPoint ? (
        <circle cx={lastCX} cy={lastCY} r={strokeWidth + 1} fill="currentColor" />
      ) : null}
    </svg>
  );
}

export default Sparkline;

// Exported for testing
export const __private = { pathFrom };
