import { lazy } from "react";
import { createBrowserRouter, createRoutesFromElements, Route, Navigate } from "react-router-dom";
import AppLayout from "./layouts/AppLayout";

// Dashboard
const Dashboard = lazy(() => import("./routes/dashboard"));

// Core modules
const RisksIndex = lazy(() => import("./routes/risks"));
const ComplianceIndex = lazy(() => import("./routes/compliance"));
const AuditsIndex = lazy(() => import("./routes/audits"));
const PoliciesIndex = lazy(() => import("./routes/policies"));

// Admin routes
const AdminIndex = lazy(() => import("./routes/admin/index"));
const CoreSettings = lazy(() => import("./routes/admin/Settings"));
const BrandingSettings = lazy(() => import("./routes/admin/BrandingSettings"));
const ThemingSettings = lazy(() => import("./routes/admin/ThemingSettings"));
const ThemeDesigner = lazy(() => import("./routes/admin/ThemeDesigner"));
const Roles = lazy(() => import("./routes/admin/Roles"));
const Audit = lazy(() => import("./routes/admin/Audit"));
const IdpProviders = lazy(() => import("./routes/admin/IdpProviders"));
const EvidenceList = lazy(() => import("./routes/evidence/List"));
const Users = lazy(() => import("./routes/admin/Users"));
const ExportsIndex = lazy(() => import("./routes/exports"));

// Profile routes
const Avatar = lazy(() => import("./routes/profile/Avatar"));
const ThemePreferences = lazy(() => import("./routes/profile/ThemePreferences"));

// Auth routes
const Login = lazy(() => import("./routes/auth/Login"));
const OidcCallback = lazy(() => import("./routes/auth/OidcCallback"));

const router = createBrowserRouter(
  createRoutesFromElements(
    <Route path="/" element={<AppLayout />}>
      <Route index element={<Navigate to="/dashboard" replace />} />

      <Route path="auth">
        <Route path="login" element={<Login />} />
        <Route path="callback" element={<OidcCallback />} />
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
        <Route path="audit" element={<Audit />} />
        <Route path="idp">
          <Route path="providers" element={<IdpProviders />} />
        </Route>
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
