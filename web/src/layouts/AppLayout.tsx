import { Outlet, useLocation, useNavigate } from "react-router-dom";
import { useEffect, useState } from "react";
import { apiGet, authMe, hasAuthToken, onUnauthorized, rememberIntendedPath, markSessionExpired } from "../lib/api";
import Nav from "../components/Nav";
import { bootstrapTheme } from "../theme/themeManager";

type Fingerprint = {
  summary?: { rbac?: { require_auth?: boolean } };
};

export default function AppLayout() {
  const loc = useLocation();
  const navigate = useNavigate();

  const [loading, setLoading] = useState(true);
  const [requireAuth, setRequireAuth] = useState<boolean>(false);
  const [authed, setAuthed] = useState<boolean>(false);

  useEffect(() => {
    // Redirect to login whenever the API returns 401
    const off = onUnauthorized(() => {
      if (!loc.pathname.startsWith("/auth/")) {
        const intended = `${loc.pathname}${loc.search}${loc.hash}`;
        rememberIntendedPath(intended);
        markSessionExpired();
        navigate("/auth/login", { replace: true });
      }
    });

    async function bootstrap(): Promise<void> {
      setLoading(true);
      try {
        // 1) Check server fingerprint to see if auth is required
        const fp = await apiGet<Fingerprint>("/api/health/fingerprint");
        const req = Boolean(fp?.summary?.rbac?.require_auth);
        setRequireAuth(req);

        // 2) If auth required, only probe /auth/me when we actually have a token
        if (req) {
          if (!hasAuthToken()) {
            setAuthed(false);
            return;
          }

          try {
            await authMe();
            setAuthed(true);
          } catch {
            setAuthed(false);
          }
        } else {
          // Auth not required globally
          setAuthed(true);
        }
      } finally {
        setLoading(false);
      }
    }

    void bootstrap();
    return () => off();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loc.pathname]);

  useEffect(() => {
    void bootstrapTheme({ fetchUserPrefs: authed });
  }, [requireAuth, authed]);

  if (loading) return null;

  // If auth is required and we aren't authed, push to login (and remember the path)
  if (requireAuth && !authed && !loc.pathname.startsWith("/auth/")) {
    const intended = `${loc.pathname}${loc.search}${loc.hash}`;
    rememberIntendedPath(intended);
    markSessionExpired();
    navigate("/auth/login", { replace: true });
    return null;
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
