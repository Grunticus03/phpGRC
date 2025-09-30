import { Outlet, useLocation, Navigate } from "react-router-dom";
import { useEffect, useState } from "react";
import { apiGet, getAuthToken, clearAuthToken } from "../lib/api";
import Nav from "../components/Nav";

type Fingerprint = { summary?: { rbac?: { require_auth?: boolean } } };

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

        const token = getAuthToken();
        if (req) {
          if (token) {
            try {
              await apiGet<unknown>("/auth/me");
              if (!cancelled) setAuthed(true);
            } catch {
              clearAuthToken();
              if (!cancelled) setAuthed(false);
            }
          } else {
            if (!cancelled) setAuthed(false);
          }
        } else {
          if (!cancelled) setAuthed(true);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    void bootstrap();
    return () => { cancelled = true; };
  }, []);

  if (loading) return null;

  const tokenPresent = !!getAuthToken();
  if (requireAuth && (!tokenPresent || !authed) && !loc.pathname.startsWith("/auth/")) {
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
