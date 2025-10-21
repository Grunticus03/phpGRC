/** @vitest-environment jsdom */
import React from "react";
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, waitFor, fireEvent, within, act } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

import Kpis, { DASHBOARD_TOGGLE_EDIT_MODE_EVENT } from "../Kpis";

const KPIS_URL = "/dashboard/kpis";
const REPORT_URL = "/reports/admin-activity";
const UI_PREFS_URL = "/me/prefs/ui";

type StoredWidget = {
  id?: string | null;
  type?: string;
  x?: number;
  y?: number;
  w?: number;
  h?: number;
};
const originalFetch = globalThis.fetch as typeof fetch;
const originalCreateObjectURL = globalThis.URL.createObjectURL;
const originalRevokeObjectURL = globalThis.URL.revokeObjectURL;
const mockNavigate = vi.fn();
const ROUTER_FUTURE_FLAGS = { v7_startTransition: true, v7_relativeSplatPath: true } as const;
let savedWidgets: StoredWidget[] = [];

vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  };
});

vi.mock("react-chartjs-2", () => ({
  Bar: (props: { data?: unknown; options?: { onClick?: (...args: unknown[]) => void } }) => (
    <button
      type="button"
      data-testid="bar-chart"
      data-data={JSON.stringify(props.data)}
      onClick={() => props.options?.onClick?.({}, [{ index: 0 } as never])}
    >
      Bar Chart
    </button>
  ),
  Pie: (props: { data: unknown }) => (
    <div data-testid="pie-chart" data-data={JSON.stringify(props.data)}>
      Pie Chart
    </div>
  ),
}));

function json(body: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { "Content-Type": "application/json" },
    ...init,
  });
}

function localDayQuery(source: string): string {
  const iso = source.includes("T") ? source : `${source}T00:00:00Z`;
  const parsed = new Date(iso);
  if (Number.isNaN(parsed.valueOf())) return source;
  const localDate = new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate());
  return localDate.toISOString().slice(0, 10);
}

function localDayLabel(source: string): string {
  const iso = source.includes("T") ? source : `${source}T00:00:00Z`;
  const parsed = new Date(iso);
  if (Number.isNaN(parsed.valueOf())) return source;
  const localDate = new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate());
  return new Intl.DateTimeFormat(undefined).format(localDate);
}

describe("Dashboard", () => {
  beforeEach(() => {
    mockNavigate.mockReset();
    savedWidgets = [];
    Object.defineProperty(globalThis.URL, "createObjectURL", {
      configurable: true,
      value: vi.fn(() => "blob:mock-url"),
    });
    Object.defineProperty(globalThis.URL, "revokeObjectURL", {
      configurable: true,
      value: vi.fn(),
    });

    globalThis.fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const request = typeof input === "string" || input instanceof URL ? null : (input as Request);
      const url =
        typeof input === "string"
          ? input
          : input instanceof URL
            ? input.toString()
            : request?.url ?? String(input);
      const method = request?.method ?? init?.method ?? "GET";

      if (url.startsWith(KPIS_URL)) {
        return json({
          ok: true,
          data: {
            auth_activity: {
              window_days: 7,
              from: "2025-01-01T00:00:00Z",
              to: "2025-01-07T23:59:59Z",
              daily: [
                { date: "2025-01-01", success: 3, failed: 1, total: 4 },
                { date: "2025-01-02", success: 2, failed: 1, total: 3 },
              ],
              totals: { success: 5, failed: 2, total: 7 },
              max_daily_total: 4,
            },
            evidence_mime: {
              total: 5,
              by_mime: [
                { mime: "application/pdf", mime_label: "PDF document", count: 3, percent: 0.6 },
                { mime: "image/png", mime_label: "PNG image", count: 2, percent: 0.4 },
              ],
            },
            admin_activity: {
              admins: [
                {
                  id: 1,
                  name: "Alice Admin",
                  email: "alice@example.test",
                  last_login_at: "2025-01-07T12:00:00Z",
                },
                {
                  id: 2,
                  name: "Bob Admin",
                  email: "bob@example.test",
                  last_login_at: null,
                },
              ],
            },
          },
        });
      }

      if (url.startsWith(UI_PREFS_URL)) {
        if (method === "PUT") {
          let widgets: StoredWidget[] = [];
          try {
            let payload: unknown = null;
            if (request) {
              payload = await request.clone().json();
            } else if (typeof init?.body === "string") {
              payload = JSON.parse(init.body);
            }
            const bodyDashboard = (payload as { dashboard?: { widgets?: StoredWidget[] } } | null)?.dashboard;
            if (Array.isArray(bodyDashboard?.widgets)) {
              widgets = bodyDashboard.widgets.map((widget) => ({ ...widget }));
            }
          } catch {
            widgets = [];
          }
          savedWidgets = widgets.map((widget) => ({ ...widget }));

          return json(
            {
              ok: true,
              prefs: {
                theme: null,
                mode: null,
                overrides: {},
                sidebar: { collapsed: false, pinned: true, width: 280, order: [], hidden: [] },
                dashboard: { widgets: savedWidgets.map((widget) => ({ ...widget })) },
              },
              etag: '"prefs-etag"',
            },
            {
              headers: {
                "Content-Type": "application/json",
                ETag: '"prefs-etag"',
              },
            }
          );
        }

        return json(
          {
            ok: true,
            prefs: {
              theme: null,
              mode: null,
              overrides: {},
              sidebar: { collapsed: false, pinned: true, width: 280, order: [], hidden: [] },
              dashboard: { widgets: savedWidgets.map((widget) => ({ ...widget })) },
            },
            etag: '"prefs-etag"',
          },
          {
            headers: {
              "Content-Type": "application/json",
              ETag: '"prefs-etag"',
            },
          }
        );
      }

      if (url.startsWith(REPORT_URL)) {
        return new Response("id,name\\n1,Alice Admin", {
          status: 200,
          headers: {
            "Content-Type": "text/csv",
            "Content-Disposition": 'attachment; filename="admin-activity-report.csv"',
          },
        });
      }

      return json({ ok: true });
    }) as unknown as typeof fetch;
  });

  afterEach(() => {
    if (originalCreateObjectURL) {
      Object.defineProperty(globalThis.URL, "createObjectURL", {
        configurable: true,
        value: originalCreateObjectURL,
      });
    } else {
      delete (globalThis.URL as unknown as Record<string, unknown>).createObjectURL;
    }

    if (originalRevokeObjectURL) {
      Object.defineProperty(globalThis.URL, "revokeObjectURL", {
        configurable: true,
        value: originalRevokeObjectURL,
      });
    } else {
      delete (globalThis.URL as unknown as Record<string, unknown>).revokeObjectURL;
    }
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it("renders charts and admin table when data loads", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    expect(screen.getByText(/Authentications last 7 days/i)).toBeInTheDocument();
    expect(screen.getByText(/Evidence File Types/i)).toBeInTheDocument();
    expect(screen.getByText(/Admin Activity/i)).toBeInTheDocument();

    expect(screen.getByText("Alice Admin")).toBeInTheDocument();
    expect(screen.getByText("Bob Admin")).toBeInTheDocument();
    expect(screen.getByText("alice@example.test")).toBeInTheDocument();
  });

  it("navigates to audit view when bar chart clicked", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    fireEvent.click(screen.getByTestId("bar-chart"));

    expect(mockNavigate).toHaveBeenCalledWith(
      expect.stringContaining("/admin/audit?category=AUTH")
    );
    const target = mockNavigate.mock.calls[0][0] as string;
    const expectedDay = localDayQuery("2025-01-01");
    expect(target).toContain(`occurred_from=${expectedDay}`);
    expect(target).toContain(`occurred_to=${expectedDay}`);
  });

  it("formats authentication chart labels using local day boundaries", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    const chart = screen.getByTestId("bar-chart");
    const dataAttr = chart.getAttribute("data-data");
    expect(dataAttr).toBeTruthy();
    const payload = JSON.parse(dataAttr ?? "{}") as { labels?: string[] };
    expect(Array.isArray(payload.labels)).toBe(true);
    expect(payload.labels?.[0]).toBe(localDayLabel("2025-01-01"));
    expect(payload.labels?.[1]).toBe(localDayLabel("2025-01-02"));
  });

  it("shows forbidden message on 403", async () => {
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
      if (url.startsWith(KPIS_URL)) {
        return json({ ok: false, code: "FORBIDDEN" }, { status: 403 });
      }
      return json({ ok: true });
    }) as unknown as typeof fetch;

    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await screen.findByText(/access to KPIs/i);
  });

  it("enters edit mode, opens widget modal, and exits without saving", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    act(() => {
      window.dispatchEvent(new CustomEvent(DASHBOARD_TOGGLE_EDIT_MODE_EVENT));
    });
    await screen.findByRole("button", { name: /save dashboard layout/i });

    const widgetButton = screen.getByRole("button", { name: /open widget picker/i });
    fireEvent.click(widgetButton);

    const modal = await screen.findByRole("dialog", { name: /add widgets/i });
    const authOption = within(modal).getByText("Authentication Activity");
    fireEvent.click(authOption);

    const addButton = within(modal).getByRole("button", { name: /add/i });
    expect(addButton).toBeEnabled();
    fireEvent.click(addButton);

    await waitFor(() => {
      expect(screen.queryByRole("dialog", { name: /add widgets/i })).not.toBeInTheDocument();
    });

    const closeButton = screen.getByRole("button", { name: /discard dashboard layout changes/i });
    fireEvent.click(closeButton);

    await waitFor(() => {
      expect(screen.queryByRole("button", { name: /save dashboard layout/i })).not.toBeInTheDocument();
    });
  });

  it("saves dashboard layout changes and restores them on reload", async () => {
    const view = render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    expect(screen.getByText(/Admin Activity/i)).toBeInTheDocument();

    act(() => {
      window.dispatchEvent(new CustomEvent(DASHBOARD_TOGGLE_EDIT_MODE_EVENT));
    });

    await screen.findByRole("button", { name: /save dashboard layout/i });
    const removeAdmin = screen.getByRole("button", { name: /remove admin activity/i });
    fireEvent.click(removeAdmin);
    await waitFor(() => {
      expect(screen.queryByText(/Admin Activity/i)).not.toBeInTheDocument();
    });
    await waitFor(() => {
      expect(view.container.querySelectorAll(".dashboard-widget").length).toBe(2);
    });

    const saveButton = screen.getByRole("button", { name: /save dashboard layout/i });
    fireEvent.click(saveButton);

    await waitFor(() => {
      expect(screen.queryByRole("button", { name: /save dashboard layout/i })).not.toBeInTheDocument();
    });
    await waitFor(() => {
      expect(savedWidgets.length).toBe(2);
    });

    view.unmount();

    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    expect(screen.queryByText(/Admin Activity/i)).not.toBeInTheDocument();
    expect(screen.getByText(/Authentications last 7 days/i)).toBeInTheDocument();
  });

  it("downloads admin activity report when button clicked", async () => {
    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    const button = screen.getByRole("button", { name: /download csv/i });
    fireEvent.click(button);

    await waitFor(() => {
      expect(globalThis.URL.createObjectURL).toHaveBeenCalled();
    });

    const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;
    expect(fetchMock.mock.calls.some((call) => {
      const url = typeof call[0] === "string" ? call[0] : (call[0] as Request).url;
      return typeof url === "string" && url.startsWith(`${REPORT_URL}?format=csv`);
    })).toBe(true);
  });

  it("shows error when report download is forbidden", async () => {
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === "string" ? input : (input as Request).url ?? String(input);

      if (url.startsWith(KPIS_URL)) {
        return json({
          ok: true,
          data: {
            auth_activity: {
              window_days: 7,
              from: "2025-01-01T00:00:00Z",
              to: "2025-01-07T23:59:59Z",
              daily: [{ date: "2025-01-01", success: 1, failed: 0, total: 1 }],
              totals: { success: 1, failed: 0, total: 1 },
              max_daily_total: 1,
            },
            evidence_mime: { total: 0, by_mime: [] },
            admin_activity: { admins: [] },
          },
        });
      }

      if (url.startsWith(REPORT_URL)) {
        return json({ ok: false, code: "FORBIDDEN" }, { status: 403 });
      }

      return json({ ok: true });
    }) as unknown as typeof fetch;

    render(
      <MemoryRouter future={ROUTER_FUTURE_FLAGS}>
        <Kpis />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.queryByText("Loading…")).not.toBeInTheDocument();
    });

    const button = screen.getByRole("button", { name: /download csv/i });
    fireEvent.click(button);

    await screen.findByRole("alert");
    expect(screen.getByRole("alert")).toHaveTextContent(/do not have access/i);
  });
});
