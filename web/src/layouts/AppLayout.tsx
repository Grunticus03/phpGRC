import { Outlet, useLocation, Navigate } from "react-router-dom";
import { useEffect, useState } from "react";
import { apiGet, HttpError } from "../lib/api";
import Nav from "../components/Nav";

type Fingerprint = {
  summary?: { rbac?: { require_auth?: boolean } };
};

export default function AppLayout() {
  const loc = useLocation();

  const [loading, setLoading] = useState(true);
  const [requireAuth, setRequireAuth] = useState<boolean>(false);
  const [authed, setAuthed] = useState<boolean>(false);

  useEffect(() => {
    let cancelled = false;

    async function bootstrap(): Promise<void> {
      try {
        const fp = await apiGet<Fingerprint>("/health/fingerprint");
        const req = Boolean(fp?.summary?.rbac?.require_auth);
        if (!cancelled) setRequireAuth(req);

        if (req) {
          try {
            // Probe a protected endpoint. 401 => unauthenticated. 200/403 => authenticated.
            await apiGet<unknown>("/admin/settings");
            if (!cancelled) setAuthed(true);
          } catch (e) {
            const err = e as unknown;
            if (err instanceof HttpError && err.status === 401) {
              if (!cancelled) setAuthed(false);
            } else {
              // Forbidden or other non-401 means we are authenticated but may lack permission.
              if (!cancelled) setAuthed(true);
            }
          }
        } else {
          if (!cancelled) setAuthed(true);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    void bootstrap();
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) return null;

  if (requireAuth && !authed && !loc.pathname.startsWith("/auth/")) {
    return <Navigate to="/auth/login" replace state={{ from: loc }} />;
  }

  const hideNav = loc.pathname.startsWith("/auth/");

  return (
    <>
      {!hideNav && <Nav requireAuth={requireAuth} authed={authed} />}
      <main id="main" role="main">
        <Outlet />
      </main>
    </>
  );
}
