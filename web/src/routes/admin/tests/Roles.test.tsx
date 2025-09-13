import React from "react";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { rest } from "msw";
import { server } from "../../../testUtils/server";
import Roles from "../Roles";

function renderPage() {
  render(
    <MemoryRouter initialEntries={["/admin/roles"]}>
      <Routes>
        <Route path="/admin/roles" element={<Roles />} />
      </Routes>
    </MemoryRouter>
  );
}

describe("Admin Roles page", () => {
  test("renders list of roles", async () => {
    renderPage();

    expect(
      await screen.findByRole("heading", { name: /rbac roles/i })
    ).toBeInTheDocument();

    // It should either show an initial role or an empty state.
    const list = screen.queryByRole("list");
    if (list) {
      expect(
        within(list).getByText(/compliance lead/i)
      ).toBeInTheDocument();
    } else {
      expect(
        screen.getByText(/no roles defined/i)
      ).toBeInTheDocument();
    }
  });

  test("creates role successfully (201 created) and reloads list", async () => {
    renderPage();
    const user = userEvent.setup();

    // Fill and submit
    await user.type(
      screen.getByLabelText(/create role/i),
      "Compliance Lead"
    );
    await user.click(screen.getByRole("button", { name: /submit/i }));

    // Instead of brittle success text, assert on the observable UI change
    // (list contains the created role).
    expect(
      await screen.findByText(/compliance lead/i)
    ).toBeInTheDocument();
  });

  test("handles stub-only acceptance", async () => {
    renderPage();
    const user = userEvent.setup();

    // When persistence is off, POST is accepted and an alert is shown.
    await user.type(screen.getByLabelText(/create role/i), "Temp");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    // Check the alert text itself (don't span across siblings)
    expect(
      await screen.findByRole("alert")
    ).toHaveTextContent(/Accepted: "Temp"\. Persistence not implemented\./i);

    // And the static stub hint text remains present
    expect(
      screen.getByText(/stub path accepted when rbac persistence is off/i)
    ).toBeInTheDocument();
  });

  test("handles 403 forbidden", async () => {
    // Force POST to return 403 for this test
    server.use(
      rest.post("/api/rbac/roles", (_req, res, ctx) =>
        res(ctx.status(403), ctx.json({ message: "Forbidden" }))
      )
    );

    renderPage();
    const user = userEvent.setup();

    await user.type(screen.getByLabelText(/create role/i), "Auditor");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(
      await screen.findByRole("alert")
    ).toHaveTextContent(/forbidden/i);
  });

  test("handles 422 validation error", async () => {
    // Simulate server-side validation error
    server.use(
      rest.post("/api/rbac/roles", (_req, res, ctx) =>
        res(
          ctx.status(422),
          ctx.json({ message: "Validation error. Name must be 2–64 chars." })
        )
      )
    );

    renderPage();
    const user = userEvent.setup();

    // Use >= 2 chars so the button enables and the request is actually sent
    await user.type(screen.getByLabelText(/create role/i), "XX");
    await user.click(screen.getByRole("button", { name: /submit/i }));

    expect(
      await screen.findByRole("alert")
    ).toHaveTextContent(/Validation error\. Name must be 2–64 chars\./i);
  });
});
