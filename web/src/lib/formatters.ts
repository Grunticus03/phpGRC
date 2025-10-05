export type TimeFormat = "ISO_8601" | "LOCAL" | "RELATIVE";

export const SUPPORTED_TIME_FORMATS: readonly TimeFormat[] = [
  "ISO_8601",
  "LOCAL",
  "RELATIVE",
] as const;

export const DEFAULT_TIME_FORMAT: TimeFormat = "LOCAL";

export function normalizeTimeFormat(value: unknown): TimeFormat {
  if (typeof value === "string") {
    const upper = value.trim().toUpperCase();
    if (upper && (SUPPORTED_TIME_FORMATS as readonly string[]).includes(upper)) {
      return upper as TimeFormat;
    }
  }

  return DEFAULT_TIME_FORMAT;
}

export function formatTimestamp(input: string | null | undefined, format: TimeFormat): string {
  if (!input) return "";
  const date = new Date(input);
  if (Number.isNaN(date.valueOf())) return input;

  switch (format) {
    case "ISO_8601":
      return date.toISOString().replace('T', ' ').replace('Z', 'Z');
    case "RELATIVE":
      return formatRelative(date, new Date());
    case "LOCAL":
    default:
      return date.toLocaleString();
  }
}

function formatRelative(target: Date, now: Date): string {
  const diffMs = target.getTime() - now.getTime();
  const absMs = Math.abs(diffMs);

  const units: Array<{ unit: Intl.RelativeTimeFormatUnit; ms: number }> = [
    { unit: "year", ms: 1000 * 60 * 60 * 24 * 365 },
    { unit: "month", ms: 1000 * 60 * 60 * 24 * 30 },
    { unit: "week", ms: 1000 * 60 * 60 * 24 * 7 },
    { unit: "day", ms: 1000 * 60 * 60 * 24 },
    { unit: "hour", ms: 1000 * 60 * 60 },
    { unit: "minute", ms: 1000 * 60 },
    { unit: "second", ms: 1000 },
  ];

  const formatter = typeof Intl !== "undefined" && typeof Intl.RelativeTimeFormat !== "undefined"
    ? new Intl.RelativeTimeFormat(undefined, { numeric: "auto" })
    : null;

  for (const { unit, ms } of units) {
    if (absMs >= ms || unit === "second") {
      const value = diffMs / ms;
      const rounded = Math.round(value);
      if (formatter) {
        return formatter.format(rounded, unit);
      }
      if (rounded === 0) {
        return "just now";
      }
      const plural = Math.abs(rounded) === 1 ? unit : `${unit}s`;
      return rounded > 0 ? `in ${rounded} ${plural}` : `${Math.abs(rounded)} ${plural} ago`;
    }
  }

  return target.toLocaleString();
}

export function formatBytes(input: number | null | undefined): string {
  const bytes = typeof input === "number" && Number.isFinite(input) ? Math.max(input, 0) : 0;
  if (bytes === 0) return "0 B";

  const units = ["B", "KB", "MB", "GB", "TB", "PB"];
  let size = bytes;
  let unitIndex = 0;
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex += 1;
  }

  const digits = unitIndex === 0 ? 0 : 2;
  const formatter = new Intl.NumberFormat("en-US", { minimumFractionDigits: digits, maximumFractionDigits: digits });
  return `${formatter.format(size)} ${units[unitIndex]}`;
}
