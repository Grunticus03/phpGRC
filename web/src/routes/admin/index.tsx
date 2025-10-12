import { Link } from "react-router-dom";

export default function AdminIndex(): JSX.Element {
  return (
    <section>
      <h1>Admin</h1>
      <ul>
        <li>
          <span>Settings</span>
          <ul>
            <li>
              <span>Theme</span>
              <ul>
                <li><Link to="/admin/settings/theming">Theme Settings</Link></li>
                <li><Link to="/admin/settings/theme-designer">Theme Designer</Link></li>
              </ul>
            </li>
            <li><Link to="/admin/settings/branding">Branding</Link></li>
            <li><Link to="/admin/settings/core">Core Settings</Link></li>
          </ul>
        </li>
        <li><Link to="/admin/roles">Roles</Link></li>
        <li><Link to="/admin/users">Users</Link></li>
        <li><Link to="/admin/user-roles">User Roles</Link></li>
        <li><Link to="/admin/audit">Audit Logs</Link></li>
        <li><a href="/api/docs">API Documentation</a></li>
      </ul>
    </section>
  );
}
