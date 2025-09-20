import { createHashRouter, createRoutesFromElements, Route, Navigate } from "react-router-dom";
import AppLayout from "./layouts/AppLayout";

// Dashboard
import Dashboard from "./routes/dashboard";

// Admin routes
import AdminIndex from "./routes/admin/index";
import Settings from "./routes/admin/Settings";
import Roles from "./routes/admin/Roles";
import UserRoles from "./routes/admin/UserRoles";
import Audit from "./routes/admin/Audit";

const router = createHashRouter(
  createRoutesFromElements(
    <Route path="/" element={<AppLayout />}>
      <Route index element={<Navigate to="/dashboard" replace />} />
      <Route path="dashboard" element={<Dashboard />} />
      <Route path="admin">
        <Route index element={<AdminIndex />} />
        <Route path="settings" element={<Settings />} />
        <Route path="roles" element={<Roles />} />
        <Route path="user-roles" element={<UserRoles />} />
        <Route path="audit" element={<Audit />} />
      </Route>
    </Route>
  )
);

export default router;