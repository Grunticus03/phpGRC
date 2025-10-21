/** @vitest-environment jsdom */
import React from "react";
import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { render, screen, fireEvent, waitFor, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

import EvidenceList from "../List";
import { ToastProvider } from "../../../components/toast/ToastProvider";

const ROUTER_FUTURE_FLAGS = { v7_startTransition: true, v7_relativeSplatPath: true } as const;

function jsonResponse(body: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { "Content-Type": "application/json" },
    ...init,
  });
}

describe("Evidence List", () => {
  const originalFetch = globalThis.fetch as typeof fetch;
  const originalCreateObjectURL = URL.createObjectURL;
  const originalRevokeObjectURL = URL.revokeObjectURL;
  let calls: string[] = [];
  let downloadResponse: Response;
  let fetchMock: ReturnType<typeof vi.fn>;
  let mockCreateObjectURL: ReturnType<typeof vi.fn>;
  let mockRevokeObjectURL: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    calls = [];
    downloadResponse = new Response("PDFDATA", {
      status: 200,
      headers: {
        "Content-Type": "application/pdf",
        "Content-Disposition": 'attachment; filename="report.pdf"',
      },
    });
    mockCreateObjectURL = vi.fn(() => "blob:mock-url");
    mockRevokeObjectURL = vi.fn();
    Object.defineProperty(URL, "createObjectURL", {
      configurable: true,
      writable: true,
      value: mockCreateObjectURL,
    });
    Object.defineProperty(URL, "revokeObjectURL", {
      configurable: true,
      writable: true,
      value: mockRevokeObjectURL,
    });

    fetchMock = vi.fn(async (...args: Parameters<typeof fetch>) => {
      const url = typeof args[0] === "string" ? args[0] : args[0].toString();
      calls.push(url);

      if (url.startsWith("/evidence/")) {
        return downloadResponse;
      }
      if (url.startsWith("/rbac/users/search")) {
        return jsonResponse({
          ok: true,
          data: [{ id: 42, name: "Alice Admin", email: "alice@example.test" }],
          meta: { page: 1, per_page: 10, total: 1, total_pages: 1 },
        });
      }

      if (url.startsWith("/rbac/users/42/roles")) {
        return jsonResponse({
          ok: true,
          user: { id: 42, name: "Alice Admin", email: "alice@example.test" },
          roles: ["admin"],
        });
      }

      if (url.startsWith("/evidence")) {
        const urlObj = new URL(url, "http://localhost");
        const mimeFilter = urlObj.searchParams.get("mime");
        const mimeLabelFilter = urlObj.searchParams.get("mime_label");
        const effectiveMime =
          mimeFilter ??
          (mimeLabelFilter && mimeLabelFilter.toLowerCase().includes("png") ? "image/png" : "application/pdf");
        const effectiveLabel =
          mimeLabelFilter ??
          (mimeFilter === "image/png" ? "PNG image" : "PDF document");
        return jsonResponse({
          ok: true,
          time_format: "ISO_8601",
          data: [
            {
              id: "ev_01X",
              owner_id: 42,
              filename: "report.pdf",
              mime: effectiveMime,
              mime_label: effectiveLabel,
              size: 1234,
              sha256: "7F9C2BA4E88F827D616045507605853ED73B8063F4A9A6F5D5B1E5F0E9D5A1C3",
              version: 1,
              created_at: "2025-09-12T00:00:00Z",
            },
            {
              id: "ev_02Y",
              owner_id: 42,
              filename: "alpha.log",
              mime: "text/plain",
              mime_label: "Plain Text",
              size: 999,
              sha256: "AA9C2BA4E88F827D616045507605853ED73B8063F4A9A6F5D5B1E5F0E9D5A1C3",
              version: 2,
              created_at: "2025-09-10T00:00:00Z",
            },
            {
              id: "ev_03Z",
              owner_id: 42,
              filename: "zulu.csv",
              mime: "text/csv",
              mime_label: "CSV",
              size: 2048,
              sha256: "BB9C2BA4E88F827D616045507605853ED73B8063F4A9A6F5D5B1E5F0E9D5A1C3",
              version: 1,
              created_at: "2025-09-11T00:00:00Z",
            },
          ],
          next_cursor: null,
        });
      }

      return jsonResponse({ ok: true });
    }) as ReturnType<typeof vi.fn>;
    globalThis.fetch = fetchMock as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    Object.defineProperty(URL, "createObjectURL", {
      configurable: true,
      writable: true,
      value: originalCreateObjectURL,
    });
    Object.defineProperty(URL, "revokeObjectURL", {
      configurable: true,
      writable: true,
      value: originalRevokeObjectURL,
    });
    vi.restoreAllMocks();
  });

  it("selects owner via search and includes owner_id in evidence request", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <ToastProvider>
          <EvidenceList />
        </ToastProvider>
      </MemoryRouter>
    );

    await screen.findByText("Evidence");

    const ownerColumnToggle = screen.getByRole("button", { name: "Username" });
    fireEvent.click(ownerColumnToggle);

    const ownerInput = screen.getByLabelText("Filter by username or email") as HTMLInputElement;
    fireEvent.change(ownerInput, { target: { value: "alice" } });

    fireEvent.click(screen.getByRole("button", { name: "Search" }));

    await waitFor(() => {
      const hits = calls.filter((u) => u.startsWith("/evidence?"));
      expect(hits.length).toBeGreaterThan(0);
      const last = hits[hits.length - 1];
      expect(String(last)).toContain("owner_id=42");
    });

    const evidenceTable = await screen.findByRole("table", { name: "Evidence results" });
    await within(evidenceTable).findByText("report.pdf");
    const ownerCells = await within(evidenceTable).findAllByText("Alice Admin");
    expect(ownerCells.length).toBeGreaterThan(0);
    await within(evidenceTable).findByText(/1\.21 KB/);
    await within(evidenceTable).findByText(/2025-09-12 00:00:00/);

    expect(calls.some((u) => u.startsWith("/rbac/users/42/roles"))).toBe(true);
  });

  it("downloads evidence file when user clicks Download", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <ToastProvider>
          <EvidenceList />
        </ToastProvider>
      </MemoryRouter>
    );

    await screen.findByText("Evidence");

    const table = await screen.findByRole("table", { name: "Evidence results" });
    expect(within(table).getByRole("button", { name: "Copy SHA-256 for report.pdf" })).toBeInTheDocument();
    const downloadButton = within(table).getByRole("button", { name: "Download report.pdf" });

    fireEvent.click(downloadButton);

    await waitFor(() => {
      expect(mockCreateObjectURL).toHaveBeenCalledTimes(1);
      expect(calls.some((u) => u.startsWith("/evidence/ev_01X"))).toBe(true);
    });
  });

  it("shows an error when download fails", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <ToastProvider>
          <EvidenceList />
        </ToastProvider>
      </MemoryRouter>
    );

    await screen.findByText("Evidence");

    const table = await screen.findByRole("table", { name: "Evidence results" });
    const downloadButton = within(table).getByRole("button", { name: "Download report.pdf" });

    downloadResponse = new Response(JSON.stringify({ ok: false, code: "EVIDENCE_NOT_FOUND" }), {
      status: 404,
      headers: { "Content-Type": "application/json" },
    });

    fireEvent.click(downloadButton);

    await screen.findByRole("alert");
    expect(screen.getByRole("alert")).toHaveTextContent("Download failed: EVIDENCE_NOT_FOUND");
  });

  it("applies MIME label filter from query string", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS} initialEntries={["/admin/evidence?mime_label=PNG%20image"]}>
        <ToastProvider>
          <EvidenceList />
        </ToastProvider>
      </MemoryRouter>
    );

    await screen.findByText("Evidence");

    await screen.findAllByRole("cell", { name: /PNG image/i });

    await waitFor(() => {
      const hits = calls.filter((u) => u.startsWith("/evidence?"));
      expect(hits.length).toBeGreaterThan(0);
      const last = hits[hits.length - 1];
      expect(last).toContain("mime_label=PNG+image");
    });
  });

  it("applies friendly MIME filter via UI", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <ToastProvider>
          <EvidenceList />
        </ToastProvider>
      </MemoryRouter>
    );

    await screen.findByText("Evidence");

    const mimeToggle = screen.getByRole("button", { name: "MIME" });
    fireEvent.click(mimeToggle);

    const input = screen.getByLabelText("Filter by MIME") as HTMLInputElement;
    fireEvent.change(input, { target: { value: "PDF document" } });

    const applyButton = screen.getByRole("button", { name: "Apply" });
    fireEvent.click(applyButton);

    await waitFor(() => {
      const hits = calls.filter((u) => u.startsWith("/evidence?"));
      expect(hits.length).toBeGreaterThan(1);
      expect(hits[hits.length - 1]).toContain("mime_label=PDF+document");
    });

    await screen.findAllByRole("cell", { name: /PDF document/i });
  });

  it("filters table rows via search input", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <ToastProvider>
          <EvidenceList />
        </ToastProvider>
      </MemoryRouter>
    );

    await screen.findByText("Evidence");

    const table = await screen.findByRole("table", { name: "Evidence results" });
    await within(table).findByText("report.pdf");
    await waitFor(() => {
      expect(within(table).getAllByRole("row")).toHaveLength(4); // header + 3 rows
    });

    const searchInput = screen.getByLabelText("Search evidence") as HTMLInputElement;
    fireEvent.change(searchInput, { target: { value: "alpha" } });

    await waitFor(() => {
      const rows = within(table).getAllByRole("row");
      expect(rows).toHaveLength(2);
      expect(rows[1]).toHaveTextContent("alpha.log");
    });
  });

  it("toggles sorting for each column", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <ToastProvider>
          <EvidenceList />
        </ToastProvider>
      </MemoryRouter>
    );

    await screen.findByText("Evidence");

    const table = await screen.findByRole("table", { name: "Evidence results" });
    await within(table).findByText("report.pdf");
    const sortFilenameButton = screen.getByRole("button", { name: "Sort Filename descending" });

    // First click -> descending
    fireEvent.click(sortFilenameButton);
    await waitFor(() => {
      const rows = within(table).getAllByRole("row");
      expect(rows[1]).toHaveTextContent("zulu.csv");
    });

    const sortFilenameAscButton = screen.getByRole("button", { name: "Sort Filename ascending" });
    fireEvent.click(sortFilenameAscButton);
    await waitFor(() => {
      const rows = within(table).getAllByRole("row");
      expect(rows[1]).toHaveTextContent("alpha.log");
    });

    const clearSortButton = screen.getByRole("button", { name: "Remove sorting for Filename" });
    fireEvent.click(clearSortButton);
    await waitFor(() => {
      const rows = within(table).getAllByRole("row");
      expect(rows[1]).toHaveTextContent("report.pdf");
    });

    // Button resets to initial label
    expect(screen.getByRole("button", { name: "Sort Filename descending" })).toBeInTheDocument();
  });
});
