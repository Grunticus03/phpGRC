import {
  DEFAULT_TIME_FORMAT,
  formatBytes as formatBytesInternal,
  formatTimestamp,
  normalizeTimeFormat,
  type TimeFormat,
} from "./formatters";

export { DEFAULT_TIME_FORMAT, normalizeTimeFormat, type TimeFormat };

export function formatBytes(value: number | null | undefined): string {
  return formatBytesInternal(value);
}

export function formatDate(value: string | null | undefined, format: TimeFormat = DEFAULT_TIME_FORMAT): string {
  return formatTimestamp(value, format);
}

export { formatTimestamp };
