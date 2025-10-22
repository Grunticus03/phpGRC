import { ChangeEvent, DragEvent, ReactNode, useCallback, useEffect, useMemo, useState } from "react";
import ConfirmModal from "../../components/modal/ConfirmModal";
import { useToast } from "../../components/toast/ToastProvider";
import {
  createIdpProvider,
  deleteIdpProvider,
  isStubResponse,
  listIdpProviders,
  updateIdpProvider,
  previewSamlMetadata,
  previewSamlMetadataFromUrl,
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
  fetchSamlSpConfig,
  type SamlServiceProviderInfo,
} from "../../lib/api/idpProviders";
import { HttpError } from "../../lib/api";
import "./IdpProviders.css";

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
  timeout: string;
  emailAttribute: string;
  nameAttribute: string;
  usernameAttribute: string;
  photoAttribute: string;
  useSsl: boolean;
  startTls: boolean;
  requireTls: boolean;
};

type FormState = {
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

type PendingAction = { type: "toggle" | "reorder" | "delete" | "create" | "update"; id: string | null };

const DRIVER_OPTIONS: Array<{ value: IdpProviderDriver; label: string }> = [
  { value: "entra", label: "Entra ID" },
  { value: "ldap", label: "LDAP" },
  { value: "oidc", label: "OIDC" },
  { value: "saml", label: "SAML" },
];

const LDAP_ROOT_KEY = "__ldap_root__";

type HoverHelpLabelProps = {
  htmlFor?: string;
  className?: string;
  helpText?: string;
  helpId?: string;
  children: ReactNode;
};

function HoverHelpLabel({ htmlFor, className, helpText, helpId, children }: HoverHelpLabelProps): JSX.Element {
  const hasHelp = Boolean(helpText && helpId);
  const classes = [className, hasHelp ? "hover-help-label" : null].filter(Boolean).join(" ").trim();

  return (
    <label htmlFor={htmlFor} className={classes || undefined}>
      {children}
      {hasHelp ? (
        <>
          <span className="visually-hidden" id={helpId}>
            {helpText}
          </span>
          <span className="hover-help-popover" aria-hidden="true">
            {helpText}
          </span>
        </>
      ) : null}
    </label>
  );
}

type ParsedLdapHost = {
  host: string;
  useSsl: boolean;
  port?: number;
};

function parseLdapConnectionUri(input: string): ParsedLdapHost | null {
  const trimmed = input.trim();
  if (trimmed === "") {
    return null;
  }

  let url: URL;
  try {
    url = new URL(trimmed);
  } catch {
    return null;
  }

  const protocol = url.protocol.toLowerCase();
  if (protocol !== "ldap:" && protocol !== "ldaps:") {
    return null;
  }

  if (!url.hostname) {
    return null;
  }

  const path = url.pathname ?? "";
  if ((path !== "" && path !== "/") || url.search || url.hash) {
    return null;
  }

  let port: number | undefined;
  if (url.port) {
    const parsed = Number(url.port);
    if (!Number.isInteger(parsed) || parsed < 1 || parsed > 65535) {
      return null;
    }
    port = parsed;
  }

  return {
    host: url.hostname,
    useSsl: protocol === "ldaps:",
    ...(port !== undefined ? { port } : {}),
  };
}

type LdapExpandTarget = { dn: string; path: string[] };

function normalizeDn(input: string): string {
  return input.trim().toLowerCase();
}

function buildLdapExpansionTargets(baseDn: string): LdapExpandTarget[] {
  const components = baseDn
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '');
  if (components.length === 0) {
    return [];
  }

  const domainStart = components.findIndex((part) => part.toUpperCase().startsWith('DC='));
  if (domainStart === -1) {
    return [];
  }

  const targets: LdapExpandTarget[] = [];
  const seen = new Set<string>();
  const domainDn = components.slice(domainStart).join(',');
  if (!seen.has(domainDn)) {
    seen.add(domainDn);
    targets.push({ dn: domainDn, path: [domainDn] });
  }

  const pathStack = [domainDn];
  for (let i = domainStart - 1; i >= 0; i -= 1) {
    const dn = components.slice(i).join(',');
    if (seen.has(dn)) continue;
    pathStack.push(dn);
    seen.add(dn);
    targets.push({ dn, path: [...pathStack] });
  }

  return targets;
}

function createDefaultFormState(): FormState {
  return {
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
      timeout: "",
      emailAttribute: "mail",
      nameAttribute: "cn",
      usernameAttribute: "uid",
      photoAttribute: "",
      useSsl: false,
      startTls: false,
      requireTls: false,
    },
  };
}

function isIdpProviderDriver(value: unknown): value is IdpProviderDriver {
  return value === "oidc" || value === "saml" || value === "ldap" || value === "entra";
}

function createFormStateFromProvider(provider: IdpProvider): FormState {
  const base = createDefaultFormState();
  base.name = provider.name ?? "";
  base.enabled = provider.enabled ?? true;
  base.meta =
    provider.meta && typeof provider.meta === "object" && Object.keys(provider.meta).length > 0
      ? JSON.stringify(provider.meta, null, 2)
      : "";

  if (isIdpProviderDriver(provider.driver)) {
    base.driver = provider.driver;
  }

  const config = provider.config ?? {};

  if (base.driver === "oidc") {
    const scopes =
      Array.isArray(config.scopes) && config.scopes.every((entry) => typeof entry === "string")
        ? (config.scopes as string[]).join(" ")
        : typeof config.scopes === "string"
        ? config.scopes
        : base.oidc.scopes;
    const redirectUris =
      Array.isArray(config.redirect_uris) && config.redirect_uris.every((entry) => typeof entry === "string")
        ? (config.redirect_uris as string[]).join("\n")
        : typeof config.redirect_uris === "string"
        ? config.redirect_uris
        : "";
    base.oidc = {
      issuer: typeof config.issuer === "string" ? config.issuer : "",
      clientId: typeof config.client_id === "string" ? config.client_id : "",
      clientSecret: typeof config.client_secret === "string" ? config.client_secret : "",
      scopes,
      redirectUris,
      metadataUrl: typeof config.metadata_url === "string" ? config.metadata_url : "",
    };
  } else if (base.driver === "entra") {
    const scopes =
      Array.isArray(config.scopes) && config.scopes.every((entry) => typeof entry === "string")
        ? (config.scopes as string[]).join(" ")
        : typeof config.scopes === "string"
        ? config.scopes
        : base.entra.scopes;
    const redirectUris =
      Array.isArray(config.redirect_uris) && config.redirect_uris.every((entry) => typeof entry === "string")
        ? (config.redirect_uris as string[]).join("\n")
        : typeof config.redirect_uris === "string"
        ? config.redirect_uris
        : "";
    base.entra = {
      issuer: typeof config.issuer === "string" ? config.issuer : "",
      clientId: typeof config.client_id === "string" ? config.client_id : "",
      clientSecret: typeof config.client_secret === "string" ? config.client_secret : "",
      tenantId: typeof config.tenant_id === "string" ? config.tenant_id : "",
      scopes,
      redirectUris,
      metadataUrl: typeof config.metadata_url === "string" ? config.metadata_url : "",
    };
  } else if (base.driver === "saml") {
    base.saml = {
      entityId: typeof config.entity_id === "string" ? config.entity_id : "",
      ssoUrl: typeof config.sso_url === "string" ? config.sso_url : "",
      certificate: typeof config.certificate === "string" ? config.certificate : "",
      metadataUrl: typeof config.metadata_url === "string" ? config.metadata_url : "",
      metadataFileName: null,
    };
  } else if (base.driver === "ldap") {
    const host = typeof config.host === "string" ? config.host : "";
    const useSsl = config.use_ssl === true;
    const port =
      typeof config.port === "number" && Number.isFinite(config.port) ? String(config.port) : "";
    const scheme = useSsl ? "ldaps://" : "ldap://";
    const defaultPort = useSsl ? "636" : "389";
    const displayPort = port !== "" && port !== defaultPort ? `:${port}` : "";
    base.ldap = {
      host: host ? `${scheme}${host}${displayPort}` : "",
      port: port && port !== defaultPort ? port : "",
      baseDn: typeof config.base_dn === "string" ? config.base_dn : "",
      bindStrategy: config.bind_strategy === "direct" ? "direct" : "service",
      bindDn: typeof config.bind_dn === "string" ? config.bind_dn : "",
      bindPassword: typeof config.bind_password === "string" ? config.bind_password : "",
      userDnTemplate: typeof config.user_dn_template === "string" ? config.user_dn_template : base.ldap.userDnTemplate,
      timeout: typeof config.timeout === "number" && Number.isFinite(config.timeout) ? String(config.timeout) : "",
      emailAttribute: typeof config.email_attribute === "string" ? config.email_attribute : base.ldap.emailAttribute,
      nameAttribute: typeof config.name_attribute === "string" ? config.name_attribute : base.ldap.nameAttribute,
      usernameAttribute:
        typeof config.username_attribute === "string" ? config.username_attribute : base.ldap.usernameAttribute,
      photoAttribute: typeof config.photo_attribute === "string" ? config.photo_attribute : "",
      useSsl,
      startTls: config.start_tls === true,
      requireTls: config.require_tls === true,
    };
  }

  return base;
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

const API_ERROR_FIELD_MAP: Record<string, string | string[]> = {
  name: "name",
  driver: "driver",
  meta: "meta",
  metadata: "saml.metadataUrl",
  url: "saml.metadataUrl",
  "config.host": "ldap.host",
  "config.port": "ldap.port",
  "config.base_dn": "ldap.baseDn",
  "config.bind_strategy": "ldap.bindStrategy",
  "config.bind_dn": "ldap.bindDn",
  "config.bind_password": "ldap.bindPassword",
  "config.user_dn_template": "ldap.userDnTemplate",
  "config.user_filter": "ldap.usernameAttribute",
  "config.timeout": "ldap.timeout",
  "config.email_attribute": "ldap.emailAttribute",
  "config.name_attribute": "ldap.nameAttribute",
  "config.username_attribute": "ldap.usernameAttribute",
  "config.photo_attribute": "ldap.photoAttribute",
  "config.use_ssl": "ldap.host",
  "config.start_tls": "ldap.startTls",
  "config.require_tls": "ldap.requireTls",
  "config.issuer": ["oidc.issuer", "entra.issuer"],
  "config.client_id": ["oidc.clientId", "entra.clientId"],
  "config.client_secret": ["oidc.clientSecret", "entra.clientSecret"],
  "config.redirect_uris": ["oidc.redirectUris", "entra.redirectUris"],
  "config.scopes": ["oidc.scopes", "entra.scopes"],
  "config.metadata_url": ["oidc.metadataUrl", "entra.metadataUrl", "saml.metadataUrl"],
  "config.tenant_id": "entra.tenantId",
  "config.entity_id": "saml.entityId",
  "config.sso_url": "saml.ssoUrl",
  "config.certificate": "saml.certificate",
};

function mapApiErrorKey(key: string): string[] {
  const mapped = API_ERROR_FIELD_MAP[key];
  if (!mapped) return [];
  return Array.isArray(mapped) ? mapped : [mapped];
}

function firstErrorMessage(candidate: unknown): string | undefined {
  if (Array.isArray(candidate)) {
    for (const entry of candidate) {
      if (typeof entry === "string") {
        const trimmed = entry.trim();
        if (trimmed !== "") {
          return trimmed;
        }
      }
    }
    return undefined;
  }

  if (typeof candidate === "string") {
    const trimmed = candidate.trim();
    return trimmed !== "" ? trimmed : undefined;
  }

  return undefined;
}

function parseHttpError(error: HttpError<unknown>, fallback: string): {
  message: string;
  fieldErrors: Record<string, string>;
  detail?: unknown;
} {
  const fieldErrors: Record<string, string> = {};
  const messageCandidates: string[] = [];
  let detail: unknown;

  const body = error.body;
  if (body && typeof body === "object" && !Array.isArray(body)) {
    const bodyRecord = body as Record<string, unknown>;
    const explicitMessage = bodyRecord.message;
    if (typeof explicitMessage === "string") {
      const trimmed = explicitMessage.trim();
      if (trimmed !== "") {
        messageCandidates.push(trimmed);
      }
    }

    const errors = bodyRecord.errors;
    if (errors && typeof errors === "object" && !Array.isArray(errors)) {
      for (const [key, raw] of Object.entries(errors as Record<string, unknown>)) {
        const text = firstErrorMessage(raw);
        if (!text) continue;

        const targets = mapApiErrorKey(key);
        if (targets.length === 0) {
          messageCandidates.push(text);
          continue;
        }

        targets.forEach((target) => {
          if (!(target in fieldErrors)) {
            fieldErrors[target] = text;
          }
        });
      }
    }

    detail = bodyRecord.details ?? bodyRecord.detail ?? bodyRecord.error ?? body;
    const detailMessage = extractDetailMessage(detail);
    if (detailMessage) {
      messageCandidates.push(detailMessage);
    }
  } else if (typeof body === "string") {
    const trimmed = body.trim();
    if (trimmed !== "") {
      messageCandidates.push(trimmed);
    }
  }

  Object.values(fieldErrors)
    .map((value) => value?.trim())
    .filter((value): value is string => Boolean(value))
    .forEach((value) => messageCandidates.push(value));

  const defaultMessage = (error.message ?? "").trim();
  if (defaultMessage !== "") {
    messageCandidates.push(defaultMessage);
  }

  let message = messageCandidates.find((candidate) => candidate && candidate.trim() !== "") ?? fallback;
  if (!message || message.trim() === "") {
    message = fallback;
  }

  return { message, fieldErrors, detail };
}

function extractDetailMessage(value: unknown): string | undefined {
  if (typeof value === "string") {
    const trimmed = value.trim();
    return trimmed === "" ? undefined : trimmed;
  }

  if (Array.isArray(value)) {
    for (const item of value) {
      const candidate = extractDetailMessage(item);
      if (candidate) {
        return candidate;
      }
    }

    return undefined;
  }

  if (value && typeof value === "object") {
    for (const entry of Object.values(value as Record<string, unknown>)) {
      const candidate = extractDetailMessage(entry);
      if (candidate) {
        return candidate;
      }
    }
  }

  return undefined;
}

function formatUnknownError(reason: unknown, fallback: string): string {
  if (reason instanceof Error) {
    const trimmed = reason.message?.trim();
    if (trimmed) {
      return trimmed;
    }
  }

  if (typeof reason === "string") {
    const trimmed = reason.trim();
    if (trimmed !== "") {
      return trimmed;
    }
  }

  return fallback;
}

function normalizeDetail(value: unknown): Record<string, unknown> | null {
  if (value === null || value === undefined) {
    return null;
  }

  if (Array.isArray(value)) {
    return { detail: value };
  }

  if (typeof value === "object") {
    return value as Record<string, unknown>;
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    return trimmed === "" ? null : { detail: trimmed };
  }

  return { detail: value };
}

export default function IdpProviders(): JSX.Element {
  const toast = useToast();
  const [loading, setLoading] = useState(true);
  const [providers, setProviders] = useState<IdpProvider[]>([]);
  const [, setMeta] = useState<IdpProviderListMeta>({ total: 0, enabled: 0 });
  const [stubMode, setStubMode] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState<PendingAction | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<IdpProvider | null>(null);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [samlSpInfo, setSamlSpInfo] = useState<SamlServiceProviderInfo | null>(null);
  const [samlSpError, setSamlSpError] = useState<string | null>(null);
  const [samlSpLoading, setSamlSpLoading] = useState(false);
  const [samlSpUrlsOpen, setSamlSpUrlsOpen] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const [formMode, setFormMode] = useState<"create" | "edit">("create");
  const [formBusy, setFormBusy] = useState(false);
  const [editingProvider, setEditingProvider] = useState<IdpProvider | null>(null);
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
  const [testDetailsOpen, setTestDetailsOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [ldapBrowserOpen, setLdapBrowserOpen] = useState(false);
  const [ldapBrowserBusy, setLdapBrowserBusy] = useState(false);
  const [ldapBrowserError, setLdapBrowserError] = useState<string | null>(null);
  const [ldapBrowserDetail, setLdapBrowserDetail] = useState<Record<string, unknown> | null>(null);
  const [ldapBrowserDiagnostics, setLdapBrowserDiagnostics] = useState<Record<string, unknown> | null>(null);
  const [pendingExpandTargets, setPendingExpandTargets] = useState<LdapExpandTarget[]>([]);
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const [ldapBrowserPath, setLdapBrowserPath] = useState<string[]>([]);
  const [ldapBrowserBaseDn, setLdapBrowserBaseDn] = useState<string | null>(null);
  const [ldapBrowserTree, setLdapBrowserTree] = useState<Record<string, LdapBrowseEntry[]>>({});
  const [ldapBrowserExpanded, setLdapBrowserExpanded] = useState<Record<string, boolean>>({});
  const [ldapBrowserDetailOpen, setLdapBrowserDetailOpen] = useState(false);
  const [ldapBrowserDiagnosticsOpen, setLdapBrowserDiagnosticsOpen] = useState(false);
  const normalizedTestStatus = typeof testResult?.status === "string" ? testResult.status : null;
  const testStatusBadgeLabel = (normalizedTestStatus ?? "error").toUpperCase();
  const testCheckedAtLabel = useMemo(() => {
    if (!testResult?.checked_at) {
      return null;
    }
    const parsed = new Date(testResult.checked_at);
    if (Number.isNaN(parsed.getTime())) {
      return null;
    }
    return parsed.toLocaleString(undefined, { hour12: false });
  }, [testResult]);
  const { resolvedTestMessage, resolvedTestDetailMessage } = useMemo((): {
    resolvedTestMessage: string | null;
    resolvedTestDetailMessage: string | null;
  } => {
    if (!testResult) {
      return { resolvedTestMessage: null, resolvedTestDetailMessage: null };
    }

    const raw = typeof testResult.message === "string" ? testResult.message.trim() : "";
    const detailMessage = extractDetailMessage(testResult.details);

    let primary = raw;
    if (primary === "" && detailMessage) {
      primary = detailMessage;
    }

    if (primary === "") {
      if (testResult.status === "ok") {
        primary = "Health check completed.";
      } else if (testResult.status === "warning") {
        primary = "Health check completed with warnings.";
      } else {
        primary = "Health check failed.";
      }
    }

    const secondary = detailMessage && detailMessage !== primary ? detailMessage : null;

    return {
      resolvedTestMessage: primary,
      resolvedTestDetailMessage: secondary,
    };
  }, [testResult]);

  const adfsErrorDetail = useMemo((): string | null => {
    if (!testResult) {
      return null;
    }

    const responseDetails = testResult.details?.response;
    if (!responseDetails || typeof responseDetails !== "object" || Array.isArray(responseDetails)) {
      return null;
    }

    const detail = (responseDetails as Record<string, unknown>).adfs_error_detail;
    if (typeof detail !== "string") {
      return null;
    }

    const trimmed = detail.trim();
    return trimmed === "" ? null : trimmed;
  }, [testResult]);

  const clearTestFeedback = useCallback(() => {
    setTestResult(null);
    setTestError(null);
    setTestDetailsOpen(false);
  }, []);

  const applyFormUpdate = useCallback(
    (updater: FormState | ((prev: FormState) => FormState), clearedFields?: string[]) => {
      setForm((prev) => (typeof updater === "function" ? (updater as (current: FormState) => FormState)(prev) : updater));
      clearTestFeedback();
      if (clearedFields && clearedFields.length > 0) {
        setFormErrors((prev) => ({
          general: prev.general,
          fields: Object.fromEntries(
            Object.entries(prev.fields).filter(([key]) => !clearedFields.includes(key))
          ),
        }));
      }
    },
    [clearTestFeedback]
  );

  const orderedProviders = useMemo(() => sortByEvaluationOrder(providers), [providers]);

  const loadProviders = useCallback(
    async (options?: { silent?: boolean }) => {
      const silent = options?.silent ?? false;
      if (!silent) {
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
        if (!silent) {
          setLoading(false);
        }
      }
    },
    []
  );

  const loadSamlSpInfo = useCallback(async () => {
    setSamlSpLoading(true);
    setSamlSpError(null);
    try {
      const res = await fetchSamlSpConfig();
      setSamlSpInfo(res.sp ?? null);
    } catch {
      setSamlSpInfo(null);
      setSamlSpError("Unable to load phpGRC service provider details.");
    } finally {
      setSamlSpLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadProviders();
  }, [loadProviders]);

  useEffect(() => {
    void loadSamlSpInfo();
  }, [loadSamlSpInfo]);

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

  const applyEvaluationOrderChange = useCallback(
    async (provider: IdpProvider, newOrder: number) => {
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
    [loadProviders, toast]
  );

  const handleReorderDrop = useCallback(
    async (sourceId: string, targetId: string) => {
      const sorted = orderedProviders;
      const sourceIndex = sorted.findIndex((item) => providerIdentifier(item) === sourceId);
      const targetIndex = sorted.findIndex((item) => providerIdentifier(item) === targetId);
      if (sourceIndex < 0 || targetIndex < 0 || sourceIndex === targetIndex) return;
      const provider = sorted[sourceIndex];
      const reference = sorted[targetIndex];
      await applyEvaluationOrderChange(provider, reference.evaluation_order);
    },
    [applyEvaluationOrderChange, orderedProviders]
  );

  const handleRowDragStart = useCallback(
    (event: DragEvent<HTMLElement>, provider: IdpProvider) => {
      const identifier = providerIdentifier(provider);
      setDraggingId(identifier);
      event.dataTransfer.effectAllowed = "move";
      event.dataTransfer.setData("text/plain", identifier);
    },
    []
  );

  const handleRowDragOver = useCallback((event: DragEvent<HTMLElement>) => {
    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
  }, []);

  const handleRowDragEnd = useCallback(() => {
    setDraggingId(null);
  }, []);

  const handleRowDrop = useCallback(
    (event: DragEvent<HTMLElement>, target: IdpProvider) => {
      event.preventDefault();
      const targetId = providerIdentifier(target);
      const sourceId = event.dataTransfer.getData("text/plain") || draggingId;
      setDraggingId(null);
      if (!sourceId || sourceId === targetId) return;
      void handleReorderDrop(sourceId, targetId);
    },
    [draggingId, handleReorderDrop]
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
    setAdvancedOpen(false);
    setFormMode("create");
    setEditingProvider(null);
    setLdapBrowserTree({});
    setLdapBrowserExpanded({});
    setLdapBrowserPath([]);
    setLdapBrowserBaseDn(null);
    setLdapBrowserError(null);
    setLdapBrowserDetail(null);
    setLdapBrowserDiagnostics(null);
    setLdapBrowserDetailOpen(false);
    setLdapBrowserDiagnosticsOpen(false);
  }, [clearTestFeedback]);

  const openCreateModal = useCallback(() => {
    resetForm();
    setFormMode("create");
    setFormOpen(true);
  }, [resetForm]);

  const openEditModal = useCallback(
    (provider: IdpProvider) => {
      resetForm();
      const nextForm = createFormStateFromProvider(provider);
      setForm(nextForm);
      setFormMode("edit");
      setEditingProvider(provider);
      setAdvancedOpen(nextForm.meta.trim() !== "");
      setFormOpen(true);
    },
    [resetForm]
  );

  const closeFormModal = useCallback(() => {
    if (formBusy) return;
    setFormOpen(false);
    setEditingProvider(null);
    setFormMode("create");
  }, [formBusy]);

  const validateForm = useCallback(
    (
      state: FormState,
      options?: { skipIdentityFields?: boolean; editing?: boolean }
    ): { valid: boolean; payload?: IdpProviderRequestPayload } => {
      const fieldErrors: Record<string, string> = {};
      const skipIdentity = options?.skipIdentityFields ?? false;
      const editing = options?.editing ?? false;

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
        if (clientSecret === "" && !editing) {
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
          };
          if (!editing || clientSecret !== "") {
            config.client_secret = clientSecret;
          }
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
        if (clientSecret === "" && !editing) {
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
          };
          if (!editing || clientSecret !== "") {
            config.client_secret = clientSecret;
          }

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
        const hostRaw = state.ldap.host.trim();
        const parsedHost = hostRaw === "" ? null : parseLdapConnectionUri(hostRaw);
        if (hostRaw === "" || !parsedHost) {
          fieldErrors["ldap.host"] =
            "Server host must start with ldap:// or ldaps:// and include a hostname.";
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
          if (bindPassword === "" && !editing) {
            fieldErrors["ldap.bindPassword"] = "Bind password is required when using a service account.";
          }
        } else if (bindStrategy === "direct") {
          if (userDnTemplate === "") {
            fieldErrors["ldap.userDnTemplate"] = "User DN template is required.";
          } else if (!userDnTemplate.includes("{{username}}")) {
            fieldErrors["ldap.userDnTemplate"] = 'Template must include the placeholder "{{username}}".';
          }
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

        const photoAttribute = state.ldap.photoAttribute.trim();

        if (Object.keys(fieldErrors).length === 0 && parsedHost) {
          const normalizedAttribute = usernameAttribute === "" ? "uid" : usernameAttribute;
          const userFilter = `(${normalizedAttribute}={{username}})`;
          config = {
            host: parsedHost.host,
            base_dn: baseDn,
            bind_strategy: bindStrategy,
            use_ssl: parsedHost.useSsl,
            start_tls: state.ldap.startTls,
            require_tls: state.ldap.requireTls,
            user_filter: userFilter,
            user_identifier_source: "username_attribute",
            email_attribute: emailAttribute,
            name_attribute: nameAttribute,
            username_attribute: usernameAttribute,
          };

          const resolvedPort = port ?? parsedHost.port;
          if (resolvedPort !== undefined) {
            config.port = resolvedPort;
          }
          if (timeout !== undefined) {
            config.timeout = timeout;
          }
          if (photoAttribute !== "") {
            config.photo_attribute = photoAttribute;
          }

          if (bindStrategy === "service") {
            config.bind_dn = bindDn;
            if (!editing || bindPassword !== "") {
              config.bind_password = bindPassword;
            }
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

  const handleFormSubmit = useCallback(async () => {
    const { valid, payload } = validateForm(form, { editing: formMode === "edit" });
    if (!valid || !payload) return;

    if (formMode === "create") {
      setFormBusy(true);
      setPending({ type: "create", id: null });
      try {
        const res = await createIdpProvider(payload);
        if (isStubResponse(res)) {
          toast.info("Creation accepted in stub-only mode; persistence disabled.");
        } else {
          toast.success(`Created provider ${res.provider.name}.`);
          setProviders((prev) => sortByEvaluationOrder([...prev, res.provider]));
        }
        setFormOpen(false);
        resetForm();
        void loadProviders({ silent: true });
      } catch (error) {
        if (error instanceof HttpError) {
          const { message, fieldErrors } = parseHttpError(
            error,
            "Failed to create provider. Check inputs and try again."
          );
          setFormErrors({ general: message, fields: fieldErrors });
        } else {
          setFormErrors({ general: "Failed to create provider. Check inputs and try again.", fields: {} });
        }
      } finally {
        setPending(null);
        setFormBusy(false);
      }
      return;
    }

    if (!editingProvider) {
      return;
    }

    const identifier = providerIdentifier(editingProvider);
    const updatePayload: IdpProviderUpdatePayload = {
      name: payload.name,
      driver: payload.driver,
      enabled: payload.enabled,
      config: payload.config,
      meta: payload.meta ?? null,
    };

    setFormBusy(true);
    setPending({ type: "update", id: identifier });
    try {
      const res = await updateIdpProvider(identifier, updatePayload);
      if (isStubResponse(res)) {
        toast.info("Update accepted in stub-only mode; persistence disabled.");
      } else if ("provider" in res) {
        toast.success(`Updated provider ${res.provider.name}.`);
        setProviders((prev) => {
          const updated = prev.map((item) => (providerIdentifier(item) === identifier ? res.provider : item));
          return sortByEvaluationOrder(updated);
        });
      }
      setFormOpen(false);
      resetForm();
      void loadProviders({ silent: true });
    } catch (error) {
      if (error instanceof HttpError) {
        const { message, fieldErrors } = parseHttpError(
          error,
          "Failed to update provider. Check inputs and try again."
        );
        setFormErrors({ general: message, fields: fieldErrors });
      } else {
        setFormErrors({ general: "Failed to update provider. Check inputs and try again.", fields: {} });
      }
    } finally {
      setPending(null);
      setFormBusy(false);
      setEditingProvider(null);
      setFormMode("create");
    }
  }, [editingProvider, form, formMode, loadProviders, resetForm, toast, validateForm]);

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

  useEffect(() => {
    if (!advancedOpen && (form.meta.trim() !== "" || Boolean(formErrors.fields.meta))) {
      setAdvancedOpen(true);
    }
  }, [advancedOpen, form.meta, formErrors.fields.meta]);

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
      } catch (error) {
        const fallback = "Failed to fetch metadata. Enter the details manually.";
        toast.danger(formatUnknownError(error, fallback));
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
        const response = await previewSamlMetadata({ metadata: metadataXml });
        if (!response || typeof response !== "object" || !response.config) {
          throw new Error("Invalid metadata preview response.");
        }

        applySamlMetadata(response.config, source);
        toast.success("Metadata parsed. Review the pre-filled fields.");
        return true;
      } catch (error) {
        const fallback = "Failed to parse metadata. Ensure the document is valid XML.";
        if (error instanceof HttpError) {
          const { message } = parseHttpError(error, fallback);
          toast.danger(message);
        } else {
          toast.danger(formatUnknownError(error, fallback));
        }
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
      const response = await previewSamlMetadataFromUrl(trimmedUrl);
      if (!response || typeof response !== "object" || !response.config) {
        throw new Error("Invalid metadata preview response.");
      }

      applySamlMetadata(response.config, { url: trimmedUrl });
      updateFieldError("saml.metadataUrl");
      toast.success("Metadata parsed. Review the pre-filled fields.");
    } catch (error) {
      const fallback = "Failed to download metadata. Verify the URL and CORS settings.";
      if (error instanceof HttpError) {
        const { message, fieldErrors } = parseHttpError(error, fallback);
        Object.entries(fieldErrors).forEach(([field, msg]) => updateFieldError(field, msg));
        toast.danger(message);
      } else {
        toast.danger(formatUnknownError(error, fallback));
      }
    } finally {
      setMetadataLoading((prev) => ({ ...prev, saml: false }));
    }
  }, [applySamlMetadata, form.saml.metadataUrl, toast, updateFieldError]);

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
      setTestDetailsOpen(false);
    } catch (error) {
      if (error instanceof HttpError) {
        const fallback = "Failed to run health check. Please try again.";
        const { message, fieldErrors, detail } = parseHttpError(
          error,
          fallback
        );
        Object.entries(fieldErrors).forEach(([field, msg]) => updateFieldError(field, msg));

        const body = error.body;
        let details: Record<string, unknown>;
        if (body && typeof body === "object" && !Array.isArray(body)) {
          details = body as Record<string, unknown>;
        } else if (detail && typeof detail === "object" && !Array.isArray(detail)) {
          details = detail as Record<string, unknown>;
        } else {
          details = { raw: detail ?? body };
        }

        setTestResult({
          ok: false,
          status: "error",
          message,
          checked_at: new Date().toISOString(),
          details,
        });
        setTestError(null);
        setTestDetailsOpen(false);
      } else {
        const fallback = "Failed to run health check. Please try again.";
        const message = formatUnknownError(error, fallback);
        setTestResult({
          ok: false,
          status: "error",
          message,
          checked_at: new Date().toISOString(),
          details: { error: message },
        });
        setTestError(null);
        setTestDetailsOpen(false);
      }
    } finally {
      setTestBusy(false);
    }
  }, [form, updateFieldError, validateForm]);

  const applyLdapPreset = useCallback(
    (preset: "ad" | "generic") => {
      const defaultHost = preset === "ad" ? "ldap://ad.example.com" : "ldap://ldap.example.com";
      const parsedDefaultHost = parseLdapConnectionUri(defaultHost);
      const defaultUseSsl = parsedDefaultHost?.useSsl ?? false;
      const defaultPort = parsedDefaultHost?.port
        ? String(parsedDefaultHost.port)
        : defaultUseSsl
        ? "636"
        : "389";

      applyFormUpdate((prev) => ({
        ...prev,
        ldap: {
          ...prev.ldap,
          port: prev.ldap.port || defaultPort,
          bindStrategy: "service",
          emailAttribute: "mail",
          nameAttribute: preset === "ad" ? "displayName" : "cn",
          usernameAttribute: preset === "ad" ? "sAMAccountName" : "uid",
          photoAttribute: preset === "ad" ? "thumbnailPhoto" : prev.ldap.photoAttribute,
          useSsl: defaultUseSsl,
          startTls: false,
          requireTls: prev.ldap.requireTls,
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
        setLdapBrowserTree({});
        setLdapBrowserExpanded({});
        setLdapBrowserDetail({
          issue: "invalid_configuration",
          requestedBaseDn: targetBaseDn,
          path: nextPath,
        });
        setLdapBrowserDiagnostics(null);
        return;
      }

      setLdapBrowserBusy(true);
      setLdapBrowserError(null);
      setLdapBrowserDetail(null);
      setLdapBrowserDiagnostics(null);

      try {
        const response = await browseLdapDirectory({
          driver: "ldap",
          config: payload.config,
          base_dn: targetBaseDn ?? null,
        });

        let entries = Array.isArray(response.entries) ? response.entries : [];
        const resolvedBaseDn = response.base_dn ?? targetBaseDn ?? null;
        const diagnostics =
          response.diagnostics && typeof response.diagnostics === "object" ? response.diagnostics : null;
        const trimmedTarget = targetBaseDn?.trim() ?? "";
        const treeKey = trimmedTarget !== "" ? trimmedTarget : LDAP_ROOT_KEY;
        const normalizedTreeKey = treeKey === LDAP_ROOT_KEY ? LDAP_ROOT_KEY : normalizeDn(treeKey);
        if (treeKey === LDAP_ROOT_KEY) {
          entries = entries.filter((entry) => {
            if (!entry?.dn || typeof entry.dn !== "string") return false;
            const upperDn = entry.dn.toUpperCase();
            const components = upperDn.split(",").map((part) => part.trim()).filter(Boolean);
            const domainComponents = components.filter((component) => component.startsWith("DC="));
            const ouComponents = components.filter((component) => component.startsWith("OU="));
            if (ouComponents.length > 0) {
              return true;
            }
            return domainComponents.length <= 2;
          });
        }
        const summary: Record<string, unknown> = {
          requestedBaseDn: response.requested_base_dn ?? targetBaseDn,
          effectiveBaseDn: resolvedBaseDn,
          entryCount: entries.length,
          path: nextPath,
          rootResponse: response.root === true,
          checkedAt: new Date().toISOString(),
          ...(diagnostics ? { diagnostics } : {}),
        };

        setLdapBrowserTree((prev) => {
          const next = { ...prev };
          next[normalizedTreeKey] = entries;
          if (treeKey !== normalizedTreeKey) {
            next[treeKey] = entries;
          }
          return next;
        });
        setLdapBrowserBaseDn(resolvedBaseDn);
        setLdapBrowserPath(nextPath);
        setLdapBrowserDetail(summary);
        setLdapBrowserDiagnostics(diagnostics ?? null);
        setLdapBrowserExpanded((prev) => {
          const next = { ...prev };
          next[normalizedTreeKey] = true;
          nextPath.forEach((dn) => {
            if (!dn) return;
            next[normalizeDn(dn)] = true;
          });
          if (!next[LDAP_ROOT_KEY]) {
            next[LDAP_ROOT_KEY] = true;
          }
          return next;
        });
        setLdapBrowserDetailOpen(false);
        setLdapBrowserDiagnosticsOpen(false);

        if (entries.length === 0) {
          if (response.root) {
            setLdapBrowserError(
              "The server did not return any naming contexts. Provide the Base DN manually or verify browse permissions."
            );
          } else {
            const targetLabel = resolvedBaseDn ? `"${resolvedBaseDn}"` : "the directory root";
            setLdapBrowserError(`No entries were returned for ${targetLabel}. Confirm the Base DN and account permissions.`);
          }
        }
      } catch (error) {
        const fallback = "Unable to browse the directory. Check connection details and try again.";
        if (error instanceof HttpError) {
          const { message, fieldErrors, detail } = parseHttpError(error, fallback);
          Object.entries(fieldErrors).forEach(([field, msg]) => updateFieldError(field, msg));
          setLdapBrowserError(message);
          const normalizedDetail =
            normalizeDetail(detail) ??
            (error.body && typeof error.body === "object" && !Array.isArray(error.body)
              ? (error.body as Record<string, unknown>)
              : { detail: message });
          setLdapBrowserDetail({
            ...normalizedDetail,
            checkedAt: new Date().toISOString(),
            requestedBaseDn: targetBaseDn,
            path: nextPath,
          });
          setLdapBrowserDiagnostics(normalizedDetail ?? null);
        } else {
          const message = formatUnknownError(error, fallback);
          setLdapBrowserError(message);
          setLdapBrowserDetail({
            detail: message,
            requestedBaseDn: targetBaseDn,
            path: nextPath,
            checkedAt: new Date().toISOString(),
          });
          setLdapBrowserDiagnostics({ detail: message });
        }
      } finally {
        setLdapBrowserBusy(false);
      }
    },
    [form, updateFieldError, validateForm]
  );

  const openLdapBrowser = useCallback(() => {
    const configuredBase = form.ldap.baseDn.trim();
    const initialBaseDn = configuredBase !== "" ? configuredBase : null;

    setLdapBrowserError(null);
    setLdapBrowserTree({});
    const expansionTargets = initialBaseDn ? buildLdapExpansionTargets(initialBaseDn) : [];
    const seededExpanded: Record<string, boolean> = {};
    expansionTargets.forEach((target) => {
      target.path.forEach((dn) => {
        if (!dn) return;
        seededExpanded[normalizeDn(dn)] = true;
      });
    });
    if (Object.keys(seededExpanded).length > 0) {
      seededExpanded[LDAP_ROOT_KEY] = true;
    }
    setLdapBrowserExpanded(seededExpanded);
    setLdapBrowserPath([]);
    setLdapBrowserBaseDn(initialBaseDn);
    setLdapBrowserDetail(null);
    setLdapBrowserDiagnostics(null);
    setLdapBrowserDetailOpen(false);
    setLdapBrowserDiagnosticsOpen(false);
    setPendingExpandTargets(expansionTargets);
    setLdapBrowserOpen(true);
    void loadLdapDirectory(null, []);
  }, [form.ldap.baseDn, loadLdapDirectory]);

  const closeLdapBrowser = useCallback(() => {
    if (ldapBrowserBusy) {
      return;
    }

    setLdapBrowserOpen(false);
    setLdapBrowserError(null);
    setLdapBrowserDetail(null);
    setLdapBrowserDiagnostics(null);
    setPendingExpandTargets([]);
  }, [ldapBrowserBusy]);

  useEffect(() => {
    if (!ldapBrowserOpen) return;
    if (pendingExpandTargets.length === 0) return;
    if (ldapBrowserBusy) return;

    const [next, ...rest] = pendingExpandTargets;
    setPendingExpandTargets(rest);
    void loadLdapDirectory(next.dn, next.path);
  }, [ldapBrowserBusy, ldapBrowserOpen, loadLdapDirectory, pendingExpandTargets]);

  const handleLdapNavigateTo = useCallback(
    (dn: string | null, index: number) => {
      const nextPath = dn === null ? [] : ldapBrowserPath.slice(0, index + 1);
      void loadLdapDirectory(dn, nextPath);
    },
    [ldapBrowserPath, loadLdapDirectory]
  );

  const handleLdapToggleNode = useCallback(
    (entry: LdapBrowseEntry, parentPath: string[]) => {
      if (ldapBrowserBusy) {
        return;
      }

      const key = normalizeDn(entry.dn);
      if (ldapBrowserExpanded[key]) {
        setLdapBrowserExpanded((prev) => {
          const next = { ...prev };
          next[key] = false;
          return next;
        });
        return;
      }

      const nextPath = [...parentPath, entry.dn];
      void loadLdapDirectory(entry.dn, nextPath);
    },
    [ldapBrowserBusy, ldapBrowserExpanded, loadLdapDirectory]
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
      setLdapBrowserBaseDn(dn);
    },
    [applyFormUpdate]
  );

  const handleLdapHostChange = useCallback(
    (event: ChangeEvent<HTMLInputElement>) => {
      const value = event.target.value;
      const parsed = parseLdapConnectionUri(value);

      applyFormUpdate((prev) => {
        if (!parsed) {
          return {
            ...prev,
            ldap: {
              ...prev.ldap,
              host: value,
            },
          };
        }

        let nextPort = prev.ldap.port;
        if (parsed.port !== undefined) {
          nextPort = String(parsed.port);
        } else if (prev.ldap.port === "" || prev.ldap.port === "389" || prev.ldap.port === "636") {
          nextPort = parsed.useSsl ? "636" : "389";
        }

        return {
          ...prev,
          ldap: {
            ...prev.ldap,
            host: value,
            port: nextPort,
            useSsl: parsed.useSsl,
          },
        };
      });
    },
    [applyFormUpdate]
  );

  const resolveLeafIcon = (type: string): string => {
    const normalized = typeof type === "string" ? type.toLowerCase() : "";
    if (normalized === "person") {
      return "person";
    }
    if (normalized === "group") {
      return "people";
    }
    if (normalized === "computer") {
      return "laptop";
    }
    return "file-earmark";
  };

  const getRootEntries = (): LdapBrowseEntry[] => {
    const root = ldapBrowserTree[LDAP_ROOT_KEY];
    if (Array.isArray(root) && root.length > 0) {
      return root;
    }
    if (ldapBrowserBaseDn) {
      const normalizedBase = normalizeDn(ldapBrowserBaseDn);
      const baseEntries = ldapBrowserTree[normalizedBase] ?? ldapBrowserTree[ldapBrowserBaseDn];
      if (Array.isArray(baseEntries) && baseEntries.length > 0) {
        return baseEntries;
      }
    }
    return [];
  };

  const renderLdapTree = (entries: LdapBrowseEntry[], parentPath: string[] = []): JSX.Element | null => {
    if (!entries || entries.length === 0) {
      return null;
    }

    const selectedBaseDn = form.ldap.baseDn.trim();

    return (
      <ul className="list-unstyled mb-0 ldap-tree">
        {entries.map((entry) => {
          const hasChildren = entry.has_children;
          const normalizedEntryKey = normalizeDn(entry.dn);
          const hasExplicitState = Object.prototype.hasOwnProperty.call(ldapBrowserExpanded, normalizedEntryKey);
          const expanded = hasChildren
            ? hasExplicitState
              ? Boolean(ldapBrowserExpanded[normalizedEntryKey])
              : parentPath.length === 0
            : false;
          const childEntries = hasChildren
            ? ldapBrowserTree[normalizedEntryKey] ?? ldapBrowserTree[entry.dn] ?? []
            : [];
          const selected = selectedBaseDn !== "" && selectedBaseDn === entry.dn;
          const leafIcon = resolveLeafIcon(entry.type);

          return (
            <li key={entry.dn} className="ldap-tree__item">
              <div className={`ldap-tree__row${selected ? " is-selected" : ""}`}>
                {hasChildren ? (
                  <button
                    type="button"
                    className="btn btn-link btn-sm p-0 d-flex align-items-center ldap-tree__toggle"
                    onClick={(event) => {
                      event.stopPropagation();
                      handleLdapToggleNode(entry, parentPath);
                    }}
                    aria-expanded={expanded}
                    aria-label={`${expanded ? "Collapse" : "Expand"} ${entry.name}`}
                  >
                    <i className={`bi bi-${expanded ? "folder-minus" : "folder-plus"}`} aria-hidden="true" />
                  </button>
                ) : (
                  <span className="ldap-tree__icon">
                    <i className={`bi bi-${leafIcon}`} aria-hidden="true" />
                  </span>
                )}
                <button
                  type="button"
                  className="btn btn-link btn-sm text-start flex-grow-1 p-0 ldap-tree__label"
                  onClick={() => handleLdapUseBaseDn(entry.dn)}
                  disabled={ldapBrowserBusy}
                >
                  <span className="d-inline-flex align-items-center gap-2 position-relative">
                    {selected ? <i className="bi bi-forward-fill text-success" aria-hidden="true" /> : null}
                    <span className="text-truncate">{entry.name}</span>
                  </span>
                </button>
              </div>
              {hasChildren && expanded ? (
                <div className="ldap-tree__children">
                  {renderLdapTree(childEntries, [...parentPath, entry.dn])}
                </div>
              ) : null}
            </li>
          );
        })}
      </ul>
    );
  };

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
            <HoverHelpLabel
              htmlFor={`${baseId}-issuer`}
              className="form-label"
              helpText="Use the issuer value reported by the discovery document."
              helpId={`${baseId}-issuer-help`}
            >
              Issuer URL
            </HoverHelpLabel>
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
                , driver === "oidc" ? ['oidc.issuer'] : ['entra.issuer'])
              }
              disabled={formBusy}
              placeholder={
                driver === "entra"
                  ? "https://login.microsoftonline.com/<tenant>/v2.0"
                  : "https://example.okta.com/oauth2/default"
              }
              inputMode="url"
              autoComplete="off"
              aria-describedby={`${baseId}-issuer-help`}
            />
            {fieldError(`${driver}.issuer`) ? (
              <div className="invalid-feedback">{fieldError(`${driver}.issuer`)}</div>
            ) : null}
          </div>

          {driver === "entra" ? (
            <div className="col-12 col-md-6">
              <HoverHelpLabel
                htmlFor="entra-tenant-id"
                className="form-label"
                helpText="Azure tenant identifier (GUID or domain)."
                helpId="entra-tenant-id-help"
              >
                Tenant ID
              </HoverHelpLabel>
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
                disabled={formBusy}
                placeholder="11111111-2222-3333-4444-555555555555"
                autoComplete="off"
                aria-describedby="entra-tenant-id-help"
              />
              {fieldError("entra.tenantId") ? (
                <div className="invalid-feedback">{fieldError("entra.tenantId")}</div>
              ) : null}
            </div>
          ) : null}

          <div className="col-12 col-md-6">
            <HoverHelpLabel
              htmlFor={`${baseId}-client-id`}
              className="form-label"
              helpText="Application identifier issued by the provider."
              helpId={`${baseId}-client-id-help`}
            >
              Client ID
            </HoverHelpLabel>
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
                , driver === "oidc" ? ['oidc.clientId'] : ['entra.clientId'])
              }
              disabled={formBusy}
              placeholder="0oa1example123"
              autoComplete="off"
              aria-describedby={`${baseId}-client-id-help`}
            />
            {fieldError(`${driver}.clientId`) ? (
              <div className="invalid-feedback">{fieldError(`${driver}.clientId`)}</div>
            ) : null}
          </div>

          <div className="col-12 col-md-6">
            <HoverHelpLabel
              htmlFor={`${baseId}-client-secret`}
              className="form-label"
              helpText="Copy the client secret exactly as issued."
              helpId={`${baseId}-client-secret-help`}
            >
              Client secret
            </HoverHelpLabel>
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
                , driver === "oidc" ? ['oidc.clientSecret'] : ['entra.clientSecret'])
              }
              disabled={formBusy}
              placeholder="••••••••••••"
              autoComplete="off"
              aria-describedby={`${baseId}-client-secret-help`}
            />
            {fieldError(`${driver}.clientSecret`) ? (
              <div className="invalid-feedback">{fieldError(`${driver}.clientSecret`)}</div>
            ) : null}
          </div>

          <div className="col-12 col-md-6">
            <HoverHelpLabel
              htmlFor={`${baseId}-scopes`}
              className="form-label"
              helpText="Space separated list. Leave blank to use the defaults."
              helpId={`${baseId}-scopes-help`}
            >
              Scopes
            </HoverHelpLabel>
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
                , driver === "oidc" ? ['oidc.scopes'] : ['entra.scopes'])
              }
              disabled={formBusy}
              placeholder="openid profile email"
              autoComplete="off"
              aria-describedby={`${baseId}-scopes-help`}
            />
          </div>

          <div className="col-12">
            <HoverHelpLabel
              htmlFor={`${baseId}-redirect-uris`}
              className="form-label"
              helpText="One URL per line or separate with commas."
              helpId={`${baseId}-redirect-uris-help`}
            >
              Redirect URIs
            </HoverHelpLabel>
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
                , driver === "oidc" ? ['oidc.redirectUris'] : ['entra.redirectUris'])
              }
              disabled={formBusy}
              placeholder="https://app.example.com/auth/callback"
              aria-describedby={`${baseId}-redirect-uris-help`}
            />
            {fieldError(`${driver}.redirectUris`) ? (
              <div className="invalid-feedback">{fieldError(`${driver}.redirectUris`)}</div>
            ) : null}
          </div>

          <div className="col-12">
            <HoverHelpLabel
              htmlFor={`${baseId}-metadata-url`}
              className="form-label"
              helpText="Pull the discovery document directly (if CORS allows) to pre-fill issuer details."
              helpId={`${baseId}-metadata-url-help`}
            >
              Metadata URL
            </HoverHelpLabel>
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
                  , driver === "oidc" ? ['oidc.metadataUrl'] : ['entra.metadataUrl'])
                }
                placeholder={metadataPlaceholder}
                disabled={formBusy || loading}
                inputMode="url"
                autoComplete="off"
                aria-describedby={`${baseId}-metadata-url-help`}
              />
              <button
                type="button"
                className="btn btn-outline-secondary"
                onClick={() => void handleOidcMetadataFetch(driver)}
                disabled={formBusy || loading}
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
          </div>

          <div className="col-12">
            <HoverHelpLabel
              htmlFor={`${baseId}-metadata-file`}
              className="form-label"
              helpText="Upload a saved discovery document if downloading from the provider is blocked."
              helpId={`${baseId}-metadata-file-help`}
            >
              Metadata file
            </HoverHelpLabel>
            <input
              id={`${baseId}-metadata-file`}
              type="file"
              className="form-control"
              accept=".json,application/json,.txt"
              onChange={(event) => handleOidcMetadataFileChange(driver, event)}
              disabled={formBusy || loading}
              aria-describedby={`${baseId}-metadata-file-help`}
            />
          </div>
        </div>
      </div>
    );
  };

  const renderSamlSpSummary = (): JSX.Element => {
    const fallback = samlSpLoading ? "Loading..." : "Unavailable";
    const spEntityId = samlSpInfo?.entity_id ?? fallback;
    const spAcsUrl = samlSpInfo?.acs_url ?? fallback;
    const spMetadataUrl = typeof samlSpInfo?.metadata_url === "string" ? samlSpInfo.metadata_url : null;
    const signRequestsState =
      samlSpInfo && typeof samlSpInfo.sign_authn_requests === "boolean"
        ? samlSpInfo.sign_authn_requests
        : null;
    const wantSignedState =
      samlSpInfo && typeof samlSpInfo.want_assertions_signed === "boolean"
        ? samlSpInfo.want_assertions_signed
        : null;
    const signingLabel = signRequestsState === null ? fallback : signRequestsState ? "Enabled" : "Disabled";
    const responsesLabel = wantSignedState === null ? fallback : wantSignedState ? "Required" : "Optional";
    const headingId = "saml-sp-urls-heading";
    const collapseId = "saml-sp-urls-collapse";

    return (
      <div className="my-3">
        {samlSpError ? (
          <div className="alert alert-warning mb-3" role="alert">
            Unable to load service provider details. {samlSpError}
          </div>
        ) : null}

        <div className="accordion" id="saml-sp-urls">
          <div className="accordion-item">
            <h2 className="accordion-header" id={headingId}>
              <button
                className={`accordion-button${samlSpUrlsOpen ? "" : " collapsed"}`}
                type="button"
                aria-expanded={samlSpUrlsOpen}
                aria-controls={collapseId}
                onClick={() => setSamlSpUrlsOpen((open) => !open)}
              >
                Service Provider URLs
              </button>
            </h2>
            <div
              id={collapseId}
              className={`accordion-collapse collapse${samlSpUrlsOpen ? " show" : ""}`}
              aria-labelledby={headingId}
            >
              <div className="accordion-body">
                <div className="row g-3 mb-0 small">
                  <div className="col-12 col-md-6">
                    <p className="text-muted mb-1">Entity ID</p>
                    <p className="font-monospace text-break mb-0">{spEntityId}</p>
                  </div>
                  <div className="col-12 col-md-6">
                    <p className="text-muted mb-1">ACS URL</p>
                    <p className="font-monospace text-break mb-0">{spAcsUrl}</p>
                  </div>
                  <div className="col-12 col-md-6">
                    <p className="text-muted mb-1">Metadata URL</p>
                    <p className="font-monospace text-break mb-0">
                      {spMetadataUrl ? (
                        <a
                          href={spMetadataUrl}
                          target="_blank"
                          rel="noreferrer noopener"
                          className="link-offset-1 text-decoration-none"
                        >
                          {spMetadataUrl}
                        </a>
                      ) : (
                        fallback
                      )}
                    </p>
                  </div>
                </div>
                <hr className="my-3" />
                <div className="row g-3 mb-0 small">
                  <div className="col-12 col-md-6">
                    <p className="text-muted mb-1">Signs AuthnRequests</p>
                    <p className="mb-0">{signingLabel}</p>
                  </div>
                  <div className="col-12 col-md-6">
                    <p className="text-muted mb-1">Requires signed responses</p>
                    <p className="mb-0">{responsesLabel}</p>
                  </div>
                </div>
              </div>
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
        <div className="row g-3">
          <div className="col-12">
            <HoverHelpLabel
              htmlFor="saml-entity-id"
              className="form-label"
              helpText="The IdP entity identifier (sometimes called audience or issuer)."
              helpId="saml-entity-id-help"
            >
              Entity ID
            </HoverHelpLabel>
            <input
              id="saml-entity-id"
              type="text"
              className={`form-control${fieldError("saml.entityId") ? " is-invalid" : ""}`}
              value={form.saml.entityId}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, saml: { ...prev.saml, entityId: event.target.value } }), ['saml.entityId'])
              }
              disabled={formBusy}
              placeholder="urn:example:idp"
              autoComplete="off"
              aria-describedby="saml-entity-id-help"
            />
            {fieldError("saml.entityId") ? (
              <div className="invalid-feedback">{fieldError("saml.entityId")}</div>
            ) : null}
          </div>

          <div className="col-12">
            <HoverHelpLabel
              htmlFor="saml-sso-url"
              className="form-label"
              helpText="HTTP-Redirect endpoint for authentication requests."
              helpId="saml-sso-url-help"
            >
              SSO URL
            </HoverHelpLabel>
            <input
              id="saml-sso-url"
              type="url"
              className={`form-control${fieldError("saml.ssoUrl") ? " is-invalid" : ""}`}
              value={form.saml.ssoUrl}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, saml: { ...prev.saml, ssoUrl: event.target.value } }), ['saml.ssoUrl'])
              }
              disabled={formBusy}
              placeholder="https://idp.example.com/saml2"
              inputMode="url"
              autoComplete="off"
              aria-describedby="saml-sso-url-help"
            />
            {fieldError("saml.ssoUrl") ? (
              <div className="invalid-feedback">{fieldError("saml.ssoUrl")}</div>
            ) : null}
          </div>

          <div className="col-12">
            <HoverHelpLabel
              htmlFor="saml-certificate"
              className="form-label"
              helpText="Paste the full PEM encoded signing certificate from your IdP."
              helpId="saml-certificate-help"
            >
              Signing certificate
            </HoverHelpLabel>
            <textarea
              id="saml-certificate"
              className={`form-control font-monospace${fieldError("saml.certificate") ? " is-invalid" : ""}`}
              rows={5}
              value={form.saml.certificate}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, saml: { ...prev.saml, certificate: event.target.value } }), ['saml.certificate'])
              }
              disabled={formBusy}
              placeholder="-----BEGIN CERTIFICATE-----"
              aria-describedby="saml-certificate-help"
            />
            {fieldError("saml.certificate") ? (
              <div className="invalid-feedback">{fieldError("saml.certificate")}</div>
            ) : null}
          </div>

          <div className="col-12">
            <HoverHelpLabel
              htmlFor="saml-metadata-url"
              className="form-label"
              helpText="Provide the federation metadata URL to import values automatically (subject to CORS)."
              helpId="saml-metadata-url-help"
            >
              Metadata URL
            </HoverHelpLabel>
            <div className="input-group">
              <input
                id="saml-metadata-url"
                type="url"
                className={`form-control${fieldError("saml.metadataUrl") ? " is-invalid" : ""}`}
                value={form.saml.metadataUrl}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({ ...prev, saml: { ...prev.saml, metadataUrl: event.target.value } }), ['saml.metadataUrl'])
                }
                placeholder="https://idp.example.com/federationmetadata.xml"
                disabled={formBusy || loading}
                inputMode="url"
                autoComplete="off"
                aria-describedby="saml-metadata-url-help"
              />
              <button
                type="button"
                className="btn btn-outline-secondary"
                onClick={() => void handleSamlMetadataFetch()}
                disabled={formBusy || loading}
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
          </div>

          <div className="col-12">
            <HoverHelpLabel
              htmlFor="saml-metadata-file"
              className="form-label"
              helpText={`Upload an XML metadata file to parse entity ID, SSO URL, and certificate automatically${
                form.saml.metadataFileName ? `. Last uploaded: ${form.saml.metadataFileName}` : "."
              }`}
              helpId="saml-metadata-file-help"
            >
              Metadata file
            </HoverHelpLabel>
            <input
              id="saml-metadata-file"
              type="file"
              className="form-control"
              accept=".xml,application/xml,text/xml"
              onChange={handleSamlMetadataFileChange}
              disabled={formBusy || loading}
              aria-describedby="saml-metadata-file-help"
            />
          </div>
        </div>
      </div>
    );
  };


  const renderLdapFields = (): JSX.Element => {
    const bindStrategy = form.ldap.bindStrategy;
    return (
      <div>
        <h2 className="h6 mb-3">LDAP configuration</h2>
        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
          <div className="btn-group" role="group" aria-label="LDAP presets">
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={() => applyLdapPreset("ad")}
              disabled={formBusy}
            >
              Active Directory
            </button>
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={() => applyLdapPreset("generic")}
              disabled={formBusy}
            >
              LDAP
            </button>
          </div>
        </div>
        <div className="row g-3">
          <div className="col-12 col-md-6">
            <HoverHelpLabel
              htmlFor="ldap-host"
              className="form-label"
              helpText="Use ldaps:// to enable LDAPS"
              helpId="ldap-host-help"
            >
              Server
            </HoverHelpLabel>
            <input
              id="ldap-host"
              type="text"
              className={`form-control${fieldError("ldap.host") ? " is-invalid" : ""}`}
              value={form.ldap.host}
              onChange={handleLdapHostChange}
              disabled={formBusy}
              placeholder="ldaps://ldap.example.com"
              autoComplete="off"
              aria-describedby="ldap-host-help"
            />
            {fieldError("ldap.host") ? <div className="invalid-feedback">{fieldError("ldap.host")}</div> : null}
          </div>

          <div className="col-6 col-md-3">
            <HoverHelpLabel
              htmlFor="ldap-port"
              className="form-label"
              helpText="default 389 on ldap:// - 636 on ldaps://"
              helpId="ldap-port-help"
            >
              Port
            </HoverHelpLabel>
            <input
              id="ldap-port"
              type="number"
              min={1}
              max={65535}
              className={`form-control${fieldError("ldap.port") ? " is-invalid" : ""}`}
              value={form.ldap.port}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, port: event.target.value } }), ['ldap.port'])
              }
              disabled={formBusy}
              placeholder="389"
              aria-describedby="ldap-port-help"
            />
            {fieldError("ldap.port") ? <div className="invalid-feedback">{fieldError("ldap.port")}</div> : null}
          </div>

          <div className="col-6 col-md-3">
            <HoverHelpLabel
              htmlFor="ldap-timeout"
              className="form-label"
              helpText="Connection timeout, in seconds"
              helpId="ldap-timeout-help"
            >
              Timeout
            </HoverHelpLabel>
            <input
              id="ldap-timeout"
              type="number"
              min={1}
              max={120}
              className={`form-control${fieldError("ldap.timeout") ? " is-invalid" : ""}`}
              value={form.ldap.timeout}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, timeout: event.target.value } }), ['ldap.timeout'])
              }
              disabled={formBusy}
              placeholder="15"
              aria-describedby="ldap-timeout-help"
            />
            {fieldError("ldap.timeout") ? <div className="invalid-feedback">{fieldError("ldap.timeout")}</div> : null}
          </div>

          <div className="col-12">
            <HoverHelpLabel
              htmlFor="ldap-base-dn"
              className="form-label"
              helpText="Search base used for users and lookups."
              helpId="ldap-base-dn-help"
            >
              Base DN
            </HoverHelpLabel>
            <input
              id="ldap-base-dn"
              type="text"
              className={`form-control${fieldError("ldap.baseDn") ? " is-invalid" : ""}`}
              value={form.ldap.baseDn}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, baseDn: event.target.value } }), ['ldap.baseDn'])
              }
              disabled={formBusy}
              placeholder="dc=example,dc=com"
              autoComplete="off"
              aria-describedby="ldap-base-dn-help"
            />
            {fieldError("ldap.baseDn") ? <div className="invalid-feedback">{fieldError("ldap.baseDn")}</div> : null}
          </div>

          <div className="col-12 col-md-6 col-xl-4">
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
                disabled={formBusy}
                aria-describedby="ldap-start-tls-help"
              />
              <HoverHelpLabel
                className="form-check-label"
                htmlFor="ldap-start-tls"
                helpText="Start TLS after connecting on the standard port."
                helpId="ldap-start-tls-help"
              >
                StartTLS
              </HoverHelpLabel>
            </div>
          </div>

          <div className="col-12 col-md-6 col-xl-4">
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
                disabled={formBusy}
                aria-describedby="ldap-require-tls-help"
              />
              <HoverHelpLabel
                className="form-check-label"
                htmlFor="ldap-require-tls"
                helpText="Prevent binds unless SSL or StartTLS is enabled."
                helpId="ldap-require-tls-help"
              >
                Require TLS
              </HoverHelpLabel>
            </div>
          </div>

          <div className="col-12 col-md-6">
            <HoverHelpLabel
              htmlFor="ldap-bind-strategy"
              className="form-label"
              helpText="Choose how user credentials are verified."
              helpId="ldap-bind-strategy-help"
            >
              Bind strategy
            </HoverHelpLabel>
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
              disabled={formBusy}
              aria-describedby="ldap-bind-strategy-help"
            >
              <option value="service">Service Account</option>
              <option value="direct">Direct user DN bind</option>
            </select>
            {fieldError("ldap.bindStrategy") ? (
              <div className="invalid-feedback">{fieldError("ldap.bindStrategy")}</div>
            ) : null}
          </div>

          {bindStrategy === "service" ? (
            <>
              <div className="col-12 col-md-6">
                <HoverHelpLabel
                  htmlFor="ldap-bind-dn"
                  className="form-label"
                  helpText="Service account used to search for user entries."
                  helpId="ldap-bind-dn-help"
                >
                  Bind DN
                </HoverHelpLabel>
                <input
                  id="ldap-bind-dn"
                  type="text"
                  className={`form-control${fieldError("ldap.bindDn") ? " is-invalid" : ""}`}
                  value={form.ldap.bindDn}
                  onChange={(event) =>
                    applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, bindDn: event.target.value } }), ['ldap.bindDn'])
                  }
                  disabled={formBusy}
                  placeholder="cn=service,ou=accounts,dc=example,dc=com"
                  autoComplete="off"
                  aria-describedby="ldap-bind-dn-help"
                />
                {fieldError("ldap.bindDn") ? (
                  <div className="invalid-feedback">{fieldError("ldap.bindDn")}</div>
                ) : null}
              </div>

              <div className="col-12 col-md-6">
                <HoverHelpLabel
                  htmlFor="ldap-bind-password"
                  className="form-label"
                  helpText="Credentials for the service account above."
                  helpId="ldap-bind-password-help"
                >
                  Bind password
                </HoverHelpLabel>
                <input
                  id="ldap-bind-password"
                  type="password"
                  className={`form-control${fieldError("ldap.bindPassword") ? " is-invalid" : ""}`}
                  value={form.ldap.bindPassword}
                  onChange={(event) =>
                    applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, bindPassword: event.target.value } }), ['ldap.bindPassword'])
                  }
                  disabled={formBusy}
                  placeholder="••••••••"
                  autoComplete="off"
                  aria-describedby="ldap-bind-password-help"
                />
                {fieldError("ldap.bindPassword") ? (
                  <div className="invalid-feedback">{fieldError("ldap.bindPassword")}</div>
                ) : null}
              </div>
            </>
          ) : (
            <div className="col-12">
              <HoverHelpLabel
                htmlFor="ldap-user-dn-template"
                className="form-label"
                helpText='Use a standard DN pattern. Replace the username with the placeholder {{username}}.'
                helpId="ldap-user-dn-template-help"
              >
                User DN template
              </HoverHelpLabel>
              <input
                id="ldap-user-dn-template"
                type="text"
                className={`form-control${fieldError("ldap.userDnTemplate") ? " is-invalid" : ""}`}
                value={form.ldap.userDnTemplate}
                onChange={(event) =>
                  applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, userDnTemplate: event.target.value } }), ['ldap.userDnTemplate'])
                }
                disabled={formBusy}
                placeholder="uid={{username}},ou=people,dc=example,dc=com"
                autoComplete="off"
                aria-describedby="ldap-user-dn-template-help"
              />
              {fieldError("ldap.userDnTemplate") ? (
                <div className="invalid-feedback">{fieldError("ldap.userDnTemplate")}</div>
              ) : null}
            </div>
          )}

          <div className="col-12">
            <div className="border-top mt-2 pt-3">
              <h3 className="h6 text-muted text-uppercase mb-3">Attribute Mapping</h3>
            </div>
          </div>

          <div className="col-12 col-lg-6 col-xl-3">
            <HoverHelpLabel
              htmlFor="ldap-email-attribute"
              className="form-label"
              helpText="Attribute containing the user's email address."
              helpId="ldap-email-attribute-help"
            >
              Email
            </HoverHelpLabel>
            <input
              id="ldap-email-attribute"
              type="text"
              className={`form-control${fieldError("ldap.emailAttribute") ? " is-invalid" : ""}`}
              value={form.ldap.emailAttribute}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, emailAttribute: event.target.value } }), ['ldap.emailAttribute'])
              }
              disabled={formBusy}
              placeholder="mail"
              autoComplete="off"
              aria-describedby="ldap-email-attribute-help"
            />
            {fieldError("ldap.emailAttribute") ? (
              <div className="invalid-feedback">{fieldError("ldap.emailAttribute")}</div>
            ) : null}
          </div>

          <div className="col-12 col-lg-6 col-xl-3">
            <HoverHelpLabel
              htmlFor="ldap-name-attribute"
              className="form-label"
              helpText="Attribute used for the user's display name."
              helpId="ldap-name-attribute-help"
            >
              Display name
            </HoverHelpLabel>
            <input
              id="ldap-name-attribute"
              type="text"
              className={`form-control${fieldError("ldap.nameAttribute") ? " is-invalid" : ""}`}
              value={form.ldap.nameAttribute}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, nameAttribute: event.target.value } }), ['ldap.nameAttribute'])
              }
              disabled={formBusy}
              placeholder="cn"
              autoComplete="off"
              aria-describedby="ldap-name-attribute-help"
            />
            {fieldError("ldap.nameAttribute") ? (
              <div className="invalid-feedback">{fieldError("ldap.nameAttribute")}</div>
            ) : null}
          </div>

          <div className="col-12 col-lg-6 col-xl-3">
            <HoverHelpLabel
              htmlFor="ldap-username-attribute"
              className="form-label"
              helpText="Attribute used to match usernames during login."
              helpId="ldap-username-attribute-help"
            >
              Username
            </HoverHelpLabel>
            <input
              id="ldap-username-attribute"
              type="text"
              className={`form-control${fieldError("ldap.usernameAttribute") ? " is-invalid" : ""}`}
              value={form.ldap.usernameAttribute}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, usernameAttribute: event.target.value } }), ['ldap.usernameAttribute'])
              }
              disabled={formBusy}
              placeholder="uid"
              autoComplete="off"
              aria-describedby="ldap-username-attribute-help"
            />
            {fieldError("ldap.usernameAttribute") ? (
              <div className="invalid-feedback">{fieldError("ldap.usernameAttribute")}</div>
            ) : null}
          </div>

          <div className="col-12 col-lg-6 col-xl-3">
            <HoverHelpLabel
              htmlFor="ldap-photo-attribute"
              className="form-label"
              helpText="Optional attribute containing a user thumbnail image."
              helpId="ldap-photo-attribute-help"
            >
              Thumbnail photo
            </HoverHelpLabel>
            <input
              id="ldap-photo-attribute"
              type="text"
              className={`form-control${fieldError("ldap.photoAttribute") ? " is-invalid" : ""}`}
              value={form.ldap.photoAttribute}
              onChange={(event) =>
                applyFormUpdate((prev) => ({ ...prev, ldap: { ...prev.ldap, photoAttribute: event.target.value } }), ['ldap.photoAttribute'])
              }
              disabled={formBusy}
              placeholder="thumbnailPhoto"
              autoComplete="off"
              aria-describedby="ldap-photo-attribute-help"
            />
            {fieldError("ldap.photoAttribute") ? (
              <div className="invalid-feedback">{fieldError("ldap.photoAttribute")}</div>
            ) : null}
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

  const rootEntries = getRootEntries();
  const hasRootEntries = rootEntries.length > 0;
  const showTreeEmptyState = !ldapBrowserBusy && !ldapBrowserError && !hasRootEntries;
  const emptyTreeMessage = ldapBrowserBaseDn
    ? `No entries returned for "${ldapBrowserBaseDn}".`
    : "No naming contexts returned. Provide the Base DN manually.";
  const showAccordions = Boolean(ldapBrowserDetail || ldapBrowserDiagnostics);

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
                <th scope="col" style={{ width: "4rem" }} className="text-center">
                  Order
                </th>
                <th scope="col">Provider</th>
                <th scope="col">Idp Type</th>
                <th scope="col">Key</th>
                <th scope="col">Status</th>
                <th scope="col" style={{ width: "8rem" }} className="text-end">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {orderedProviders.map((provider) => {
                const identifier = providerIdentifier(provider);
                const disabled = disabledIds.has(identifier) || busy;
                const isDragging = draggingId === identifier;
                const draggable = !disabled && orderedProviders.length > 1;

                return (
                  <tr
                    key={identifier}
                    className={isDragging ? "table-active" : undefined}
                    onDragOver={handleRowDragOver}
                    onDrop={(event) => handleRowDrop(event, provider)}
                    data-provider-id={identifier}
                  >
                    <td className="align-middle text-center">
                      <div className="d-inline-flex align-items-center gap-2 text-muted">
                        <button
                          type="button"
                          className="btn btn-link btn-sm p-0 text-muted idp-dnd-handle"
                          draggable={draggable}
                          onDragStart={(event) => handleRowDragStart(event, provider)}
                          onDragEnd={handleRowDragEnd}
                          aria-label={`Drag to reorder ${provider.name}`}
                          aria-grabbed={isDragging}
                        >
                          <i className="bi bi-grip-vertical" aria-hidden="true" />
                        </button>
                        <span className="fw-semibold text-body">{provider.evaluation_order}</span>
                      </div>
                    </td>
                    <td>
                      <div className="fw-semibold">{provider.name}</div>
                      <div className="small text-muted">
                        Added {new Date(provider.created_at).toLocaleString(undefined, { hour12: false })}
                      </div>
                      <div className="small text-muted">Reference #{provider.reference}</div>
                    </td>
                    <td className="text-uppercase fw-semibold small">{provider.driver}</td>
                    <td>
                      <code>{provider.key}</code>
                    </td>
                    <td>
                      <button
                        type="button"
                        className={`badge rounded-pill border-0 idp-status-toggle ${
                          provider.enabled ? "text-bg-success" : "text-bg-secondary"
                        }`}
                        onClick={() => handleToggle(provider)}
                        disabled={disabled}
                        aria-pressed={provider.enabled}
                        aria-label={`${provider.enabled ? "Disable" : "Enable"} ${provider.name}`}
                      >
                        {provider.enabled ? "Enabled" : "Disabled"}
                      </button>
                    </td>
                    <td className="text-end">
                      <div className="btn-group btn-group-sm" role="group" aria-label={`Actions for ${provider.name}`}>
                        <button
                          type="button"
                          className="btn btn-outline-secondary"
                          onClick={() => openEditModal(provider)}
                          disabled={disabled}
                          aria-label={`Edit ${provider.name}`}
                        >
                          <i className="bi bi-pencil-square" aria-hidden="true" />
                        </button>
                        <button
                          type="button"
                          className="btn btn-outline-danger"
                          onClick={() => openDeleteModal(provider)}
                          disabled={disabled}
                          aria-label={`Delete ${provider.name}`}
                        >
                          <i className="bi bi-trash" aria-hidden="true" />
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
        open={formOpen}
        title={formMode === "edit" ? "Edit Identity Provider" : "Add Identity Provider"}
        onCancel={closeFormModal}
        onConfirm={handleFormSubmit}
        busy={formBusy}
        confirmLabel={formMode === "edit" ? "Save" : "Create"}
        confirmTone="primary"
        confirmDisabled={formBusy}
        dialogClassName="modal-dialog modal-dialog-centered modal-xl"
        bodyClassName="modal-body pt-0"
        footerClassName="modal-footer flex-column flex-md-row align-items-start gap-3"
        footerStart={
          formErrors.general ? (
            <p className="text-danger small mb-0" role="alert">
              {formErrors.general}
            </p>
          ) : null
        }
      >
        <form
          className="d-flex flex-column gap-4"
          onSubmit={(event) => {
            event.preventDefault();
            void handleFormSubmit();
          }}
        >
          <div className="row g-3">
            <div className="col-12 col-md-5">
              <HoverHelpLabel
                htmlFor="idp-name"
                className="form-label"
                helpText="Shown to users on the login screen."
                helpId="idp-name-help"
              >
                Display name
              </HoverHelpLabel>
              <input
                id="idp-name"
                name="name"
                type="text"
                className={`form-control${fieldError("name") ? " is-invalid" : ""}`}
                value={form.name}
                onChange={(event) => applyFormUpdate((prev) => ({ ...prev, name: event.target.value }), ['name'])}
                placeholder="Microsoft Entra - Primary"
                disabled={formBusy}
                autoComplete="off"
                aria-describedby="idp-name-help"
                required
              />
              {fieldError("name") ? <div className="invalid-feedback">{fieldError("name")}</div> : null}
            </div>
            <div className="col-12 col-md-5">
              <HoverHelpLabel
                htmlFor="idp-driver"
                className="form-label"
                helpText="Choose the integration style for this provider."
                helpId="idp-driver-help"
              >
                Idp Type
              </HoverHelpLabel>
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
                disabled={formBusy || formMode === "edit"}
                aria-describedby="idp-driver-help"
                required
              >
                {DRIVER_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
              {fieldError("driver") ? <div className="invalid-feedback">{fieldError("driver")}</div> : null}
            </div>
            <div className="col-12 col-md-2 d-flex align-items-end">
              <div className="form-check form-switch d-flex align-items-center gap-2 mb-0">
                <input
                  id="idp-enabled"
                  name="enabled"
                  type="checkbox"
                  className="form-check-input"
                  role="switch"
                  checked={form.enabled}
                  onChange={(event) => applyFormUpdate((prev) => ({ ...prev, enabled: event.target.checked }), ['enabled'])}
                  aria-describedby="idp-enabled-help"
                  disabled={formBusy}
                />
                <HoverHelpLabel
                  className="form-check-label mb-0"
                  htmlFor="idp-enabled"
                  helpText="You can toggle availability later from the overview."
                  helpId="idp-enabled-help"
                >
                  Enable
                </HoverHelpLabel>
              </div>
          </div>
        </div>

        {form.driver === "saml" ? renderSamlSpSummary() : null}

        <div className="border-top pt-3">{renderDriverFields()}</div>

          <div className="border-top pt-3">
            <div className="d-flex flex-wrap gap-3 align-items-center">
              <button
                type="button"
                className="btn btn-outline-info"
                onClick={() => void handleTestConfiguration()}
                disabled={formBusy || testBusy}
              >
                {testBusy ? (
                  <>
                    <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
                    Testing...
                  </>
                ) : (
                  "Test"
                )}
              </button>
              {form.driver === "ldap" ? (
                <button
                  type="button"
                  className="btn btn-outline-primary"
                  onClick={openLdapBrowser}
                  disabled={formBusy || ldapBrowserBusy}
                >
                  {ldapBrowserBusy ? (
                    <>
                      <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
                      Loading...
                    </>
                  ) : (
                    "Browse"
                  )}
                </button>
              ) : null}
              {testResult ? (
                <span
                  className={`badge rounded-pill ${
                    normalizedTestStatus === "ok"
                      ? "text-bg-success"
                      : normalizedTestStatus === "warning"
                      ? "text-bg-warning"
                      : "text-bg-danger"
                  }`}
                >
                  {testStatusBadgeLabel}
                </span>
              ) : null}
              {testResult ? (
                <span className="text-muted small">
                  {testCheckedAtLabel ? `Checked ${testCheckedAtLabel}` : "Checked moments ago"}
                </span>
              ) : null}
            </div>
            {testError && !testResult ? (
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
                <div className="fw-semibold mb-1">{resolvedTestMessage}</div>
                {resolvedTestDetailMessage ? (
                  <div className="small mb-1">{resolvedTestDetailMessage}</div>
                ) : null}
                {adfsErrorDetail ? (
                  <div className="small mb-2">
                    <strong>IdP error:</strong> {adfsErrorDetail}
                  </div>
                ) : null}
                {Object.keys(testResult.details ?? {}).length > 0 ? (
                  <div className="accordion" id="idp-health-details">
                    <div className="accordion-item">
                      <h2 className="accordion-header" id="idp-health-details-heading">
                        <button
                          className={`accordion-button ${testDetailsOpen ? "" : "collapsed"}`}
                          type="button"
                          onClick={() => setTestDetailsOpen((prev) => !prev)}
                          aria-expanded={testDetailsOpen}
                          aria-controls="idp-health-details-body"
                        >
                          Technical details
                        </button>
                      </h2>
                      <div
                        id="idp-health-details-body"
                        className={`accordion-collapse collapse ${testDetailsOpen ? "show" : ""}`}
                        aria-labelledby="idp-health-details-heading"
                      >
                        <div className="accordion-body p-0">
                          <pre className="bg-body-secondary border-top rounded-bottom p-2 small overflow-auto mb-0">
                            {JSON.stringify(testResult.details, null, 2)}
                          </pre>
                        </div>
                      </div>
                    </div>
                  </div>
                ) : null}
              </div>
            ) : null}
          </div>

          <div className="border-top pt-3">
            <div className="d-flex align-items-center gap-2">
              <button
                type="button"
                className="btn btn-link p-0"
                onClick={() => setAdvancedOpen((prev) => !prev)}
                aria-expanded={advancedOpen}
                aria-controls="idp-meta-panel"
              >
                Advanced
              </button>
            </div>
            {advancedOpen ? (
              <div id="idp-meta-panel" className="mt-3">
                <HoverHelpLabel
                  htmlFor="idp-meta"
                  className="form-label"
                  helpText="Optional structured notes for automation or UI hints."
                  helpId="idp-meta-help"
                >
                  Optional JSON configuration
                </HoverHelpLabel>
                <textarea
                  id="idp-meta"
                  name="meta"
                  className={`form-control font-monospace${fieldError("meta") ? " is-invalid" : ""}`}
                  rows={3}
                  value={form.meta}
                  onChange={(event) => applyFormUpdate((prev) => ({ ...prev, meta: event.target.value }), ['meta'])}
                  disabled={formBusy}
                  placeholder='{"display_region": "us-east"}'
                  aria-describedby="idp-meta-help"
                />
                {fieldError("meta") ? <div className="invalid-feedback">{fieldError("meta")}</div> : null}
              </div>
            ) : null}
          </div>
        </form>
      </ConfirmModal>

      <ConfirmModal
        open={ldapBrowserOpen}
        title="LDAP Browser"
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
        {ldapBrowserPath.length > 0 ? (
          <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span className="fw-semibold small text-uppercase text-muted">Path:</span>
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
        ) : null}
        {ldapBrowserError ? (
          <div className="alert alert-danger" role="alert">
            <div className="fw-semibold">{ldapBrowserError}</div>
            {ldapBrowserDetail ? (
              <pre className="mt-2 mb-0 bg-body-secondary border rounded p-2 small overflow-auto">
                {JSON.stringify(ldapBrowserDetail, null, 2)}
              </pre>
            ) : null}
          </div>
        ) : null}
        {ldapBrowserBusy ? (
          <div className="d-flex justify-content-center py-3" role="status" aria-live="polite">
            <div className="spinner-border text-primary" role="presentation" aria-hidden="true" />
            <span className="visually-hidden">Browsing directory...</span>
          </div>
        ) : null}
        {!ldapBrowserError && hasRootEntries ? (
          <div className="border rounded p-3 bg-body-tertiary">
            {renderLdapTree(rootEntries)}
          </div>
        ) : null}
        {showTreeEmptyState ? <p className="text-muted mb-0">{emptyTreeMessage}</p> : null}
        {showAccordions ? (
          <div className="accordion mt-4" id="ldap-browser-info">
            {ldapBrowserDetail ? (
              <div className="accordion-item">
                <h2 className="accordion-header" id="ldap-browser-detail-heading">
                  <button
                    className={`accordion-button${ldapBrowserDetailOpen ? "" : " collapsed"}`}
                    type="button"
                    onClick={() => setLdapBrowserDetailOpen((prev) => !prev)}
                    aria-expanded={ldapBrowserDetailOpen}
                    aria-controls="ldap-browser-detail"
                  >
                    Last Directory Response
                  </button>
                </h2>
                <div
                  id="ldap-browser-detail"
                  className={`accordion-collapse collapse${ldapBrowserDetailOpen ? " show" : ""}`}
                  aria-labelledby="ldap-browser-detail-heading"
                >
                  <div className="accordion-body">
                    <pre className="mb-0 bg-body-secondary border rounded p-2 small overflow-auto">
                      {JSON.stringify(ldapBrowserDetail, null, 2)}
                    </pre>
                  </div>
                </div>
              </div>
            ) : null}
            {ldapBrowserDiagnostics ? (
              <div className="accordion-item mt-2">
                <h2 className="accordion-header" id="ldap-browser-diagnostics-heading">
                  <button
                    className={`accordion-button${ldapBrowserDiagnosticsOpen ? "" : " collapsed"}`}
                    type="button"
                    onClick={() => setLdapBrowserDiagnosticsOpen((prev) => !prev)}
                    aria-expanded={ldapBrowserDiagnosticsOpen}
                    aria-controls="ldap-browser-diagnostics"
                  >
                    LDAP Diagnostics
                  </button>
                </h2>
                <div
                  id="ldap-browser-diagnostics"
                  className={`accordion-collapse collapse${ldapBrowserDiagnosticsOpen ? " show" : ""}`}
                  aria-labelledby="ldap-browser-diagnostics-heading"
                >
                  <div className="accordion-body">
                    <pre className="mb-0 bg-body-secondary border rounded p-2 small overflow-auto">
                      {JSON.stringify(ldapBrowserDiagnostics, null, 2)}
                    </pre>
                  </div>
                </div>
              </div>
            ) : null}
          </div>
        ) : null}
      </ConfirmModal>
    </section>
  );
}
