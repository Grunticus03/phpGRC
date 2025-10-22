import { apiDelete, apiGet, apiPatch, apiPost } from "../api";

export type IdpProviderDriver = "oidc" | "saml" | "ldap" | "entra";

export type IdpProvider = {
  id: string;
  key: string;
  reference: number;
  name: string;
  driver: IdpProviderDriver | string;
  enabled: boolean;
  evaluation_order: number;
  config: Record<string, unknown>;
  meta: Record<string, unknown> | null;
  last_health_at: string | null;
  created_at: string;
  updated_at: string;
};

export type IdpProviderListMeta = {
  total: number;
  enabled: number;
};

export type IdpProviderListResponse = {
  ok: true;
  items: IdpProvider[];
  meta: IdpProviderListMeta;
  note?: "stub-only";
};

export type IdpProviderSuccessResponse = {
  ok: true;
  provider: IdpProvider;
};

export type IdpProviderStubResponse = {
  ok: true;
  note: "stub-only";
  provider?: Record<string, unknown>;
  deleted?: string | null;
};

export type IdpProviderDeleteResponse = {
  ok: true;
  deleted: string;
};

export type IdpProviderActionResult = IdpProviderSuccessResponse | IdpProviderStubResponse;

export type IdpProviderRequestPayload = {
  key?: string;
  name: string;
  driver: IdpProviderDriver;
  enabled?: boolean;
  evaluation_order?: number;
  config: Record<string, unknown>;
  meta?: Record<string, unknown> | null;
};

export type IdpProviderUpdatePayload = Partial<{
  key: string;
  name: string;
  driver: IdpProviderDriver;
  enabled: boolean;
  evaluation_order: number;
  config: Record<string, unknown>;
  meta: Record<string, unknown> | null;
}>;

export function listIdpProviders(signal?: AbortSignal): Promise<IdpProviderListResponse> {
  return apiGet<IdpProviderListResponse>("/admin/idp/providers", undefined, signal);
}

export function createIdpProvider(payload: IdpProviderRequestPayload): Promise<IdpProviderActionResult> {
  return apiPost<IdpProviderActionResult, IdpProviderRequestPayload>("/admin/idp/providers", payload);
}

export function updateIdpProvider(
  provider: string,
  payload: IdpProviderUpdatePayload,
  signal?: AbortSignal
): Promise<IdpProviderActionResult> {
  const encoded = encodeURIComponent(provider);
  return apiPatch<IdpProviderActionResult, IdpProviderUpdatePayload>(`/admin/idp/providers/${encoded}`, payload, signal);
}

export function deleteIdpProvider(
  provider: string,
  signal?: AbortSignal
): Promise<IdpProviderDeleteResponse | IdpProviderStubResponse> {
  const encoded = encodeURIComponent(provider);
  return apiDelete<IdpProviderDeleteResponse | IdpProviderStubResponse>(`/admin/idp/providers/${encoded}`, signal);
}

export function isStubResponse(result: unknown): result is IdpProviderStubResponse {
  return (
    typeof result === "object" &&
    result !== null &&
    (result as { note?: unknown }).note === "stub-only"
  );
}

export type SamlMetadataConfig = {
  entity_id: string;
  sso_url: string;
  certificate: string;
};

export type SamlMetadataPreviewResponse = {
  ok: true;
  config: SamlMetadataConfig;
};

export type SamlMetadataPreviewRequestPayload = {
  metadata?: string;
  url?: string;
};

export type SamlServiceProviderInfo = {
  entity_id: string;
  acs_url: string;
  metadata_url: string;
  sign_authn_requests: boolean;
  want_assertions_signed: boolean;
  want_assertions_encrypted: boolean;
};

export type SamlSpConfigResponse = {
  ok: true;
  sp: SamlServiceProviderInfo;
};

export function previewSamlMetadata(
  payload: SamlMetadataPreviewRequestPayload,
  signal?: AbortSignal
): Promise<SamlMetadataPreviewResponse> {
  return apiPost<SamlMetadataPreviewResponse, SamlMetadataPreviewRequestPayload>(
    "/admin/idp/providers/saml/metadata/preview",
    payload,
    signal
  );
}

export function previewSamlMetadataFromUrl(url: string, signal?: AbortSignal): Promise<SamlMetadataPreviewResponse> {
  return previewSamlMetadata({ url }, signal);
}

export function fetchSamlSpConfig(signal?: AbortSignal): Promise<SamlSpConfigResponse> {
  return apiGet<SamlSpConfigResponse>("/admin/idp/providers/saml/sp", undefined, signal);
}

export type IdpProviderPreviewPayload = {
  driver: IdpProviderDriver;
  config: Record<string, unknown>;
  meta?: Record<string, unknown> | null;
};

export type IdpProviderPreviewHealthResult = {
  ok: boolean;
  status: "ok" | "warning" | "error";
  message: string;
  checked_at: string;
  details: Record<string, unknown>;
};

export function previewIdpHealth(
  payload: IdpProviderPreviewPayload,
  signal?: AbortSignal
): Promise<IdpProviderPreviewHealthResult> {
  return apiPost<IdpProviderPreviewHealthResult, IdpProviderPreviewPayload>(
    "/admin/idp/providers/preview-health",
    payload,
    signal
  );
}

export type LdapBrowseRequestPayload = {
  driver: "ldap";
  config: Record<string, unknown>;
  base_dn?: string | null;
};

export type LdapBrowseEntry = {
  dn: string;
  rdn: string;
  name: string;
  type: string;
  object_class: string[];
  has_children: boolean;
};

export type LdapBrowseResponse = {
  ok: true;
  root: boolean;
  base_dn: string | null;
  requested_base_dn?: string | null;
  entries: LdapBrowseEntry[];
  diagnostics?: Record<string, unknown> | null;
};

export function browseLdapDirectory(
  payload: LdapBrowseRequestPayload,
  signal?: AbortSignal
): Promise<LdapBrowseResponse> {
  return apiPost<LdapBrowseResponse, LdapBrowseRequestPayload>("/admin/idp/providers/ldap/browse", payload, signal);
}
