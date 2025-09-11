import { Link } from "react-router-dom";

export default function AdminIndex(): JSX.Element {
  return (
    <section>
      <h1>Admin</h1>
      <ul>
        <li><Link to="/admin/settings">Settings</Link></li>
        <li><Link to="/admin/roles">Roles</Link></li>
      </ul>
      <p className="text-muted">Phase 4 stubs. RBAC enforced by API.</p>
    </section>
  );
}
