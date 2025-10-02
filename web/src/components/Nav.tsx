import { NavLink } from "react-router-dom";
import { authLogout } from "../lib/api";

type NavProps = {
  requireAuth: boolean;
  authed: boolean;
};

const linkCls = ({ isActive }: { isActive: boolean }) => "nav-link" + (isActive ? " active" : "");

export default function Nav({ requireAuth, authed }: NavProps): JSX.Element {
  const showUsers = !requireAuth || authed;

  async function onLogout(): Promise<void> {
    await authLogout();
    window.location.assign("/auth/login");
  }

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
          {showUsers && (
            <NavLink to="/admin/users" className={linkCls}>
              Users
            </NavLink>
          )}
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

          {/* right side */}
          <div style={{ marginLeft: "auto" }}>
            {authed ? (
              <button className="btn btn-outline-secondary btn-sm" type="button" onClick={onLogout}>
                Logout
              </button>
            ) : (
              <NavLink to="/auth/login" className={linkCls}>
                Login
              </NavLink>
            )}
          </div>
        </div>
      </nav>
    </header>
  );
}
