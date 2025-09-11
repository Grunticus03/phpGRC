import { createHashRouter, createRoutesFromElements, Route, Navigate } from "react-router-dom";
import AppLayout from "./layouts/AppLayout";

// Admin routes
import AdminIndex from "./routes/admin/index";
import Settings from "./routes/admin/Settings";
import Roles from "./routes/admin/Roles";

// Other feature stubs already present (optional wiring later)

const router = createHashRouter(
  createRoutesFromElements(
    <Route path="/" element={<AppLayout />}>
      <Route index element={<Navigate to="/admin" replace />} />
      <Route path="admin">
        <Route index element={<AdminIndex />} />
        <Route path="settings" element={<Settings />} />
        <Route path="roles" element={<Roles />} />
      </Route>
    </Route>
  )
);

export default router;
