import { useEffect, useState } from "react";
import { actionInfo, actionA11yLabel } from "../../lib/audit/actionInfo";
import { apiGet } from "../../lib/api";

type AuditEvent = {
  id: string;
  occurred_at: string;
  actor_id: number | null;
  action: string;
  entity_type: string;
  entity_id: string;
  ip?: string | null;
  ua?: string | null;
  meta?: Record<string, unknown> | null;
};

export default function AuditIndex(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [msg, setMsg] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      setLoading(true);
      setMsg(null);
      try {
        const json = await apiGet<{ ok: boolean; items?: AuditEvent[] }>("/api/audit", { limit: 20 });
        if (json?.ok && Array.isArray(json.items)) setEvents(json.items);
        else setMsg("Failed to load audit events.");
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
      <h1>Audit Trail</h1>
      {msg && <div className="alert alert-warning">{msg}</div>}
      {events.length === 0 ? (
        <p>No events.</p>
      ) : (
        <div className="table-responsive">
          <table className="table table-sm table-striped">
            <thead>
              <tr>
                <th>Time (UTC)</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Actor</th>
                <th>IP</th>
              </tr>
            </thead>
            <tbody>
              {events.map(e => {
                const info = actionInfo(e.action);
                return (
                  <tr key={e.id}>
                    <td>{e.occurred_at}</td>
                    <td>
                      <span
                        title={e.action}
                        aria-label={actionA11yLabel(e.action)}
                      >
                        {info.label}
                      </span>
                    </td>
                    <td>{`${e.entity_type}:${e.entity_id}`}</td>
                    <td>{e.actor_id ?? "-"}</td>
                    <td>{e.ip ?? "-"}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      <p className="text-muted mt-3">Phase 4 stub. Pagination and persistence deferred.</p>
    </div>
  );
}
