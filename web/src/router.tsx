import { createBrowserRouter, createRoutesFromElements, Route, Navigate } from "react-router-dom";
import AppLayout from "./layouts/AppLayout";

// Auth
import Login from "./routes/auth/Login";

// Dashboard
import Dashboard from "./routes/dashboard";

// Admin routes
import AdminIndex from "./routes/admin/index";
import Settings from "./routes/admin/Settings";
import Roles from "./routes/admin/Roles";
import UserRoles from "./routes/admin/UserRoles";
import Users from "./routes/admin/Users";
import Audit from "./routes/admin/Audit";
import EvidenceList from "./routes/evidence/List";

// Simple guard: token presence. API will also 401 which we trap globally.
function ProtectedRoute({ element }: { element: JSX.Element }) {
  const token = localStorage.getItem("token");
  return token ? element : <Navigate to="/login" replace />;
}

const router = createBrowserRouter(
  createRoutesFromElements(
    <Route path="/" element={<AppLayout />}>
      <Route index element={<Navigate to="/dashboard" replace />} />
      <Route path="login" element={<Login />} />
      <Route path="dashboard" element={<ProtectedRoute element={<Dashboard />} />} />
      <Route path="admin">
        <Route index element={<ProtectedRoute element={<AdminIndex />} />} />
        <Route path="settings" element={<ProtectedRoute element={<Settings />} />} />
        <Route path="roles" element={<ProtectedRoute element={<Roles />} />} />
        <Route path="user-roles" element={<ProtectedRoute element={<UserRoles />} />} />
        <Route path="users" element={<ProtectedRoute element={<Users />} />} />
        <Route path="audit" element={<ProtectedRoute element={<Audit />} />} />
        <Route path="evidence" element={<ProtectedRoute element={<EvidenceList />} />} />
      </Route>
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Route>
  )
);

export default router;

