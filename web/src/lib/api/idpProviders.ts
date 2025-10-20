import { apiDelete, apiGet, apiPatch, apiPost } from "../api";

export type IdpProviderDriver = "oidc" | "saml" | "ldap" | "entra";

export type IdpProvider = {
  id: string;
  key: string;
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
  key: string;
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

export function previewSamlMetadata(metadata: string, signal?: AbortSignal): Promise<SamlMetadataPreviewResponse> {
  return apiPost<SamlMetadataPreviewResponse, { metadata: string }>(
    "/admin/idp/providers/saml/metadata/preview",
    { metadata },
    signal
  );
}
