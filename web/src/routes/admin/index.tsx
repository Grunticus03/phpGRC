import { Link } from "react-router-dom";

export default function AdminIndex(): JSX.Element {
  return (
    <section>
      <h1>Admin</h1>
      <ul>
        <li><Link to="/admin/settings">Settings</Link></li>
        <li><Link to="/admin/roles">Roles</Link></li>
        <li><Link to="/admin/users">Users</Link></li>
        <li><Link to="/admin/user-roles">User Roles</Link></li>
        <li><Link to="/admin/audit">Audit Logs</Link></li>
        <li><a href="/api/docs">API Documentation</a></li>
      </ul>
    </section>
  );
}
