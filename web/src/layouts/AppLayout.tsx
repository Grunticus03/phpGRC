import { Outlet, useLocation, Navigate } from "react-router-dom";
import { useEffect, useState } from "react";
import { apiGet } from "../lib/api";

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
        // 1) Read the flag from a public endpoint
        const fp = await apiGet<Fingerprint>("/health/fingerprint");
        const req = Boolean(fp?.summary?.rbac?.require_auth);
        if (!cancelled) setRequireAuth(req);

        // 2) Only probe session when auth is required
        if (req) {
          try {
            await apiGet<unknown>("/auth/me");
            if (!cancelled) setAuthed(true);
          } catch {
            if (!cancelled) setAuthed(false);
          }
        } else {
          // No auth required for viewing data
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

  // Redirect only when the feature flag demands auth and we are not authenticated
  if (requireAuth && !authed && !loc.pathname.startsWith("/auth/")) {
    return <Navigate to="/auth/login" replace state={{ from: loc }} />;
  }

  return <Outlet />;
}
