import { NavLink } from "react-router-dom";
import { authLogout } from "../lib/api";

type NavProps = {
  requireAuth: boolean;
  authed: boolean;
};

const linkCls = ({ isActive }: { isActive: boolean }) => "nav-link" + (isActive ? " active" : "");

export default function Nav({ requireAuth, authed }: NavProps): JSX.Element {
  const showUsers = !requireAuth || authed;
  const showLogout = requireAuth && authed;
  const showLogin = requireAuth && !authed;

  async function onLogout(): Promise<void> {
    await authLogout();
    window.location.assign("/auth/login");
  }

  return (
    <header role="banner">
      <nav className="navbar" role="navigation" aria-label="Primary">
        <div className="container" style={{ display: "flex", alignItems: "center", gap: "1rem" }}>
          <NavLink to="/" className="navbar-brand d-flex align-items-center" aria-label="phpGRC home">
            <img
              src="/api/images/phpGRC-light-horizontal-trans.png"
              alt="phpGRC"
              style={{ height: "36px", width: "auto" }}
              loading="lazy"
            />
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
            {showLogout ? (
              <button className="btn btn-outline-secondary btn-sm" type="button" onClick={onLogout}>
                Logout
              </button>
            ) : showLogin ? (
              <NavLink to="/auth/login" className={linkCls}>
                Login
              </NavLink>
            ) : null}
          </div>
        </div>
      </nav>
    </header>
  );
}
