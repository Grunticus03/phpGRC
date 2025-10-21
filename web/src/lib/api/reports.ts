import { API_BASE, baseHeaders, HttpError } from "../api";

type DownloadResult = {
  blob: Blob;
  filename: string;
  mime: string;
};

function parseFilename(contentDisposition: string | null): string | null {
  if (!contentDisposition) return null;

  const starMatch = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(contentDisposition);
  if (starMatch?.[1]) {
    try {
      return decodeURIComponent(starMatch[1].replace(/(^"|"$)/g, ""));
    } catch {
      return starMatch[1].replace(/(^"|"$)/g, "");
    }
  }

  const quotedMatch = /filename="([^"]+)"/i.exec(contentDisposition);
  if (quotedMatch?.[1]) {
    return quotedMatch[1];
  }

  const plainMatch = /filename=([^;]+)/i.exec(contentDisposition);
  if (plainMatch?.[1]) {
    return plainMatch[1].trim().replace(/(^"|"$)/g, "");
  }

  return null;
}

function buildAdminActivityUrl(): string {
  return `${API_BASE}/reports/admin-activity?format=csv`;
}

async function fetchAdminActivityCsv(): Promise<DownloadResult> {
  const url = buildAdminActivityUrl();
  const res = await fetch(url, {
    method: "GET",
    credentials: "same-origin",
    headers: baseHeaders({ Accept: "text/csv" }),
  });

  if (!res.ok) {
    let body: unknown = null;
    try {
      body = await res.json();
    } catch {
      try {
        body = await res.text();
      } catch {
        body = null;
      }
    }

    throw new HttpError(res.status, body);
  }

  const blob = await res.blob();
  const filename =
    parseFilename(res.headers.get("content-disposition")) ?? "admin-activity-report.csv";
  const mime = res.headers.get("content-type") ?? "text/csv";

  return { blob, filename, mime };
}

export async function downloadAdminActivityCsv(): Promise<void> {
  if (typeof window === "undefined" || typeof document === "undefined") return;

  const result = await fetchAdminActivityCsv();

  const finalName = result.filename && result.filename.trim().length > 0
    ? result.filename.trim()
    : "admin-activity-report.csv";

  if (typeof URL !== "undefined" && typeof URL.createObjectURL === "function") {
    const href = URL.createObjectURL(result.blob);
    const anchor = document.createElement("a");
    anchor.href = href;
    anchor.download = finalName;
    anchor.rel = "noopener noreferrer";
    anchor.style.display = "none";
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.setTimeout(() => {
      if (typeof URL.revokeObjectURL === "function") {
        URL.revokeObjectURL(href);
      }
    }, 1000);
    return;
  }

  if (typeof window.open === "function") {
    window.open(buildAdminActivityUrl(), "_blank", "noopener");
    return;
  }

  window.location.assign(buildAdminActivityUrl());
}
