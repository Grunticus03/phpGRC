import { NavLink } from "react-router-dom";

const linkCls = ({ isActive }: { isActive: boolean }) =>
  "nav-link" + (isActive ? " active" : "");

export default function Nav(): JSX.Element {
  return (
    <nav className="navbar">
      <div className="container" style={{ display: "flex", alignItems: "center", gap: "1rem" }}>
        <NavLink to="/" className={({ isActive }) => (isActive ? "active" : "")}>phpGRC</NavLink>
        <NavLink to="/admin" className={linkCls}>Admin</NavLink>
        <NavLink to="/admin/settings" className={linkCls}>Settings</NavLink>
        <NavLink to="/admin/roles" className={linkCls}>Roles</NavLink>
      </div>
    </nav>
  );
}
