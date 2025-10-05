import { createBrowserRouter, createRoutesFromElements, Route, Navigate } from "react-router-dom";
import AppLayout from "./layouts/AppLayout";

// Dashboard
import Dashboard from "./routes/dashboard";

// Admin routes
import AdminIndex from "./routes/admin/index";
import Settings from "./routes/admin/Settings";
import Roles from "./routes/admin/Roles";
import UserRoles from "./routes/admin/UserRoles";
import Audit from "./routes/admin/Audit";
import EvidenceList from "./routes/evidence/List";
import Users from "./routes/admin/Users";

// Auth routes
import Login from "./routes/auth/Login";

const router = createBrowserRouter(
  createRoutesFromElements(
    <Route path="/" element={<AppLayout />}>
      <Route index element={<Navigate to="/dashboard" replace />} />

      <Route path="auth">
        <Route path="login" element={<Login />} />
      </Route>

      <Route path="dashboard" element={<Dashboard />} />
      <Route path="admin">
        <Route index element={<AdminIndex />} />
        <Route path="settings" element={<Settings />} />
        <Route path="roles" element={<Roles />} />
        <Route path="users" element={<Users />} />
        <Route path="user-roles" element={<UserRoles />} />
        <Route path="audit" element={<Audit />} />
        <Route path="evidence" element={<EvidenceList />} />
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Route>
  )
);

export default router;
