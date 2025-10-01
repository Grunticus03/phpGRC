import { Outlet, useLocation, Navigate } from "react-router-dom";
import { useEffect, useState } from "react";
import { apiGet, getToken } from "../lib/api";
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
    async function bootstrap(): Promise<void> {
      setLoading(true);
      try {
        const fp = await apiGet<Fingerprint>("/health/fingerprint");
        const req = Boolean(fp?.summary?.rbac?.require_auth);
        setRequireAuth(req);

        if (!req) {
          setAuthed(true);
          return;
        }

        const tok = getToken();
        if (!tok) {
          setAuthed(false);
          return;
        }

        try {
          await apiGet<unknown>("/auth/me");
          setAuthed(true);
        } catch {
          setAuthed(false);
        }
      } finally {
        setLoading(false);
      }
    }

    void bootstrap();
  }, [loc.pathname]);

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
