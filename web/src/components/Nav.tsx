import { NavLink } from "react-router-dom";

const linkCls = ({ isActive }: { isActive: boolean }) => "nav-link" + (isActive ? " active" : "");

export default function Nav(): JSX.Element {
  return (
    <header role="banner">
      <a href="#main" className="visually-hidden-focusable">
        Skip to content
      </a>
      <nav className="navbar" role="navigation" aria-label="Primary">
        <div className="container" style={{ display: "flex", alignItems: "center", gap: "1rem" }}>
          <NavLink to="/" className="navbar-brand" aria-label="phpGRC home">
            phpGRC
          </NavLink>
          <NavLink to="/dashboard" className={linkCls}>
            Dashboard
          </NavLink>
          <NavLink to="/admin" className={linkCls}>
            Admin
          </NavLink>
          <NavLink to="/admin/settings" className={linkCls}>
            Settings
          </NavLink>
          <NavLink to="/admin/roles" className={linkCls}>
            Roles
          </NavLink>
          <NavLink to="/admin/user-roles" className={linkCls}>
            User Roles
          </NavLink>
          <NavLink to="/admin/audit" className={linkCls}>
            Audit
          </NavLink>
          <NavLink to="/admin/evidence" className={linkCls}>
            Evidence
          </NavLink>
          <a href="/api/docs" className="nav-link">
            API Docs
          </a>
        </div>
      </nav>
    </header>
  );
}
