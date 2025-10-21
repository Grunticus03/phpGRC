import React from "react";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { vi } from "vitest";
import IdpProviders from "../IdpProviders";
import { ToastProvider } from "../../../components/toast/ToastProvider";

const ROUTER_FUTURE_FLAGS = { v7_startTransition: true, v7_relativeSplatPath: true } as const;

const originalFetch = globalThis.fetch as typeof fetch;

function jsonResponse(status: number, body?: unknown) {
  return new Response(body !== undefined ? JSON.stringify(body) : null, {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function renderPage() {
  render(
    <MemoryRouter future={ROUTER_FUTURE_FLAGS} initialEntries={["/admin/idp/providers"]}>
      <Routes>
        <Route
          path="/admin/idp/providers"
          element={
            <ToastProvider>
              <IdpProviders />
            </ToastProvider>
          }
        />
      </Routes>
    </MemoryRouter>
  );
}

afterEach(() => {
  globalThis.fetch = originalFetch;
  vi.restoreAllMocks();
});

describe("Admin IdP Providers page", () => {
  test("shows stub mode banner when persistence is disabled", async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));

      if (method === "GET" && /\/admin\/idp\/providers$/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          items: [],
          meta: { total: 0, enabled: 0 },
          note: "stub-only",
        });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    renderPage();

    expect(await screen.findByText(/stub mode/i)).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  test("allows toggling provider status", async () => {
    const user = userEvent.setup();
    const providers = [
      {
        id: "01JP1A4J52",
        key: "okta-primary",
        name: "Okta Primary",
        driver: "oidc",
        enabled: true,
        evaluation_order: 1,
        config: {},
        meta: null,
        reference: 1,
        last_health_at: null,
        created_at: "2025-02-01T00:00:00Z",
        updated_at: "2025-02-01T00:00:00Z",
      },
      {
        id: "01JP1A4J5Z",
        key: "entra-fallback",
        name: "Entra Fallback",
        driver: "entra",
        enabled: false,
        evaluation_order: 2,
        config: {},
        meta: null,
        reference: 2,
        last_health_at: null,
        created_at: "2025-02-01T00:00:00Z",
        updated_at: "2025-02-01T00:00:00Z",
      },
    ];

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));

      if (method === "GET" && /\/admin\/idp\/providers$/.test(url)) {
        const enabledCount = providers.filter((item) => item.enabled).length;
        return jsonResponse(200, {
          ok: true,
          items: providers,
          meta: { total: providers.length, enabled: enabledCount },
        });
      }

      if (method === "PATCH" && /\/admin\/idp\/providers\/01JP1A4J52$/.test(url)) {
        const body = JSON.parse(String(init?.body ?? "{}"));
        expect(body).toEqual({ enabled: false });
        providers[0] = {
          ...providers[0],
          enabled: false,
          updated_at: "2025-02-02T00:00:00Z",
        };
        return jsonResponse(200, {
          ok: true,
          provider: providers[0],
        });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    renderPage();

    expect(await screen.findByText("Okta Primary")).toBeInTheDocument();

    const oktaRow = screen.getByText("Okta Primary").closest("tr");
    expect(oktaRow).not.toBeNull();
    const disableButton = within(oktaRow!).getByRole("button", { name: /disable/i });
    await user.click(disableButton);

    await waitFor(() => {
      const updatedRow = screen.getByText("Okta Primary").closest("tr");
      expect(updatedRow).not.toBeNull();
      if (updatedRow) {
        const enableButton = within(updatedRow).getByRole("button", { name: /^enable$/i });
        expect(enableButton).toBeInTheDocument();
      }
    });

    const finalRow = screen.getByText("Okta Primary").closest("tr");
    expect(finalRow).not.toBeNull();
    if (finalRow) {
      const statusBadge = within(finalRow).getByText(/disabled/i);
      expect(statusBadge).toBeInTheDocument();
    }
  });

  test("reorders providers and deletes an entry", async () => {
    const user = userEvent.setup();
    const providers = [
      {
        id: "01JORDER01",
        key: "primary",
        name: "Primary",
        driver: "oidc",
        enabled: true,
        evaluation_order: 1,
        config: {},
        meta: null,
        reference: 1,
        last_health_at: null,
        created_at: "2025-01-01T00:00:00Z",
        updated_at: "2025-01-01T00:00:00Z",
      },
      {
        id: "01JORDER02",
        key: "secondary",
        name: "Secondary",
        driver: "saml",
        enabled: true,
        evaluation_order: 2,
        config: {},
        meta: null,
        reference: 2,
        last_health_at: null,
        created_at: "2025-01-02T00:00:00Z",
        updated_at: "2025-01-02T00:00:00Z",
      },
    ];

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));

      if (method === "GET" && /\/admin\/idp\/providers$/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          items: providers,
          meta: { total: providers.length, enabled: providers.filter((item) => item.enabled).length },
        });
      }

      if (method === "PATCH" && /\/admin\/idp\/providers\/01JORDER02$/.test(url)) {
        const body = JSON.parse(String(init?.body ?? "{}"));
        expect(body).toEqual({ evaluation_order: 1 });
        providers[0] = { ...providers[0], evaluation_order: 2, updated_at: "2025-01-03T00:00:00Z" };
        providers[1] = { ...providers[1], evaluation_order: 1, updated_at: "2025-01-03T00:00:00Z" };
        return jsonResponse(200, {
          ok: true,
          provider: providers[1],
        });
      }

      if (method === "DELETE" && /\/admin\/idp\/providers\/01JORDER02$/.test(url)) {
        providers.splice(1, 1);
        return jsonResponse(200, {
          ok: true,
          deleted: "secondary",
        });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    renderPage();

    expect(await screen.findByText("Primary")).toBeInTheDocument();
    const moveUpButton = screen.getByRole("button", { name: /move secondary higher/i });
    await user.click(moveUpButton);

    await waitFor(() => {
      const rows = screen.getAllByRole("row");
      const firstDataRow = rows.find((row) => within(row).queryByText("Secondary"));
      expect(firstDataRow).toBeDefined();
      if (firstDataRow) {
        const orderCell = within(firstDataRow).getByText("1");
        expect(orderCell).toBeInTheDocument();
      }
    });

    const secondaryRow = screen.getByText("Secondary").closest("tr");
    expect(secondaryRow).not.toBeNull();
    const deleteButton = within(secondaryRow!).getByRole("button", { name: /^delete$/i });
    await user.click(deleteButton);

    const confirmDialog = await screen.findByRole("dialog");
    expect(within(confirmDialog).getByText(/remove/i)).toBeInTheDocument();

    const confirmButton = within(confirmDialog).getByRole("button", { name: /^delete$/i });
    await user.click(confirmButton);

    await waitFor(() => {
      expect(screen.queryByText("Secondary")).not.toBeInTheDocument();
    });
  });

  test("creates a provider through the modal form", async () => {
    const user = userEvent.setup();
    const providers: Array<Record<string, unknown>> = [];

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));

      if (method === "GET" && /\/admin\/idp\/providers$/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          items: providers,
          meta: { total: providers.length, enabled: providers.filter((item) => item.enabled).length },
        });
      }

      if (method === "POST" && /\/admin\/idp\/providers$/.test(url)) {
        const body = JSON.parse(String(init?.body ?? "{}"));
        expect(body).toEqual({
          name: "New IdP",
          driver: "oidc",
          enabled: true,
          config: {
            issuer: "https://issuer.example/",
            client_id: "client",
            client_secret: "super-secret",
            scopes: ["openid", "profile", "email"],
            redirect_uris: ["https://app.example.com/auth/callback"],
          },
          meta: {
            display_region: "us",
          },
        });

        const generatedKey = "01J7ZF2YB4N9M1X5V6Q8R0S2TU";
        const created = {
          id: "01JCREATE01",
          key: generatedKey,
          name: body.name,
          driver: body.driver,
          enabled: body.enabled,
          evaluation_order: 1,
          config: body.config,
          meta: {
            ...body.meta,
            reference: 1,
          },
          reference: 1,
          last_health_at: null,
          created_at: "2025-03-01T00:00:00Z",
          updated_at: "2025-03-01T00:00:00Z",
        };

        providers.unshift(created);
        return jsonResponse(201, {
          ok: true,
          provider: created,
        });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    renderPage();

    const addProviderButtons = await screen.findAllByRole("button", { name: /add provider/i });
    expect(addProviderButtons.length).toBeGreaterThan(0);
    await user.click(addProviderButtons[0]);

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/add identity provider/i)).toBeInTheDocument();

    const nameInput = within(dialog).getByLabelText(/display name/i);
    await user.clear(nameInput);
    await user.type(nameInput, "New IdP");

    const driverSelect = within(dialog).getByLabelText(/idp type/i);
    await user.selectOptions(driverSelect, "oidc");

    const issuerInput = within(dialog).getByLabelText(/issuer url/i);
    await user.type(issuerInput, "https://issuer.example");

    const clientIdInput = within(dialog).getByLabelText(/client id/i);
    await user.type(clientIdInput, "client");

    const clientSecretInput = within(dialog).getByLabelText(/client secret/i);
    await user.type(clientSecretInput, "super-secret");

    const redirectUrisArea = within(dialog).getByLabelText(/redirect uris/i);
    await user.type(redirectUrisArea, "https://app.example.com/auth/callback");

    const advancedToggle = within(dialog).getByRole("button", { name: /^advanced$/i });
    await user.click(advancedToggle);

    const metaArea = within(dialog).getByPlaceholderText('{"display_region": "us-east"}');
    fireEvent.change(metaArea, { target: { value: '{"display_region":"us"}' } });

    const submitButton = within(dialog).getByRole("button", { name: /^create$/i });
    await user.click(submitButton);

    await waitFor(() => {
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    await waitFor(() => {
      expect(screen.getByText("New IdP")).toBeInTheDocument();
    });
  });

  test("Active Directory preset prepopulates the thumbnail photo attribute", async () => {
    const user = userEvent.setup();

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));

      if (method === "GET" && /\/admin\/idp\/providers$/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          items: [],
          meta: { total: 0, enabled: 0 },
        });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    renderPage();

    const addProviderButtons = await screen.findAllByRole("button", { name: /add provider/i });
    await user.click(addProviderButtons[0]);

    const dialog = await screen.findByRole("dialog");
    const driverSelect = within(dialog).getByLabelText(/idp type/i);
    await user.selectOptions(driverSelect, "ldap");

    const adPresetButton = within(dialog).getByRole("button", { name: /active directory/i });
    await user.click(adPresetButton);

    const photoAttributeInput = within(dialog).getByLabelText(/thumbnail photo attribute/i) as HTMLInputElement;
    expect(photoAttributeInput.value).toBe("thumbnailPhoto");
  });

  test("surfaces server validation errors near the create button and affected fields", async () => {
    const user = userEvent.setup();

    let postCount = 0;
    let lastPostInit: RequestInit | undefined;

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));

      if (method === "GET" && /\/admin\/idp\/providers$/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          items: [],
          meta: { total: 0, enabled: 0 },
        });
      }

      if (method === "POST" && /\/admin\/idp\/providers$/.test(url)) {
        postCount += 1;
        lastPostInit = init;
        return jsonResponse(422, {
          message: "Validation failed.",
          errors: {
            "config.bind_dn": ["Bind DN is invalid."],
          },
        });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    renderPage();

    const addProviderButtons = await screen.findAllByRole("button", { name: /add provider/i });
    await user.click(addProviderButtons[0]);

    const dialog = await screen.findByRole("dialog");
    const nameInput = within(dialog).getByLabelText(/display name/i);
    await user.clear(nameInput);
    await user.type(nameInput, "LDAP Provider");

    const driverSelect = within(dialog).getByLabelText(/idp type/i);
    await user.selectOptions(driverSelect, "ldap");

    const hostInput = within(dialog).getByLabelText(/server/i);
    await user.clear(hostInput);
    await user.type(hostInput, "ldaps://ldap.example.com/");

    const baseDnInput = within(dialog).getByLabelText(/base dn/i);
    await user.clear(baseDnInput);
    await user.type(baseDnInput, "dc=example,dc=com");

    const bindDnInput = within(dialog).getByLabelText(/bind dn/i);
    await user.clear(bindDnInput);
    await user.type(bindDnInput, "cn=service,dc=example,dc=com");

    const bindPasswordInput = within(dialog).getByLabelText(/bind password/i);
    await user.clear(bindPasswordInput);
    await user.type(bindPasswordInput, "secret123");

    const submitButton = within(dialog).getByRole("button", { name: /^create$/i });
    await user.click(submitButton);

    await waitFor(() => expect(postCount).toBeGreaterThan(0));
    const payload = JSON.parse(String(lastPostInit?.body ?? "{}"));

    const bindDnFeedback = await within(dialog).findByText(/bind dn is invalid\./i);
    const generalError = await within(dialog).findByText(/validation failed\./i);
    expect(generalError.closest(".modal-footer")).not.toBeNull();
    expect(payload).toMatchObject({ driver: "ldap" });
    expect(bindDnFeedback).toBeInTheDocument();
    expect(bindDnInput).toHaveClass("is-invalid");
  });

  test("allows selecting an LDAP base DN from the browser tree", async () => {
    const user = userEvent.setup();
    const browseRequests: Array<string | null> = [];

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const method = (init?.method ?? "GET").toUpperCase();
      const url = typeof input === "string" ? input : ((input as Request).url ?? String(input));

      if (method === "GET" && /\/admin\/idp\/providers$/.test(url)) {
        return jsonResponse(200, {
          ok: true,
          items: [],
          meta: { total: 0, enabled: 0 },
        });
      }

      if (method === "POST" && /\/admin\/idp\/providers\/ldap\/browse$/.test(url)) {
        const body = JSON.parse(String(init?.body ?? "{}")) as { base_dn?: string | null };
        const baseDn = body.base_dn ?? null;
        browseRequests.push(baseDn);

        if (!baseDn) {
          return jsonResponse(200, {
            ok: true,
            root: true,
            base_dn: null,
            entries: [
              {
                dn: "dc=example,dc=com",
                rdn: "dc=example",
                name: "example",
                type: "organizationalUnit",
                object_class: ["top", "domain"],
                has_children: true,
              },
              {
                dn: "cn=Alice,dc=example,dc=com",
                rdn: "cn=Alice",
                name: "Alice",
                type: "person",
                object_class: ["person"],
                has_children: false,
              },
            ],
            diagnostics: { stage: "root" },
          });
        }

        if (baseDn === "dc=example,dc=com") {
          return jsonResponse(200, {
            ok: true,
            root: false,
            base_dn: baseDn,
            entries: [
              {
                dn: "ou=Users,dc=example,dc=com",
                rdn: "ou=Users",
                name: "Users",
                type: "group",
                object_class: ["group"],
                has_children: false,
              },
            ],
            diagnostics: { stage: "users" },
          });
        }

        return jsonResponse(200, {
          ok: true,
          root: false,
          base_dn: baseDn,
          entries: [],
        });
      }

      return jsonResponse(404);
    }) as unknown as typeof fetch;

    globalThis.fetch = fetchMock;
    renderPage();

    const addProviderButtons = await screen.findAllByRole("button", { name: /add provider/i });
    await user.click(addProviderButtons[0]);

    const createModal = await screen.findByRole("dialog", { name: /add identity provider/i });
    const nameInput = within(createModal).getByLabelText(/display name/i);
    await user.clear(nameInput);
    await user.type(nameInput, "LDAP Provider");

    const driverSelect = within(createModal).getByLabelText(/idp type/i);
    await user.selectOptions(driverSelect, "ldap");

    const hostInput = within(createModal).getByLabelText(/server/i);
    await user.clear(hostInput);
    await user.type(hostInput, "ldaps://ldap.example.com");

    const baseDnInput = within(createModal).getByLabelText(/base dn/i);
    await user.clear(baseDnInput);
    await user.type(baseDnInput, "dc=example,dc=com");

    const bindDnInput = within(createModal).getByLabelText(/bind dn/i);
    await user.clear(bindDnInput);
    await user.type(bindDnInput, "cn=service,dc=example,dc=com");

    const bindPasswordInput = within(createModal).getByLabelText(/bind password/i);
    await user.clear(bindPasswordInput);
    await user.type(bindPasswordInput, "super-secret");

    const browseButton = within(createModal).getByRole("button", { name: /^browse$/i });
    await user.click(browseButton);

    const browserModal = await screen.findByRole("dialog", { name: /ldap browser/i });
    const namingContextsButton = within(browserModal).getByRole("button", { name: /naming contexts/i });
    await user.click(namingContextsButton);

    let exampleToggle = await within(browserModal).findByRole("button", { name: /(expand|collapse) example/i });
    const toggleLabel = exampleToggle.getAttribute("aria-label") ?? exampleToggle.textContent ?? "";
    if (/collapse/i.test(toggleLabel)) {
      await user.click(exampleToggle);
      exampleToggle = await within(browserModal).findByRole("button", { name: /expand example/i });
    }
    await user.click(exampleToggle);

    const usersButton = await within(browserModal).findByRole("button", { name: /^users$/i });
    await user.click(usersButton);

    await waitFor(() => expect(baseDnInput).toHaveValue("ou=Users,dc=example,dc=com"));
    const updatedUsersButton = within(browserModal).getByRole("button", { name: /^users$/i });
    expect(updatedUsersButton.querySelector(".bi-forward-fill")).not.toBeNull();

    expect(await within(browserModal).findByRole("button", { name: /last directory response/i })).toBeInTheDocument();
    expect(await within(browserModal).findByRole("button", { name: /ldap diagnostics/i })).toBeInTheDocument();
    await waitFor(() => expect(browseRequests).toEqual(["dc=example,dc=com", null, "dc=example,dc=com"]));
  });
});
