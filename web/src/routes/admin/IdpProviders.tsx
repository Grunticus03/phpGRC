import { ChangeEvent, useCallback, useEffect, useMemo, useState } from "react";
import ConfirmModal from "../../components/modal/ConfirmModal";
import { useToast } from "../../components/toast/ToastProvider";
import {
  createIdpProvider,
  deleteIdpProvider,
  isStubResponse,
  listIdpProviders,
  updateIdpProvider,
  previewSamlMetadata,
  previewIdpHealth,
  browseLdapDirectory,
  type IdpProvider,
  type IdpProviderDriver,
  type IdpProviderListMeta,
  type IdpProviderRequestPayload,
  type IdpProviderUpdatePayload,
  type SamlMetadataConfig,
  type IdpProviderPreviewHealthResult,
  type LdapBrowseEntry,
} from "../../lib/api/idpProviders";
import { HttpError } from "../../lib/api";

type OidcFormState = {
  issuer: string;
  clientId: string;
  clientSecret: string;
  scopes: string;
  redirectUris: string;
  metadataUrl: string;
};

type EntraFormState = OidcFormState & {
  tenantId: string;
};

type SamlFormState = {
  entityId: string;
  ssoUrl: string;
  certificate: string;
  metadataUrl: string;
  metadataFileName: string | null;
};

type LdapFormState = {
  host: string;
  port: string;
  baseDn: string;
  bindStrategy: "service" | "direct";
  bindDn: string;
  bindPassword: string;
  userDnTemplate: string;
  userFilter: string;
  timeout: string;
  emailAttribute: string;
  nameAttribute: string;
  usernameAttribute: string;
  useSsl: boolean;
  startTls: boolean;
  requireTls: boolean;
};

type FormState = {
  key: string;
  name: string;
  driver: IdpProviderDriver;
  enabled: boolean;
  meta: string;
  oidc: OidcFormState;
  entra: EntraFormState;
  saml: SamlFormState;
  ldap: LdapFormState;
};

type FormErrors = {
  general?: string;
  fields: Record<string, string>;
};

type PendingAction = { type: "toggle" | "reorder" | "delete" | "create"; id: string | null };

const DRIVER_OPTIONS: Array<{ value: IdpProviderDriver; label: string }> = [
  { value: "entra", label: "Entra ID" },
  { value: "ldap", label: "LDAP" },
  { value: "oidc", label: "OIDC" },
  { value: "saml", label: "SAML" },
];

function createDefaultFormState(): FormState {
  return {
    key: "",
    name: "",
    driver: "oidc",
    enabled: true,
    meta: "",
    oidc: {
      issuer: "",
      clientId: "",
      clientSecret: "",
      scopes: "openid profile email",
      redirectUris: "",
      metadataUrl: "",
    },
    entra: {
      issuer: "",
      clientId: "",
      clientSecret: "",
      scopes: "openid profile email",
      redirectUris: "",
      metadataUrl: "",
      tenantId: "",
    },
    saml: {
      entityId: "",
      ssoUrl: "",
      certificate: "",
      metadataUrl: "",
      metadataFileName: null,
    },
    ldap: {
      host: "",
      port: "",
      baseDn: "",
      bindStrategy: "service",
      bindDn: "",
      bindPassword: "",
      userDnTemplate: "uid={{username}},ou=people,dc=example,dc=com",
      userFilter: "(&(objectClass=person)(uid={{username}}))",
      timeout: "",
      emailAttribute: "mail",
      nameAttribute: "cn",
      usernameAttribute: "uid",
      useSsl: false,
      startTls: false,
      requireTls: false,
    },
  };
}

function parseUrlValue(candidate: string, secureOnly = false): string | null {
  if (candidate === "") {
    return null;
  }

  try {
    const url = new URL(candidate);
    if (secureOnly && url.protocol !== "https:") {
      return null;
    }

    return url.toString();
  } catch {
    return null;
  }
}

function providerIdentifier(provider: IdpProvider): string {
  return provider.id || provider.key;
}

function sortByEvaluationOrder(items: IdpProvider[]): IdpProvider[] {
  return [...items].sort((a, b) => {
    if (a.evaluation_order === b.evaluation_order) return a.name.localeCompare(b.name);
    return a.evaluation_order - b.evaluation_order;
  });
}

export default function IdpProviders(): JSX.Element {
  const toast = useToast();
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [providers, setProviders] = useState<IdpProvider[]>([]);
  const [meta, setMeta] = useState<IdpProviderListMeta>({ total: 0, enabled: 0 });
  const [stubMode, setStubMode] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState<PendingAction | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<IdpProvider | null>(null);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [createBusy, setCreateBusy] = useState(false);
  const [form, setForm] = useState<FormState>(() => createDefaultFormState());
  const [formErrors, setFormErrors] = useState<FormErrors>({ fields: {} });
  const [metadataLoading, setMetadataLoading] = useState<Record<IdpProviderDriver, boolean>>({
    entra: false,
    ldap: false,
    oidc: false,
    saml: false,
  });
  const [testBusy, setTestBusy] = useState(false);
  const [testResult, setTestResult] = useState<IdpProviderPreviewHealthResult | null>(null);
  const [testError, setTestError] = useState<string | null>(null);
  const [ldapBrowserOpen, setLdapBrowserOpen] = useState(false);
  const [ldapBrowserBusy, setLdapBrowserBusy] = useState(false);
  const [ldapBrowserError, setLdapBrowserError] = useState<string | null>(null);
  const [ldapBrowserEntries, setLdapBrowserEntries] = useState<LdapBrowseEntry[]>([]);
  const [ldapBrowserPath, setLdapBrowserPath] = useState<string[]>([]);
  const [ldapBrowserBaseDn, setLdapBrowserBaseDn] = useState<string | null>(null);

  const clearTestFeedback = useCallback(() => {
    setTestResult(null);
    setTestError(null);
  }, []);

  const applyFormUpdate = useCallback(
    (updater: FormState | ((prev: FormState) => FormState)) => {
      setForm((prev) => (typeof updater === "function" ? (updater as (current: FormState) => FormState)(prev) : updater));
      clearTestFeedback();
    },
    [clearTestFeedback]
  );

  const orderedProviders = useMemo(() => sortByEvaluationOrder(providers), [providers]);

  const loadProviders = useCallback(
    async (options?: { silent?: boolean }) => {
      const silent = options?.silent ?? false;
      if (silent) {
        setRefreshing(true);
      } else {
        setLoading(true);
      }
      setError(null);
      try {
        const res = await listIdpProviders();
        setProviders(sortByEvaluationOrder(res.items ?? []));
        setMeta(res.meta ?? { total: res.items?.length ?? 0, enabled: res.items?.filter((item) => item.enabled).length ?? 0 });
        setStubMode(res.note === "stub-only");
      } catch {
        setError("Failed to load Identity Providers. Please try again.");
      } finally {
        if (silent) {
          setRefreshing(false);
        } else {
          setLoading(false);
        }
      }
    },
    []
  );

  useEffect(() => {
    void loadProviders();
  }, [loadProviders]);

  const handleToggle = useCallback(
    async (provider: IdpProvider) => {
      const identifier = providerIdentifier(provider);
      setPending({ type: "toggle", id: identifier });
      try {
        const payload: IdpProviderUpdatePayload = { enabled: !provider.enabled };
        const res = await updateIdpProvider(identifier, payload);
        if (isStubResponse(res)) {
          toast.info("Changes accepted in stub-only mode; persistence disabled.");
        } else {
          setProviders((prev) =>
            prev.map((item) => (providerIdentifier(item) === providerIdentifier(provider) ? res.provider : item))
          );
          toast.success(res.provider.enabled ? "Provider enabled." : "Provider disabled.");
        }
      } catch {
        toast.danger("Failed to update provider status. Please retry.");
      } finally {
        setPending(null);
        void loadProviders({ silent: true });
      }
    },
    [loadProviders, toast]
  );

  const handleReorder = useCallback(
    async (provider: IdpProvider, direction: "up" | "down") => {
      const sorted = orderedProviders;
      const index = sorted.findIndex((item) => providerIdentifier(item) === providerIdentifier(provider));
      if (index < 0) return;
      const targetIndex = direction === "up" ? index - 1 : index + 1;
      if (targetIndex < 0 || targetIndex >= sorted.length) return;
      const targetProvider = sorted[targetIndex];
      const newOrder = targetProvider.evaluation_order;
      const identifier = providerIdentifier(provider);
      setPending({ type: "reorder", id: identifier });
      try {
        const res = await updateIdpProvider(identifier, { evaluation_order: newOrder });
        if (isStubResponse(res)) {
          toast.info("Reorder accepted in stub-only mode; persistence disabled.");
        } else {
          setProviders((prev) => {
            const updated = prev.map((item) =>
              providerIdentifier(item) === identifier ? res.provider : item
            );
            return sortByEvaluationOrder(updated);
          });
          toast.success("Evaluation order updated.");
        }
      } catch {
        toast.danger("Failed to update evaluation order. Please retry.");
      } finally {
        setPending(null);
        void loadProviders({ silent: true });
      }
    },
    [loadProviders, orderedProviders, toast]
  );

  const openDeleteModal = useCallback((provider: IdpProvider) => {
    setDeleteTarget(provider);
    setDeleteError(null);
  }, []);

  const closeDeleteModal = useCallback(() => {
    if (deleteBusy) return;
    setDeleteTarget(null);
    setDeleteError(null);
  }, [deleteBusy]);

  const confirmDelete = useCallback(async () => {
    const provider = deleteTarget;
    if (!provider) return;
    const identifier = providerIdentifier(provider);
    setDeleteBusy(true);
    setPending({ type: "delete", id: identifier });
    try {
      const res = await deleteIdpProvider(identifier);
      if (isStubResponse(res)) {
        toast.info("Deletion accepted in stub-only mode; persistence disabled.");
      } else {
        toast.success(`Deleted provider ${res.deleted}.`);
      }
      setProviders((prev) => prev.filter((item) => providerIdentifier(item) !== identifier));
      void loadProviders({ silent: true });
      setDeleteTarget(null);
    } catch {
      setDeleteError("Failed to delete provider. Please try again.");
    } finally {
      setPending(null);
      setDeleteBusy(false);
    }
  }, [deleteTarget, loadProviders, toast]);

  const resetForm = useCallback(() => {
    setForm(createDefaultFormState());
    clearTestFeedback();
    setFormErrors({ fields: {} });
    setMetadataLoading({ entra: false, ldap: false, oidc: false, saml: false });
    setLdapBrowserEntries([]);
    setLdapBrowserPath([]);
    setLdapBrowserBaseDn(null);
    setLdapBrowserError(null);
  }, [clearTestFeedback]);

  const openCreateModal = useCallback(() => {
    resetForm();
    setCreateOpen(true);
  }, [resetForm]);

  const closeCreateModal = useCallback(() => {
    if (createBusy) return;
    setCreateOpen(false);
  }, [createBusy]);

  const validateForm = useCallback(
    (
      state: FormState,
      options?: { skipIdentityFields?: boolean }
    ): { valid: boolean; payload?: IdpProviderRequestPayload } => {
      const fieldErrors: Record<string, string> = {};
      const skipIdentity = options?.skipIdentityFields ?? false;

      const trimmedKey = state.key.trim();
      if (!skipIdentity) {
        if (trimmedKey.length < 3) {
          fieldErrors.key = "Key must be at least 3 characters.";
        }
        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(trimmedKey)) {
          fieldErrors.key = "Key may only include lowercase letters, numbers, and hyphens.";
        }
      }

      const trimmedName = state.name.trim();
      if (!skipIdentity && trimmedName.length < 3) {
        fieldErrors.name = "Name must be at least 3 characters.";
      }

      const driver = state.driver;
      if (!driver) {
        fieldErrors.driver = "Idp Type is required.";
      }

      let config: Record<string, unknown> | undefined;

      if (driver === "oidc") {
        const issuer = state.oidc.issuer.trim();
        const normalizedIssuer = parseUrlValue(issuer, true);
        if (!normalizedIssuer) {
          fieldErrors["oidc.issuer"] = "Issuer must be a valid https:// URL.";
        }

        const clientId = state.oidc.clientId.trim();
        if (clientId === "") {
          fieldErrors["oidc.clientId"] = "Client ID is required.";
        }

        const clientSecret = state.oidc.clientSecret.trim();
        if (clientSecret === "") {
          fieldErrors["oidc.clientSecret"] = "Client secret is required.";
        }

        const scopesRaw = state.oidc.scopes.trim();
        const scopes = scopesRaw === "" ? [] : scopesRaw.split(/\s+/).map((scope) => scope.trim()).filter(Boolean);

        const redirectUrisRaw = state.oidc.redirectUris;
        const redirectUris = redirectUrisRaw
          .split(/[\n,]/)
          .map((entry) => entry.trim())
          .filter((entry) => entry !== "");

        const invalidRedirect = redirectUris.find((uri) => parseUrlValue(uri) === null);
        if (invalidRedirect) {
          fieldErrors["oidc.redirectUris"] = "Each redirect URI must be a valid URL.";
        }

        if (Object.keys(fieldErrors).length === 0) {
          config = {
            issuer: normalizedIssuer ?? issuer,
            client_id: clientId,
            client_secret: clientSecret,
          };
          if (scopes.length > 0) {
            config.scopes = scopes;
          }
          if (!invalidRedirect && redirectUris.length > 0) {
            config.redirect_uris = redirectUris;
          }
        }
      } else if (driver === "entra") {
        const issuer = state.entra.issuer.trim();
        const normalizedIssuer = issuer === "" ? null : parseUrlValue(issuer, true);

        const tenantId = state.entra.tenantId.trim();
        if (tenantId === "") {
          fieldErrors["entra.tenantId"] = "Tenant ID is required.";
        } else if (!/^[0-9a-f-]{8,}$/i.test(tenantId)) {
          fieldErrors["entra.tenantId"] = "Tenant ID must look like a GUID.";
        }

        if (issuer !== "" && !normalizedIssuer) {
          fieldErrors["entra.issuer"] = "Issuer must be a valid https:// URL.";
        }

        const clientId = state.entra.clientId.trim();
        if (clientId === "") {
          fieldErrors["entra.clientId"] = "Client ID is required.";
        }

        const clientSecret = state.entra.clientSecret.trim();
        if (clientSecret === "") {
          fieldErrors["entra.clientSecret"] = "Client secret is required.";
        }

        const scopesRaw = state.entra.scopes.trim();
        const scopes = scopesRaw === "" ? [] : scopesRaw.split(/\s+/).map((scope) => scope.trim()).filter(Boolean);

        const redirectUrisRaw = state.entra.redirectUris;
        const redirectUris = redirectUrisRaw
          .split(/[\n,]/)
          .map((entry) => entry.trim())
          .filter((entry) => entry !== "");

        const invalidRedirect = redirectUris.find((uri) => parseUrlValue(uri) === null);
        if (invalidRedirect) {
          fieldErrors["entra.redirectUris"] = "Each redirect URI must be a valid URL.";
        }

        if (Object.keys(fieldErrors).length === 0) {
          config = {
            tenant_id: tenantId,
            client_id: clientId,
            client_secret: clientSecret,
          };

          if (normalizedIssuer) {
            config.issuer = normalizedIssuer;
          }

          if (scopes.length > 0) {
            config.scopes = scopes;
          }

          if (!invalidRedirect && redirectUris.length > 0) {
            config.redirect_uris = redirectUris;
          }
        }
      } else if (driver === "saml") {
        const entityId = state.saml.entityId.trim();
        if (entityId === "") {
          fieldErrors["saml.entityId"] = "Entity ID is required.";
        }

        const ssoUrlRaw = state.saml.ssoUrl.trim();
        const normalizedSsoUrl = parseUrlValue(ssoUrlRaw);
        if (!normalizedSsoUrl) {
          fieldErrors["saml.ssoUrl"] = "SSO URL must be a valid URL.";
        }

        const certificate = state.saml.certificate.trim();
        if (certificate === "") {
          fieldErrors["saml.certificate"] = "Signing certificate is required.";
        }

        if (Object.keys(fieldErrors).length === 0) {
          config = {
            entity_id: entityId,
            sso_url: normalizedSsoUrl ?? ssoUrlRaw,
            certificate,
          };
        }
      } else if (driver === "ldap") {
        const host = state.ldap.host.trim();
        if (host === "") {
          fieldErrors["ldap.host"] = "Server host is required.";
        }

        const portRaw = state.ldap.port.trim();
        let port: number | undefined;
        if (portRaw !== "") {
          const portCandidate = Number(portRaw);
          if (!Number.isInteger(portCandidate) || portCandidate < 1 || portCandidate > 65535) {
            fieldErrors["ldap.port"] = "Port must be an integer between 1 and 65535.";
          } else {
            port = portCandidate;
          }
        }

        const baseDn = state.ldap.baseDn.trim();
        if (baseDn === "") {
          fieldErrors["ldap.baseDn"] = "Base DN is required.";
        }

        const bindStrategy = state.ldap.bindStrategy;
        if (!["service", "direct"].includes(bindStrategy)) {
          fieldErrors["ldap.bindStrategy"] = "Select a bind strategy.";
        }

        const bindDn = state.ldap.bindDn.trim();
        const bindPassword = state.ldap.bindPassword.trim();
        const userDnTemplate = state.ldap.userDnTemplate.trim();

        if (bindStrategy === "service") {
          if (bindDn === "") {
            fieldErrors["ldap.bindDn"] = "Bind DN is required when using a service account.";
          }
          if (bindPassword === "") {
            fieldErrors["ldap.bindPassword"] = "Bind password is required when using a service account.";
          }
        } else if (bindStrategy === "direct") {
          if (userDnTemplate === "") {
            fieldErrors["ldap.userDnTemplate"] = "User DN template is required.";
          } else if (!userDnTemplate.includes("{{username}}")) {
            fieldErrors["ldap.userDnTemplate"] = 'Template must include the placeholder "{{username}}".';
          }
        }

        const userFilter = state.ldap.userFilter.trim();
        if (userFilter === "") {
          fieldErrors["ldap.userFilter"] = "User search filter is required.";
        } else if (!userFilter.includes("{{username}}")) {
          fieldErrors["ldap.userFilter"] = 'Filter must include the placeholder "{{username}}".';
        }

        const timeoutRaw = state.ldap.timeout.trim();
        let timeout: number | undefined;
        if (timeoutRaw !== "") {
          const timeoutCandidate = Number(timeoutRaw);
          if (!Number.isInteger(timeoutCandidate) || timeoutCandidate < 1 || timeoutCandidate > 120) {
            fieldErrors["ldap.timeout"] = "Timeout must be between 1 and 120 seconds.";
          } else {
            timeout = timeoutCandidate;
          }
        }

        const emailAttribute = state.ldap.emailAttribute.trim();
        if (emailAttribute === "") {
          fieldErrors["ldap.emailAttribute"] = "Email attribute is required.";
        }

        const nameAttribute = state.ldap.nameAttribute.trim();
        if (nameAttribute === "") {
          fieldErrors["ldap.nameAttribute"] = "Display name attribute is required.";
        }

        const usernameAttribute = state.ldap.usernameAttribute.trim();
        if (usernameAttribute === "") {
          fieldErrors["ldap.usernameAttribute"] = "Username attribute is required.";
        }

        if (Object.keys(fieldErrors).length === 0) {
          config = {
            host,
            base_dn: baseDn,
            bind_strategy: bindStrategy,
            use_ssl: state.ldap.useSsl,
            start_tls: state.ldap.startTls,
            require_tls: state.ldap.requireTls,
            user_filter: userFilter,
            email_attribute: emailAttribute,
            name_attribute: nameAttribute,
            username_attribute: usernameAttribute,
          };

          if (port !== undefined) {
            config.port = port;
          }
          if (timeout !== undefined) {
            config.timeout = timeout;
          }

          if (bindStrategy === "service") {
            config.bind_dn = bindDn;
            config.bind_password = bindPassword;
          } else {
            config.user_dn_template = userDnTemplate;
          }
        }
      }

      let meta: Record<string, unknown> | null = null;
      const metaTrimmed = state.meta.trim();
      if (metaTrimmed !== "") {
        try {
          const parsed = JSON.parse(metaTrimmed) as unknown;
          if (parsed !== null && typeof parsed === "object" && !Array.isArray(parsed)) {
            meta = parsed as Record<string, unknown>;
          } else {
            fieldErrors.meta = "Meta must be a JSON object.";
          }
        } catch {
          fieldErrors.meta = "Meta must be valid JSON.";
        }
      }

      if (Object.keys(fieldErrors).length > 0) {
        setFormErrors({ fields: fieldErrors });
        return { valid: false };
      }

      if (!skipIdentity) {
        setFormErrors({ fields: {} });
      }

      const payload: IdpProviderRequestPayload = {
        key: trimmedKey,
        name: trimmedName,
        driver,
        enabled: state.enabled,
        config: config ?? {},
      };

      if (meta) {
        payload.meta = meta;
      }

      return { valid: true, payload };
    },
    []
  );

  const submitCreate = useCallback(async () => {
    const { valid, payload } = validateForm(form);
    if (!valid || !payload) return;
    setCreateBusy(true);
    setPending({ type: "create", id: null });
    try {
      const res = await createIdpProvider(payload);
      if (isStubResponse(res)) {
        toast.info("Creation accepted in stub-only mode; persistence disabled.");
      } else {
        toast.success(`Created provider ${res.provider.name}.`);
        setProviders((prev) => sortByEvaluationOrder([...prev, res.provider]));
      }
      setCreateOpen(false);
      resetForm();
      void loadProviders({ silent: true });
    } catch {
      setFormErrors({ general: "Failed to create provider. Check inputs and try again.", fields: {} });
    } finally {
      setPending(null);
      setCreateBusy(false);
    }
  }, [form, loadProviders, resetForm, toast, validateForm]);

  const disabledIds = useMemo(() => {
    if (!pending) return new Set<string>();
    const set = new Set<string>();
    if (pending.id) {
      set.add(pending.id);
    }
    return set;
  }, [pending]);

  const busy = pending !== null;
  const fieldError = (path: string): string | undefined => formErrors.fields[path];
  const updateFieldError = useCallback((path: string, message?: string) => {
    setFormErrors((prev) => {
      const nextFields = { ...prev.fields };
      if (message) {
        nextFields[path] = message;
      } else {
        delete nextFields[path];
      }

      return {
        ...prev,
        fields: nextFields,
      };
    });
  }, []);

  const applyOidcMetadata = useCallback(
    (driver: "oidc" | "entra", metadata: Record<string, unknown>, source?: { url?: string }) => {
      const issuer = typeof metadata.issuer === "string" ? metadata.issuer : "";
      const scopesSupported = Array.isArray(metadata.scopes_supported)
        ? (metadata.scopes_supported as unknown[]).filter((entry): entry is string => typeof entry === "string")
        : [];

      applyFormUpdate((prev) => {
        if (driver === "oidc") {
          return {
            ...prev,
            oidc: {
              ...prev.oidc,
              metadataUrl: source?.url ?? prev.oidc.metadataUrl,
              issuer: issuer !== "" ? issuer : prev.oidc.issuer,
              scopes:
                scopesSupported.length > 0
                  ? scopesSupported.join(" ")
                  : prev.oidc.scopes,
            },
          };
        }

        let tenantId = prev.entra.tenantId;
        if (tenantId.trim() === "" && issuer.includes("login.microsoftonline.com/")) {
          const match = issuer.match(/login\.microsoftonline\.com\/([^/]+)\//i);
          if (match?.[1]) {
            tenantId = match[1];
          }
        }

        return {
          ...prev,
          entra: {
            ...prev.entra,
            metadataUrl: source?.url ?? prev.entra.metadataUrl,
            issuer: issuer !== "" ? issuer : prev.entra.issuer,
            scopes:
              scopesSupported.length > 0
                ? scopesSupported.join(" ")
                : prev.entra.scopes,
            tenantId,
          },
        };
      });
    },
    [applyFormUpdate]
  );

  const handleOidcMetadataFetch = useCallback(
    async (driver: "oidc" | "entra") => {
      const currentUrl = driver === "oidc" ? form.oidc.metadataUrl : form.entra.metadataUrl;
      const trimmedUrl = currentUrl.trim();
      if (trimmedUrl === "") {
        updateFieldError(`${driver}.metadataUrl`, "Enter a metadata URL first.");
        return;
      }

      updateFieldError(`${driver}.metadataUrl`);
      setMetadataLoading((prev) => ({ ...prev, [driver]: true }));

      try {
        const response = await fetch(trimmedUrl);
        if (!response.ok) {
          throw new Error(`Metadata request failed (${response.status})`);
        }

        const raw = await response.text();
        const parsed = JSON.parse(raw) as unknown;
        if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
          throw new Error("Metadata response was not a JSON object.");
        }

        applyOidcMetadata(driver, parsed as Record<string, unknown>, { url: trimmedUrl });
        toast.success("Metadata loaded. Review and complete any required secrets.");
      } catch {
        toast.danger("Failed to fetch metadata. Enter the details manually.");
      } finally {
        setMetadataLoading((prev) => ({ ...prev, [driver]: false }));
      }
    },
    [applyOidcMetadata, form.entra.metadataUrl, form.oidc.metadataUrl, toast, updateFieldError]
  );

  const handleOidcMetadataFileChange = useCallback(
    (driver: "oidc" | "entra", event: ChangeEvent<HTMLInputElement>) => {
      const file = event.target.files?.[0];
      if (!file) return;

      const reader = new FileReader();
      setMetadataLoading((prev) => ({ ...prev, [driver]: true }));

      reader.onload = () => {
        try {
          const text = typeof reader.result === "string" ? reader.result : "";
          if (text.trim() === "") {
            throw new Error("Metadata file was empty.");
          }

          const parsed = JSON.parse(text) as unknown;
          if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
            throw new Error("Metadata file must contain a JSON object.");
          }

          applyOidcMetadata(driver, parsed as Record<string, unknown>);
          updateFieldError(`${driver}.metadataUrl`);
          toast.success("Metadata file loaded. Review the populated fields.");
        } catch {
          toast.danger("Could not read that metadata file. Ensure it is JSON.");
        } finally {
          setMetadataLoading((prev) => ({ ...prev, [driver]: false }));
        }
      };

      reader.onerror = () => {
        toast.danger("Could not read that metadata file. Please try again.");
        setMetadataLoading((prev) => ({ ...prev, [driver]: false }));
      };

      reader.readAsText(file);
      event.target.value = "";
    },
    [applyOidcMetadata, toast, updateFieldError]
  );

  const applySamlMetadata = useCallback((config: SamlMetadataConfig, source?: { url?: string; fileName?: string }) => {
    applyFormUpdate((prev) => ({
      ...prev,
      saml: {
        ...prev.saml,
        entityId: config.entity_id ?? prev.saml.entityId,
        ssoUrl: config.sso_url ?? prev.saml.ssoUrl,
        certificate: config.certificate ?? prev.saml.certificate,
        metadataUrl: source?.url ?? prev.saml.metadataUrl,
        metadataFileName: source?.fileName ?? prev.saml.metadataFileName,
      },
    }));
  }, [applyFormUpdate]);

  const previewSamlMetadataXml = useCallback(
    async (metadataXml: string, source?: { url?: string; fileName?: string }) => {
      if (metadataXml.trim().length < 20) {
        toast.danger("Metadata file looks too small. Check the content and try again.");
        return false;
      }

      try {
        const response = await previewSamlMetadata(metadataXml);
        applySamlMetadata(response.config, source);
        toast.success("Metadata parsed. Review the pre-filled fields.");
        return true;
      } catch {
        toast.danger("Failed to parse metadata. Ensure the document is valid XML.");
        return false;
      }
    },
    [applySamlMetadata, toast]
  );

  const handleSamlMetadataFetch = useCallback(async () => {
    const trimmedUrl = form.saml.metadataUrl.trim();
    if (trimmedUrl === "") {
      updateFieldError("saml.metadataUrl", "Enter a metadata URL first.");
      return;
    }

    updateFieldError("saml.metadataUrl");
    setMetadataLoading((prev) => ({ ...prev, saml: true }));

    try {
      const response = await fetch(trimmedUrl);
      if (!response.ok) {
        throw new Error(`Metadata request failed (${response.status})`);
      }

      const xml = await response.text();
      await previewSamlMetadataXml(xml, { url: trimmedUrl });
    } catch {
      toast.danger("Failed to download metadata. Verify the URL and CORS settings.");
    } finally {
      setMetadataLoading((prev) => ({ ...prev, saml: false }));
    }
  }, [form.saml.metadataUrl, previewSamlMetadataXml, toast, updateFieldError]);

  const handleSamlMetadataFileChange = useCallback(
    (event: ChangeEvent<HTMLInputElement>) => {
      const file = event.target.files?.[0];
      if (!file) return;

      const reader = new FileReader();
      setMetadataLoading((prev) => ({ ...prev, saml: true }));

      reader.onload = async () => {
        const text = typeof reader.result === "string" ? reader.result : "";
        if (text.trim() === "") {
          toast.danger("Metadata file was empty.");
          setMetadataLoading((prev) => ({ ...prev, saml: false }));
          return;
        }

        try {
          const success = await previewSamlMetadataXml(text, { fileName: file.name });
          if (success) {
            updateFieldError("saml.metadataUrl");
          }
        } finally {
          setMetadataLoading((prev) => ({ ...prev, saml: false }));
        }
      };

      reader.onerror = () => {
        toast.danger("Could not read that metadata file. Please try again.");
        setMetadataLoading((prev) => ({ ...prev, saml: false }));
      };

      reader.readAsText(file);
      event.target.value = "";
    },
    [previewSamlMetadataXml, toast, updateFieldError]
  );

  const handleTestConfiguration = useCallback(async () => {
    setTestResult(null);
    setTestError(null);

    const { valid, payload } = validateForm(form, { skipIdentityFields: true });
    if (!valid || !payload) {
      setTestError("Please resolve the highlighted configuration issues before testing.");
      return;
    }

    const requestBody = {
      driver: payload.driver,
      config: payload.config,
      ...(payload.meta ? { meta: payload.meta } : {}),
    };

    setTestBusy(true);
    try {
      const result = await previewIdpHealth(requestBody);
      setTestResult(result);
      setTestError(null);
    } catch (error) {
      if (error instanceof HttpError) {
        const body = error.body;
        let message = error.message;
        if (body && typeof body === "object" && !Array.isArray(body) && "message" in body && body.message) {
          message = String((body as { message?: unknown }).message);
        }

        const details: Record<string, unknown> =
          body && typeof body === "object" && !Array.isArray(body)
            ? (body as Record<string, unknown>)
            : { raw: body };

        setTestResult({
          ok: false,
          status: "error",
          message,
          checked_at: new Date().toISOString(),
          details,
        });
        setTestError(message);
      } else {
        setTestResult(null);
        setTestError("Failed to run health check. Please try again.");
      }
    } finally {
      setTestBusy(false);
    }
  }, [form, validateForm]);

  const applyLdapPreset = useCallback(
    (preset: "ad" | "generic") => {
      applyFormUpdate((prev) => ({
        ...prev,
        ldap: {
          ...prev.ldap,
          host: prev.ldap.host || (preset === "ad" ? "ad.example.com" : "ldap.example.com"),
          port: prev.ldap.port || (preset === "ad" ? "389" : prev.ldap.port),
          baseDn: prev.ldap.baseDn || "dc=example,dc=com",
          bindStrategy: "service",
          bindDn:
            preset === "ad"
              ? "CN=Service Account,OU=Users,DC=example,DC=com"
              : prev.ldap.bindDn || "cn=service,dc=example,dc=com",
          userFilter:
            preset === "ad"
              ? "(&(objectClass=user)(sAMAccountName={{username}}))"
              : "(&(objectClass=person)(uid={{username}}))",
          emailAttribute: "mail",
          nameAttribute: preset === "ad" ? "displayName" : "cn",
          usernameAttribute: preset === "ad" ? "sAMAccountName" : "uid",
          useSsl: false,
          startTls: false,
          requireTls: preset === "ad",
        },
      }));
    },
    [applyFormUpdate]
  );

  const loadLdapDirectory = useCallback(
    async (targetBaseDn: string | null, nextPath: string[]) => {
      const { valid, payload } = validateForm(form, { skipIdentityFields: true });
      if (!valid || !payload || payload.driver !== "ldap") {
        setLdapBrowserError("Provide a valid LDAP configuration before browsing.");
        setLdapBrowserEntries([]);
        return;
      }

      setLdapBrowserBusy(true);
      setLdapBrowserError(null);

      try {
        const response = await browseLdapDirectory({
          driver: "ldap",
          config: payload.config,
          base_dn: targetBaseDn ?? null,
        });

        setLdapBrowserEntries(response.entries);
        setLdapBrowserBaseDn(response.base_dn ?? targetBaseDn);
        setLdapBrowserPath(nextPath);
      } catch (error) {
        if (error instanceof HttpError) {
          const body = error.body;
          const message =
            body && typeof body === "object" && !Array.isArray(body) && "message" in body && body.message
              ? String((body as { message?: unknown }).message)
              : error.message;
          setLdapBrowserError(message);
        } else {
          setLdapBrowserError("Unable to browse the directory. Check connection details and try again.");
        }
        setLdapBrowserEntries([]);
      } finally {
        setLdapBrowserBusy(false);
      }
    },
    [form, validateForm]
  );

  const openLdapBrowser = useCallback(() => {
    setLdapBrowserError(null);
    setLdapBrowserEntries([]);
    setLdapBrowserPath([]);
    setLdapBrowserBaseDn(null);
    setLdapBrowserOpen(true);
    void loadLdapDirectory(null, []);
  }, [loadLdapDirectory]);

  const closeLdapBrowser = useCallback(() => {
    if (ldapBrowserBusy) {
      return;
    }

    setLdapBrowserOpen(false);
    setLdapBrowserError(null);
  }, [ldapBrowserBusy]);

  const handleLdapNavigateTo = useCallback(
    (dn: string | null, index: number) => {
      const nextPath = dn === null ? [] : ldapBrowserPath.slice(0, index + 1);
      void loadLdapDirectory(dn, nextPath);
    },
    [ldapBrowserPath, loadLdapDirectory]
  );

  const handleLdapOpenChild = useCallback(
    (entry: LdapBrowseEntry) => {
      void loadLdapDirectory(entry.dn, [...ldapBrowserPath, entry.dn]);
    },
    [ldapBrowserPath, loadLdapDirectory]
  );

  const handleLdapUseBaseDn = useCallback(
    (dn: string) => {
      applyFormUpdate((prev) => ({
        ...prev,
        ldap: {
          ...prev.ldap,
          baseDn: dn,
        },
      }));
      setLdapBrowserOpen(false);
    },
    [applyFormUpdate]
  );

  const handleLdapUseFilter = useCallback(
    (entry: LdapBrowseEntry) => {
      const parts = entry.rdn.split("=", 2);
      if (parts.length !== 2) {
        applyFormUpdate((prev) => ({
          ...prev,
          ldap: {
            ...prev.ldap,
            userFilter: prev.ldap.userFilter,
          },
        }));
        return;
      }

      const attribute = parts[0].trim().toLowerCase();
      applyFormUpdate((prev) => ({
        ...prev,
        ldap: {
          ...prev.ldap,
          userFilter: `(&(objectClass=person)(${attribute}={{username}}))`,
        },
      }));
      setLdapBrowserOpen(false);
    },
    [applyFormUpdate]
  );

  const renderOidcLikeFields = (driver: "oidc" | "entra"): JSX.Element => {
    const state = driver === "oidc" ? form.oidc : form.entra;
    const loading = metadataLoading[driver];
    const baseId = driver === "oidc" ? "oidc" : "entra";
    const displayName = driver === "entra" ? "Microsoft Entra ID" : "OIDC";
    const metadataPlaceholder =
      driver === "entra"
        ? "https://login.microsoftonline.com/<tenant>/v2.0/.well-known/openid-configuration"
        : "https://example.okta.com/oauth2/default/.well-known/openid-configuration";

    return (
      <div>
        <h2 className="h6 mb-2">{displayName} configuration</h2>
        <p className="text-muted small mb-3">
          Capture the discovery details and credentials required for {displayName} sign-in.
        </p>
        <div className="row g-3">
          <div className="col-12">
            <label htmlFor={`${baseId}-issuer`} className="form-label">
              Issuer URL
            </label>
            <input
              id={`${baseId}-issuer`}
              type="url"
              className={`form-control${fieldError(`${driver}.issuer`) ? " is-invalid" : ""}`}
              value={state.issuer}
              onChange={(event) =>
                applyFormUpdate((prev) =>
                  driver === "oidc"
                    ? { ...prev, oidc: { ...prev.oidc, issuer: event.target.value } }
                    : { ...prev, entra: { ...prev.entra, issuer: event.target.value } }
                )
              }
              disabled={createBusy}
              placeholder={
                driver === "entra"
                  ? "https://login.microsoftonline.com/<tenant>/v2.0"
                  : "https://example.okta.com/oauth2/default"
              }
              inputMode="url"
              autoComplete="off"
            />
            {fieldError(`${driver}.issuer`) ? (
              <div className="invalid-feedback">{fieldError(`${driver}.issuer`)}</div>
            ) : null}
            <div className="form-text">Use the issuer value reported by the discovery document.</div>
          </div>

          {driver === "entra" ? (
            <div className="col-12 col-md-6">
              <label htmlFor="entra-tenant-id" className="form-label">
                Tenant ID
              </label>
              <input
                id="entra-tenant-id"
                type="text"
                className={`form-control${fieldError("entra.tenantId") ? " is-invalid" : ""}`}
                value={form.entra.tenantId}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({
                    ...prev,
                    entra: { ...prev.entra, tenantId: event.target.value },
                  }))
                }
                disabled={createBusy}
                placeholder="11111111-2222-3333-4444-555555555555"
                autoComplete="off"
              />
              {fieldError("entra.tenantId") ? (
                <div className="invalid-feedback">{fieldError("entra.tenantId")}</div>
              ) : null}
              <div className="form-text">Azure tenant identifier (GUID or domain).</div>
            </div>
          ) : null}

          <div className="col-12 col-md-6">
            <label htmlFor={`${baseId}-client-id`} className="form-label">
              Client ID
            </label>
            <input
              id={`${baseId}-client-id`}
              type="text"
              className={`form-control${fieldError(`${driver}.clientId`) ? " is-invalid" : ""}`}
              value={state.clientId}
              onChange={(event) =>
                applyFormUpdate((prev) =>
                  driver === "oidc"
                    ? { ...prev, oidc: { ...prev.oidc, clientId: event.target.value } }
                    : { ...prev, entra: { ...prev.entra, clientId: event.target.value } }
                )
              }
              disabled={createBusy}
              placeholder="0oa1example123"
              autoComplete="off"
            />
            {fieldError(`${driver}.clientId`) ? (
              <div className="invalid-feedback">{fieldError(`${driver}.clientId`)}</div>
            ) : null}
            <div className="form-text">Application identifier issued by the provider.</div>
          </div>

          <div className="col-12 col-md-6">
            <label htmlFor={`${baseId}-client-secret`} className="form-label">
              Client secret
            </label>
            <input
              id={`${baseId}-client-secret`}
              type="password"
              className={`form-control${fieldError(`${driver}.clientSecret`) ? " is-invalid" : ""}`}
              value={state.clientSecret}
              onChange={(event) =>
                applyFormUpdate((prev) =>
                  driver === "oidc"
                    ? { ...prev, oidc: { ...prev.oidc, clientSecret: event.target.value } }
                    : { ...prev, entra: { ...prev.entra, clientSecret: event.target.value } }
                )
              }
              disabled={createBusy}
              placeholder="••••••••••••"
              autoComplete="off"
            />
            {fieldError(`${driver}.clientSecret`) ? (
              <div className="invalid-feedback">{fieldError(`${driver}.clientSecret`)}</div>
            ) : null}
            <div className="form-text">Copy the client secret exactly as issued.</div>
          </div>

          <div className="col-12 col-md-6">
            <label htmlFor={`${baseId}-scopes`} className="form-label">
              Scopes
            </label>
            <input
              id={`${baseId}-scopes`}
              type="text"
              className="form-control"
              value={state.scopes}
              onChange={(event) =>
                applyFormUpdate((prev) =>
                  driver === "oidc"
                    ? { ...prev, oidc: { ...prev.oidc, scopes: event.target.value } }
                    : { ...prev, entra: { ...prev.entra, scopes: event.target.value } }
                )
              }
              disabled={createBusy}
              placeholder="openid profile email"
              autoComplete="off"
            />
            <div className="form-text">Space separated list. Leave blank to use the defaults.</div>
          </div>

          <div className="col-12">
            <label htmlFor={`${baseId}-redirect-uris`} className="form-label">
              Redirect URIs
            </label>
            <textarea
              id={`${baseId}-redirect-uris`}
              className={`form-control font-monospace${fieldError(`${driver}.redirectUris`) ? " is-invalid" : ""}`}
              rows={3}
              value={state.redirectUris}
              onChange={(event) =>
                applyFormUpdate((prev) =>
                  driver === "oidc"
                    ? { ...prev, oidc: { ...prev.oidc, redirectUris: event.target.value } }
                    : { ...prev, entra: { ...prev.entra, redirectUris: event.target.value } }
                )
              }
              disabled={createBusy}
              placeholder="https://app.example.com/auth/callback"
            />
            {fieldError(`${driver}.redirectUris`) ? (
              <div className="invalid-feedback">{fieldError(`${driver}.redirectUris`)}</div>
            ) : null}
            <div className="form-text">One URL per line or separate with commas.</div>
          </div>

          <div className="col-12">
            <label htmlFor={`${baseId}-metadata-url`} className="form-label">
              Metadata URL
            </label>
            <div className="input-group">
              <input
                id={`${baseId}-metadata-url`}
                type="url"
                className={`form-control${fieldError(`${driver}.metadataUrl`) ? " is-invalid" : ""}`}
                value={state.metadataUrl}
                onChange={(event) =>
                  applyFormUpdate((prev) =>
                    driver === "oidc"
                      ? { ...prev, oidc: { ...prev.oidc, metadataUrl: event.target.value } }
                      : { ...prev, entra: { ...prev.entra, metadataUrl: event.target.value } }
                  )
                }
                placeholder={metadataPlaceholder}
                disabled={createBusy || loading}
                inputMode="url"
                autoComplete="off"
              />
              <button
                type="button"
                className="btn btn-outline-secondary"
                onClick={() => void handleOidcMetadataFetch(driver)}
                disabled={createBusy || loading}
              >
                {loading ? (
                  <>
                    <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
                    Fetching...
                  </>
                ) : (
                  "Fetch & Autofill"
                )}
              </button>
            </div>
            {fieldError(`${driver}.metadataUrl`) ? (
              <div className="invalid-feedback d-block">{fieldError(`${driver}.metadataUrl`)}</div>
            ) : null}
            <div className="form-text">
              Pull the discovery document directly (if CORS allows) to pre-fill issuer details.
            </div>
          </div>

          <div className="col-12">
            <label htmlFor={`${baseId}-metadata-file`} className="form-label">
              Metadata file
            </label>
            <input
              id={`${baseId}-metadata-file`}
              type="file"
              className="form-control"
              accept=".json,application/json,.txt"
              onChange={(event) => handleOidcMetadataFileChange(driver, event)}
              disabled={createBusy || loading}
            />
            <div className="form-text">
              Upload a saved discovery document if downloading from the provider is blocked.
            </div>
          </div>
        </div>
      </div>
    );
  };

  const renderSamlFields = (): JSX.Element => {
    const loading = metadataLoading.saml;

    return (
      <div>
        <h2 className="h6 mb-2">SAML configuration</h2>
        <p className="text-muted small mb-3">
          Provide the entity ID, SSO endpoint, and signing certificate from your IdP metadata.
        </p>
        <div className="row g-3">
          <div className="col-12">
            <label htmlFor="saml-entity-id" className="form-label">
              Entity ID
            </label>
            <input
              id="saml-entity-id"
              type="text"
              className={`form-control${fieldError("saml.entityId") ? " is-invalid" : ""}`}
              value={form.saml.entityId}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, saml: { ...prev.saml, entityId: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="urn:example:idp"
              autoComplete="off"
            />
            {fieldError("saml.entityId") ? (
              <div className="invalid-feedback">{fieldError("saml.entityId")}</div>
            ) : null}
            <div className="form-text">The IdP entity identifier (sometimes called audience or issuer).</div>
          </div>

          <div className="col-12">
            <label htmlFor="saml-sso-url" className="form-label">
              SSO URL
            </label>
            <input
              id="saml-sso-url"
              type="url"
              className={`form-control${fieldError("saml.ssoUrl") ? " is-invalid" : ""}`}
              value={form.saml.ssoUrl}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, saml: { ...prev.saml, ssoUrl: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="https://idp.example.com/saml2"
              inputMode="url"
              autoComplete="off"
            />
            {fieldError("saml.ssoUrl") ? (
              <div className="invalid-feedback">{fieldError("saml.ssoUrl")}</div>
            ) : null}
            <div className="form-text">HTTP-Redirect endpoint for authentication requests.</div>
          </div>

          <div className="col-12">
            <label htmlFor="saml-certificate" className="form-label">
              Signing certificate
            </label>
            <textarea
              id="saml-certificate"
              className={`form-control font-monospace${fieldError("saml.certificate") ? " is-invalid" : ""}`}
              rows={5}
              value={form.saml.certificate}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, saml: { ...prev.saml, certificate: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="-----BEGIN CERTIFICATE-----"
            />
            {fieldError("saml.certificate") ? (
              <div className="invalid-feedback">{fieldError("saml.certificate")}</div>
            ) : null}
            <div className="form-text">Paste the full PEM encoded signing certificate from your IdP.</div>
          </div>

          <div className="col-12">
            <label htmlFor="saml-metadata-url" className="form-label">
              Metadata URL
            </label>
            <div className="input-group">
              <input
                id="saml-metadata-url"
                type="url"
                className={`form-control${fieldError("saml.metadataUrl") ? " is-invalid" : ""}`}
                value={form.saml.metadataUrl}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({ ...prev, saml: { ...prev.saml, metadataUrl: event.target.value } }))
                }
                placeholder="https://idp.example.com/federationmetadata.xml"
                disabled={createBusy || loading}
                inputMode="url"
                autoComplete="off"
              />
              <button
                type="button"
                className="btn btn-outline-secondary"
                onClick={() => void handleSamlMetadataFetch()}
                disabled={createBusy || loading}
              >
                {loading ? (
                  <>
                    <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
                    Fetching...
                  </>
                ) : (
                  "Fetch & Autofill"
                )}
              </button>
            </div>
            {fieldError("saml.metadataUrl") ? (
              <div className="invalid-feedback d-block">{fieldError("saml.metadataUrl")}</div>
            ) : null}
            <div className="form-text">
              Provide the federation metadata URL to import values automatically (subject to CORS).
            </div>
          </div>

          <div className="col-12">
            <label htmlFor="saml-metadata-file" className="form-label">
              Metadata file
            </label>
            <input
              id="saml-metadata-file"
              type="file"
              className="form-control"
              accept=".xml,application/xml,text/xml"
              onChange={handleSamlMetadataFileChange}
              disabled={createBusy || loading}
            />
            <div className="form-text">
              Upload an XML metadata file to parse entity ID, SSO URL, and certificate automatically.
              {form.saml.metadataFileName ? ` Last uploaded: ${form.saml.metadataFileName}` : ""}
            </div>
          </div>
        </div>
      </div>
    );
  };

  const renderLdapFields = (): JSX.Element => {
    const bindStrategy = form.ldap.bindStrategy;

    return (
      <div>
        <h2 className="h6 mb-2">LDAP configuration</h2>
        <p className="text-muted small mb-3">
          Define how we connect, bind, and map attributes from your directory server.
        </p>
        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
          <div className="btn-group" role="group" aria-label="LDAP presets">
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={() => applyLdapPreset("ad")}
              disabled={createBusy}
            >
              Active Directory preset
            </button>
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={() => applyLdapPreset("generic")}
              disabled={createBusy}
            >
              Generic LDAP preset
            </button>
          </div>
          <button
            type="button"
            className="btn btn-outline-primary btn-sm ms-auto"
            onClick={openLdapBrowser}
            disabled={createBusy || ldapBrowserBusy}
          >
            {ldapBrowserBusy ? (
              <>
                <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
                Loading...
              </>
            ) : (
              "Browse directory"
            )}
          </button>
        </div>
        <div className="row g-3">
          <div className="col-12 col-md-6">
            <label htmlFor="ldap-host" className="form-label">
              Server host
            </label>
            <input
              id="ldap-host"
              type="text"
              className={`form-control${fieldError("ldap.host") ? " is-invalid" : ""}`}
              value={form.ldap.host}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, host: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="ldap.example.com"
              autoComplete="off"
            />
            {fieldError("ldap.host") ? <div className="invalid-feedback">{fieldError("ldap.host")}</div> : null}
            <div className="form-text">Hostname or IP address of the LDAP server.</div>
          </div>

          <div className="col-6 col-md-3">
            <label htmlFor="ldap-port" className="form-label">
              Port
            </label>
            <input
              id="ldap-port"
              type="number"
              min={1}
              max={65535}
              className={`form-control${fieldError("ldap.port") ? " is-invalid" : ""}`}
              value={form.ldap.port}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, port: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="389"
            />
            {fieldError("ldap.port") ? <div className="invalid-feedback">{fieldError("ldap.port")}</div> : null}
            <div className="form-text">Leave blank to use 389 or 636 with SSL.</div>
          </div>

          <div className="col-6 col-md-3">
            <label htmlFor="ldap-timeout" className="form-label">
              Timeout (s)
            </label>
            <input
              id="ldap-timeout"
              type="number"
              min={1}
              max={120}
              className={`form-control${fieldError("ldap.timeout") ? " is-invalid" : ""}`}
              value={form.ldap.timeout}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, timeout: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="15"
            />
            {fieldError("ldap.timeout") ? <div className="invalid-feedback">{fieldError("ldap.timeout")}</div> : null}
            <div className="form-text">Optional network timeout in seconds.</div>
          </div>

          <div className="col-12">
            <label htmlFor="ldap-base-dn" className="form-label">
              Base DN
            </label>
            <input
              id="ldap-base-dn"
              type="text"
              className={`form-control${fieldError("ldap.baseDn") ? " is-invalid" : ""}`}
              value={form.ldap.baseDn}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, baseDn: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="dc=example,dc=com"
              autoComplete="off"
            />
            {fieldError("ldap.baseDn") ? <div className="invalid-feedback">{fieldError("ldap.baseDn")}</div> : null}
            <div className="form-text">Search base used for users and lookups.</div>
          </div>

          <div className="col-12 col-md-4">
            <div className="form-check form-switch pt-2">
              <input
                id="ldap-use-ssl"
                className="form-check-input"
                type="checkbox"
                role="switch"
                checked={form.ldap.useSsl}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, useSsl: event.target.checked } }))
                }
                disabled={createBusy}
              />
              <label className="form-check-label" htmlFor="ldap-use-ssl">
                Use LDAPS (SSL)
              </label>
            </div>
            <div className="form-text">Connect over LDAPS on port 636.</div>
          </div>

          <div className="col-12 col-md-4">
            <div className="form-check form-switch pt-2">
              <input
                id="ldap-start-tls"
                className="form-check-input"
                type="checkbox"
                role="switch"
                checked={form.ldap.startTls}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, startTls: event.target.checked } }))
                }
                disabled={createBusy}
              />
              <label className="form-check-label" htmlFor="ldap-start-tls">
                StartTLS
              </label>
            </div>
            <div className="form-text">Start TLS after connecting on the standard port.</div>
          </div>

          <div className="col-12 col-md-4">
            <div className="form-check form-switch pt-2">
              <input
                id="ldap-require-tls"
                className="form-check-input"
                type="checkbox"
                role="switch"
                checked={form.ldap.requireTls}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, requireTls: event.target.checked } }))
                }
                disabled={createBusy}
              />
              <label className="form-check-label" htmlFor="ldap-require-tls">
                Require TLS
              </label>
            </div>
            <div className="form-text">Prevent binds unless SSL or StartTLS is enabled.</div>
          </div>

          <div className="col-12 col-md-6">
            <label htmlFor="ldap-bind-strategy" className="form-label">
              Bind strategy
            </label>
            <select
              id="ldap-bind-strategy"
              className={`form-select${fieldError("ldap.bindStrategy") ? " is-invalid" : ""}`}
              value={bindStrategy}
              onChange={(event) =>
                applyFormUpdate((prev) => ({
                  ...prev,
                  ldap: { ...prev.ldap, bindStrategy: event.target.value as LdapFormState["bindStrategy"] },
                }))
              }
              disabled={createBusy}
            >
              <option value="service">Service account (search + rebind)</option>
              <option value="direct">Direct user DN bind</option>
            </select>
            {fieldError("ldap.bindStrategy") ? (
              <div className="invalid-feedback">{fieldError("ldap.bindStrategy")}</div>
            ) : null}
            <div className="form-text">Choose how user credentials are verified.</div>
          </div>

          {bindStrategy === "service" ? (
            <>
              <div className="col-12 col-md-6">
                <label htmlFor="ldap-bind-dn" className="form-label">
                  Bind DN
                </label>
                <input
                  id="ldap-bind-dn"
                  type="text"
                  className={`form-control${fieldError("ldap.bindDn") ? " is-invalid" : ""}`}
                  value={form.ldap.bindDn}
                  onChange={(event) =>
                    applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, bindDn: event.target.value } }))
                  }
                  disabled={createBusy}
                  placeholder="cn=service,ou=accounts,dc=example,dc=com"
                  autoComplete="off"
                />
                {fieldError("ldap.bindDn") ? (
                  <div className="invalid-feedback">{fieldError("ldap.bindDn")}</div>
                ) : null}
                <div className="form-text">Service account used to search for user entries.</div>
              </div>

              <div className="col-12 col-md-6">
                <label htmlFor="ldap-bind-password" className="form-label">
                  Bind password
                </label>
                <input
                  id="ldap-bind-password"
                  type="password"
                  className={`form-control${fieldError("ldap.bindPassword") ? " is-invalid" : ""}`}
                  value={form.ldap.bindPassword}
                  onChange={(event) =>
                    applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, bindPassword: event.target.value } }))
                  }
                  disabled={createBusy}
                  placeholder="••••••••"
                  autoComplete="off"
                />
                {fieldError("ldap.bindPassword") ? (
                  <div className="invalid-feedback">{fieldError("ldap.bindPassword")}</div>
                ) : null}
                <div className="form-text">Credentials for the service account above.</div>
              </div>
            </>
          ) : (
            <div className="col-12">
              <label htmlFor="ldap-user-dn-template" className="form-label">
                User DN template
              </label>
              <input
                id="ldap-user-dn-template"
                type="text"
                className={`form-control${fieldError("ldap.userDnTemplate") ? " is-invalid" : ""}`}
                value={form.ldap.userDnTemplate}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, userDnTemplate: event.target.value } }))
                }
                disabled={createBusy}
                placeholder="uid={{username}},ou=people,dc=example,dc=com"
                autoComplete="off"
              />
              {fieldError("ldap.userDnTemplate") ? (
                <div className="invalid-feedback">{fieldError("ldap.userDnTemplate")}</div>
              ) : null}
              <div className="form-text">{"Must include the placeholder {{username}}."}</div>
            </div>
          )}

          <div className="col-12">
            <label htmlFor="ldap-user-filter" className="form-label">
              User search filter
            </label>
            <input
              id="ldap-user-filter"
              type="text"
              className={`form-control${fieldError("ldap.userFilter") ? " is-invalid" : ""}`}
              value={form.ldap.userFilter}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, userFilter: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="(&(objectClass=person)(uid={{username}}))"
              autoComplete="off"
            />
            {fieldError("ldap.userFilter") ? (
              <div className="invalid-feedback">{fieldError("ldap.userFilter")}</div>
            ) : null}
            <div className="form-text">{"Include the {{username}} placeholder for the authenticating user."}</div>
          </div>

          <div className="col-12 col-md-4">
            <label htmlFor="ldap-email-attribute" className="form-label">
              Email attribute
            </label>
            <input
              id="ldap-email-attribute"
              type="text"
              className={`form-control${fieldError("ldap.emailAttribute") ? " is-invalid" : ""}`}
              value={form.ldap.emailAttribute}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, emailAttribute: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="mail"
              autoComplete="off"
            />
            {fieldError("ldap.emailAttribute") ? (
              <div className="invalid-feedback">{fieldError("ldap.emailAttribute")}</div>
            ) : null}
            <div className="form-text">Attribute containing the user&apos;s email address.</div>
          </div>

          <div className="col-12 col-md-4">
            <label htmlFor="ldap-name-attribute" className="form-label">
              Display name attribute
            </label>
            <input
              id="ldap-name-attribute"
              type="text"
              className={`form-control${fieldError("ldap.nameAttribute") ? " is-invalid" : ""}`}
              value={form.ldap.nameAttribute}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, nameAttribute: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="cn"
              autoComplete="off"
            />
            {fieldError("ldap.nameAttribute") ? (
              <div className="invalid-feedback">{fieldError("ldap.nameAttribute")}</div>
            ) : null}
            <div className="form-text">Attribute used for the user&apos;s display name.</div>
          </div>

          <div className="col-12 col-md-4">
            <label htmlFor="ldap-username-attribute" className="form-label">
              Username attribute
            </label>
            <input
              id="ldap-username-attribute"
              type="text"
              className={`form-control${fieldError("ldap.usernameAttribute") ? " is-invalid" : ""}`}
              value={form.ldap.usernameAttribute}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, usernameAttribute: event.target.value } }))
              }
              disabled={createBusy}
              placeholder="uid"
              autoComplete="off"
            />
            {fieldError("ldap.usernameAttribute") ? (
              <div className="invalid-feedback">{fieldError("ldap.usernameAttribute")}</div>
            ) : null}
            <div className="form-text">Attribute used to match usernames during login.</div>
          </div>
        </div>
      </div>
    );
  };

  const renderDriverFields = (): JSX.Element | null => {
    if (form.driver === "oidc" || form.driver === "entra") {
      return renderOidcLikeFields(form.driver);
    }

    if (form.driver === "saml") {
      return renderSamlFields();
    }

    if (form.driver === "ldap") {
      return renderLdapFields();
    }

    return null;
  };

  return (
    <section className="container py-3">
      <header className="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div>
          <h1 className="h3 mb-1">Identity Providers</h1>
          <p className="text-muted mb-0">
            Manage external authentication providers, toggle availability, and adjust evaluation order.
          </p>
        </div>
        <div className="d-flex gap-2">
          <button type="button" className="btn btn-primary" onClick={openCreateModal} disabled={busy}>
            Add Provider
          </button>
        </div>
      </header>

      {stubMode ? (
        <div className="alert alert-info d-flex align-items-start gap-2" role="status">
          <span className="fw-semibold">Stub mode:</span>
          <span>
            Persistence is disabled in this environment. Changes are accepted in-memory but not written to storage.
          </span>
        </div>
      ) : null}

      {error ? (
        <div className="alert alert-danger" role="alert">
          {error}
        </div>
      ) : null}

      <div className="card mb-4">
        <div className="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
          <div>
            <h2 className="h5 mb-1">Provider summary</h2>
            <p className="text-muted mb-0">Highest priority providers appear first. Evaluation order updates immediately.</p>
          </div>
          <dl className="row mb-0 small text-muted text-md-end">
            <div className="col-6 col-md-auto">
              <dt className="fw-normal">Total</dt>
              <dd className="fw-semibold mb-0">{meta.total}</dd>
            </div>
            <div className="col-6 col-md-auto">
              <dt className="fw-normal">Enabled</dt>
              <dd className="fw-semibold mb-0">{meta.enabled}</dd>
            </div>
            <div className="col-12 col-md-auto mt-2 mt-md-0">
              <dt className="fw-normal">Refresh</dt>
              <dd className="mb-0">
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  onClick={() => loadProviders({ silent: true })}
                  disabled={loading || refreshing || busy}
                >
                  {refreshing ? (
                    <>
                      <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
                      Loading...
                    </>
                  ) : (
                    "Reload"
                  )}
                </button>
              </dd>
            </div>
          </dl>
        </div>
      </div>

      {loading ? (
        <div className="d-flex justify-content-center align-items-center py-5" role="status" aria-live="polite">
          <div className="spinner-border text-primary" role="presentation" aria-hidden="true" />
          <span className="visually-hidden">Loading providers...</span>
        </div>
      ) : orderedProviders.length === 0 ? (
        <div className="card">
          <div className="card-body text-center py-5">
            <p className="lead mb-2">No providers configured</p>
            <p className="text-muted mb-4">
              Configure an Identity Provider to enable external authentication. Use the “Add Provider” button to get started.
            </p>
            <button type="button" className="btn btn-primary" onClick={openCreateModal} disabled={busy}>
              Add Provider
            </button>
          </div>
        </div>
      ) : (
        <div className="table-responsive">
          <table className="table align-middle">
            <thead>
              <tr>
                <th scope="col" style={{ width: "3rem" }} className="text-center">
                  #
                </th>
                <th scope="col">Provider</th>
                <th scope="col">Idp Type</th>
                <th scope="col">Key</th>
                <th scope="col">Status</th>
                <th scope="col" style={{ width: "12rem" }} className="text-end">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {orderedProviders.map((provider, index) => {
                const identifier = providerIdentifier(provider);
                const disabled = disabledIds.has(identifier) || busy;
                const canMoveUp = index > 0;
                const canMoveDown = index < orderedProviders.length - 1;

                return (
                  <tr key={identifier}>
                    <td className="text-center fw-semibold">{provider.evaluation_order}</td>
                    <td>
                      <div className="fw-semibold">{provider.name}</div>
                      <div className="small text-muted">
                        Added {new Date(provider.created_at).toLocaleString(undefined, { hour12: false })}
                      </div>
                    </td>
                    <td className="text-uppercase fw-semibold small">{provider.driver}</td>
                    <td>
                      <code>{provider.key}</code>
                    </td>
                    <td>
                      <span
                        className={`badge rounded-pill ${
                          provider.enabled ? "text-bg-success" : "text-bg-secondary"
                        }`}
                      >
                        {provider.enabled ? "Enabled" : "Disabled"}
                      </span>
                    </td>
                    <td className="text-end">
                      <div className="btn-group btn-group-sm" role="group" aria-label={`Actions for ${provider.name}`}>
                        <button
                          type="button"
                          className="btn btn-outline-secondary"
                          onClick={() => handleReorder(provider, "up")}
                          disabled={!canMoveUp || disabled}
                          aria-label={`Move ${provider.name} higher`}
                        >
                          ↑
                        </button>
                        <button
                          type="button"
                          className="btn btn-outline-secondary"
                          onClick={() => handleReorder(provider, "down")}
                          disabled={!canMoveDown || disabled}
                          aria-label={`Move ${provider.name} lower`}
                        >
                          ↓
                        </button>
                        <button
                          type="button"
                          className={`btn ${provider.enabled ? "btn-outline-warning" : "btn-outline-success"}`}
                          onClick={() => handleToggle(provider)}
                          disabled={disabled}
                        >
                          {provider.enabled ? "Disable" : "Enable"}
                        </button>
                        <button
                          type="button"
                          className="btn btn-outline-danger"
                          onClick={() => openDeleteModal(provider)}
                          disabled={disabled}
                        >
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <ConfirmModal
        open={deleteTarget !== null}
        title={deleteTarget ? `Delete ${deleteTarget.name}?` : "Delete provider"}
        busy={deleteBusy}
        onCancel={closeDeleteModal}
        onConfirm={confirmDelete}
        confirmLabel="Delete"
        confirmTone="danger"
      >
        <p className="mb-2">
          This will remove <strong>{deleteTarget?.name}</strong> and collapse remaining providers up to fill the gap.
        </p>
        <p className="mb-0">Are you sure you want to continue?</p>
        {deleteError ? (
          <p className="text-danger small mb-0 mt-3" role="alert">
            {deleteError}
          </p>
        ) : null}
      </ConfirmModal>

      <ConfirmModal
        open={createOpen}
        title="Add Identity Provider"
        onCancel={closeCreateModal}
        onConfirm={submitCreate}
        busy={createBusy}
        confirmLabel="Create"
        confirmTone="primary"
        confirmDisabled={createBusy}
        dialogClassName="modal-dialog modal-dialog-centered modal-xl"
        bodyClassName="modal-body pt-0"
      >
        <form
          className="d-flex flex-column gap-4"
          onSubmit={(event) => {
            event.preventDefault();
            void submitCreate();
          }}
        >
          {formErrors.general ? (
            <p className="text-danger small mb-0" role="alert">
              {formErrors.general}
            </p>
          ) : null}
          <div className="row g-3">
            <div className="col-12 col-md-6">
              <label htmlFor="idp-key" className="form-label">
                Provider key
              </label>
              <input
                id="idp-key"
                name="key"
                type="text"
                className={`form-control${fieldError("key") ? " is-invalid" : ""}`}
                value={form.key}
                onChange={(event) => applyFormUpdate((prev) => ({ ...prev, key: event.target.value }))}
                placeholder="azure-entraid"
                disabled={createBusy}
                autoComplete="off"
                required
              />
              {fieldError("key") ? <div className="invalid-feedback">{fieldError("key")}</div> : null}
              <div className="form-text">Lowercase slug used in API responses and audit logs.</div>
            </div>
            <div className="col-12 col-md-6">
              <label htmlFor="idp-name" className="form-label">
                Display name
              </label>
              <input
                id="idp-name"
                name="name"
                type="text"
                className={`form-control${fieldError("name") ? " is-invalid" : ""}`}
                value={form.name}
                onChange={(event) => applyFormUpdate((prev) => ({ ...prev, name: event.target.value }))}
                placeholder="Microsoft Entra - Primary"
                disabled={createBusy}
                autoComplete="off"
                required
              />
              {fieldError("name") ? <div className="invalid-feedback">{fieldError("name")}</div> : null}
              <div className="form-text">Shown to users on the login screen.</div>
            </div>
            <div className="col-12 col-md-6">
              <label htmlFor="idp-driver" className="form-label">
                Idp Type
              </label>
              <select
                id="idp-driver"
                name="driver"
                className={`form-select${fieldError("driver") ? " is-invalid" : ""}`}
                value={form.driver}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({
                    ...prev,
                    driver: event.target.value as IdpProviderDriver,
                  }))
                }
                disabled={createBusy}
                required
              >
                {DRIVER_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
              {fieldError("driver") ? <div className="invalid-feedback">{fieldError("driver")}</div> : null}
              <div className="form-text">Choose the integration style for this provider.</div>
            </div>
            <div className="col-12 col-md-6">
              <div className="form-check form-switch pt-2">
                <input
                  id="idp-enabled"
                  name="enabled"
                  type="checkbox"
                  className="form-check-input"
                  role="switch"
                  checked={form.enabled}
                  onChange={(event) => applyFormUpdate((prev) => ({ ...prev, enabled: event.target.checked }))}
                  disabled={createBusy}
                />
                <label className="form-check-label" htmlFor="idp-enabled">
                  Enable immediately
                </label>
              </div>
              <div className="form-text">You can toggle availability later from the overview.</div>
            </div>
          </div>

          <div className="border-top pt-3">{renderDriverFields()}</div>

          <div className="border-top pt-3">
            <div className="d-flex flex-wrap gap-3 align-items-center">
              <button
                type="button"
                className="btn btn-outline-info"
                onClick={() => void handleTestConfiguration()}
                disabled={createBusy || testBusy}
              >
                {testBusy ? (
                  <>
                    <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
                    Testing...
                  </>
                ) : (
                  "Test configuration"
                )}
              </button>
              {testResult ? (
                <span
                  className={`badge rounded-pill ${
                    testResult.status === "ok"
                      ? "text-bg-success"
                      : testResult.status === "warning"
                      ? "text-bg-warning"
                      : "text-bg-danger"
                  }`}
                >
                  {testResult.status.toUpperCase()}
                </span>
              ) : null}
              {testResult ? (
                <span className="text-muted small">
                  Checked {new Date(testResult.checked_at).toLocaleString(undefined, { hour12: false })}
                </span>
              ) : null}
            </div>
            {testError ? (
              <div className="alert alert-danger mt-3 mb-0" role="alert">
                {testError}
              </div>
            ) : null}
            {testResult ? (
              <div
                className={`alert mt-3 mb-0 ${
                  testResult.status === "ok"
                    ? "alert-success"
                    : testResult.status === "warning"
                    ? "alert-warning"
                    : "alert-danger"
                }`}
                role="status"
              >
                <div className="fw-semibold mb-1">{testResult.message}</div>
                {Object.keys(testResult.details ?? {}).length > 0 ? (
                  <pre className="mt-2 mb-0 bg-body-secondary border rounded p-2 small overflow-auto">
                    {JSON.stringify(testResult.details, null, 2)}
                  </pre>
                ) : null}
              </div>
            ) : null}
          </div>

          <div className="border-top pt-3">
            <label htmlFor="idp-meta" className="form-label">
              Additional metadata (JSON, optional)
            </label>
            <textarea
              id="idp-meta"
              name="meta"
              className={`form-control font-monospace${fieldError("meta") ? " is-invalid" : ""}`}
              rows={3}
              value={form.meta}
              onChange={(event) => applyFormUpdate((prev) => ({ ...prev, meta: event.target.value }))}
              disabled={createBusy}
              placeholder='{"display_region": "us-east"}'
            />
            {fieldError("meta") ? <div className="invalid-feedback">{fieldError("meta")}</div> : null}
            <div className="form-text">Optional structured notes for automation or UI hints.</div>
          </div>
        </form>
      </ConfirmModal>

      <ConfirmModal
        open={ldapBrowserOpen}
        title="Browse LDAP Directory"
        onCancel={closeLdapBrowser}
        onConfirm={closeLdapBrowser}
        confirmLabel="Close"
        confirmTone="secondary"
        busy={ldapBrowserBusy}
        confirmDisabled={ldapBrowserBusy}
        hideCancelButton
        dialogClassName="modal-dialog modal-dialog-centered modal-lg"
        bodyClassName="modal-body pt-0"
      >
        <p className="text-muted small mb-3">
          Drill into the directory to select a Base DN or to sample entries for your search filter. Directory browsing requires a
          service bind account.
        </p>
        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
          <span className="fw-semibold small text-uppercase text-muted">Path:</span>
          <button
            type="button"
            className="btn btn-link btn-sm px-0"
            onClick={() => handleLdapNavigateTo(null, -1)}
            disabled={ldapBrowserBusy}
          >
            Naming contexts
          </button>
          {ldapBrowserPath.map((dn, index) => (
            <span key={dn} className="d-flex align-items-center gap-2">
              <span className="text-muted">/</span>
              <button
                type="button"
                className="btn btn-link btn-sm px-0"
                onClick={() => handleLdapNavigateTo(dn, index)}
                disabled={ldapBrowserBusy}
              >
                {dn}
              </button>
            </span>
          ))}
        </div>
        {ldapBrowserError ? (
          <div className="alert alert-danger" role="alert">
            {ldapBrowserError}
          </div>
        ) : null}
        {ldapBrowserBusy ? (
          <div className="d-flex justify-content-center py-4" role="status" aria-live="polite">
            <div className="spinner-border text-primary" role="presentation" aria-hidden="true" />
            <span className="visually-hidden">Browsing directory...</span>
          </div>
        ) : ldapBrowserEntries.length === 0 ? (
          <p className="text-muted mb-0">
            {ldapBrowserBaseDn ? "No entries returned for this branch." : "No naming contexts returned. Provide the Base DN manually."}
          </p>
        ) : (
          <div className="list-group">
            {ldapBrowserEntries.map((entry) => (
              <div key={entry.dn} className="list-group-item">
                <div className="d-flex flex-column flex-lg-row justify-content-between gap-2">
                  <div>
                    <div className="fw-semibold">{entry.name}</div>
                    <div className="text-muted small">{entry.dn}</div>
                    <div className="text-muted small text-uppercase">{entry.type}</div>
                  </div>
                  <div className="d-flex flex-wrap gap-2">
                    {entry.has_children ? (
                      <button
                        type="button"
                        className="btn btn-outline-primary btn-sm"
                        onClick={() => handleLdapOpenChild(entry)}
                        disabled={ldapBrowserBusy}
                      >
                        Open
                      </button>
                    ) : null}
                    <button
                      type="button"
                      className="btn btn-outline-success btn-sm"
                      onClick={() => handleLdapUseBaseDn(entry.dn)}
                      disabled={ldapBrowserBusy}
                    >
                      Set as Base DN
                    </button>
                    {entry.type === "person" ? (
                      <button
                        type="button"
                        className="btn btn-outline-secondary btn-sm"
                        onClick={() => handleLdapUseFilter(entry)}
                        disabled={ldapBrowserBusy}
                      >
                        Use for filter
                      </button>
                    ) : null}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </ConfirmModal>
    </section>
  );
}
