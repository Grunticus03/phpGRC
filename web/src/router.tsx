import { createBrowserRouter, createRoutesFromElements, Route, Navigate } from "react-router-dom";
import AppLayout from "./layouts/AppLayout";

// Dashboard
import Dashboard from "./routes/dashboard";

// Core modules
import RisksIndex from "./routes/risks";
import ComplianceIndex from "./routes/compliance";
import AuditsIndex from "./routes/audits";
import PoliciesIndex from "./routes/policies";

// Admin routes
import AdminIndex from "./routes/admin/index";
import CoreSettings from "./routes/admin/Settings";
import BrandingSettings from "./routes/admin/BrandingSettings";
import ThemingSettings from "./routes/admin/ThemingSettings";
import ThemeDesigner from "./routes/admin/ThemeDesigner";
import Roles from "./routes/admin/Roles";
import UserRoles from "./routes/admin/UserRoles";
import Audit from "./routes/admin/Audit";
import EvidenceList from "./routes/evidence/List";
import Users from "./routes/admin/Users";
import ExportsIndex from "./routes/exports";

// Profile routes
import Avatar from "./routes/profile/Avatar";
import ThemePreferences from "./routes/profile/ThemePreferences";

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
      <Route path="risks" element={<RisksIndex />} />
      <Route path="compliance" element={<ComplianceIndex />} />
      <Route path="audits" element={<AuditsIndex />} />
      <Route path="policies" element={<PoliciesIndex />} />
      <Route path="evidence" element={<EvidenceList />} />
      <Route path="exports" element={<ExportsIndex />} />
      <Route path="admin">
        <Route index element={<AdminIndex />} />
        <Route path="settings">
          <Route index element={<Navigate to="core" replace />} />
          <Route path="core" element={<CoreSettings />} />
          <Route path="branding" element={<BrandingSettings />} />
          <Route path="theming" element={<ThemingSettings />} />
          <Route path="theme-designer" element={<ThemeDesigner />} />
        </Route>
        <Route path="roles" element={<Roles />} />
        <Route path="users" element={<Users />} />
        <Route path="user-roles" element={<UserRoles />} />
        <Route path="audit" element={<Audit />} />
        <Route path="evidence" element={<EvidenceList />} />
      </Route>

      <Route path="profile">
        <Route path="avatar" element={<Avatar />} />
        <Route path="theme" element={<ThemePreferences />} />
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Route>
  ),
  {
    future: {
      v7_relativeSplatPath: true,
    },
  }
);

export default router;
