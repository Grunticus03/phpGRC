import { useEffect, useState } from "react";

export default function Roles(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [roles, setRoles] = useState<string[]>([]);
  const [msg, setMsg] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      setLoading(true);
      setMsg(null);
      try {
        const res = await fetch("/api/rbac/roles");
        const json = await res.json();
        if (json?.ok && Array.isArray(json.roles)) setRoles(json.roles);
        else setMsg("Failed to load roles.");
      } catch {
        setMsg("Network error.");
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  if (loading) return <p>Loading…</p>;

  return (
    <div className="container py-3">
      <h1>RBAC Roles</h1>
      {msg && <div className="alert alert-warning">{msg}</div>}
      {roles.length === 0 ? (
        <p>No roles defined.</p>
      ) : (
        <ul className="list-group">
          {roles.map((r) => (
            <li key={r} className="list-group-item d-flex justify-content-between align-items-center">
              <span>{r}</span>
              <span className="badge bg-secondary">read-only</span>
            </li>
          ))}
        </ul>
      )}
      <p className="text-muted mt-3">Phase 4 stub. Editing disabled.</p>
    </div>
  );
}
