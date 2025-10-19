import { useCallback, useEffect, useMemo, useState } from "react";
import ConfirmModal from "../../components/modal/ConfirmModal";
import { useToast } from "../../components/toast/ToastProvider";
import {
  createIdpProvider,
  deleteIdpProvider,
  isStubResponse,
  listIdpProviders,
  updateIdpProvider,
  type IdpProvider,
  type IdpProviderListMeta,
  type IdpProviderRequestPayload,
  type IdpProviderUpdatePayload,
} from "../../lib/api/idpProviders";

type FormState = {
  key: string;
  name: string;
  driver: string;
  enabled: boolean;
  config: string;
  meta: string;
};

type FormErrors = Partial<Record<keyof FormState, string>> & { general?: string };

type PendingAction = { type: "toggle" | "reorder" | "delete" | "create"; id: string | null };

const DEFAULT_FORM_STATE: FormState = {
  key: "",
  name: "",
  driver: "oidc",
  enabled: true,
  config: "{\n  \"issuer\": \"\",\n  \"client_id\": \"\",\n  \"client_secret\": \"\"\n}",
  meta: "{\n  \"display_region\": \"\"\n}",
};

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
  const [form, setForm] = useState<FormState>(DEFAULT_FORM_STATE);
  const [formErrors, setFormErrors] = useState<FormErrors>({});

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
    setForm(DEFAULT_FORM_STATE);
    setFormErrors({});
  }, []);

  const openCreateModal = useCallback(() => {
    resetForm();
    setCreateOpen(true);
    setFormErrors({});
  }, [resetForm]);

  const closeCreateModal = useCallback(() => {
    if (createBusy) return;
    setCreateOpen(false);
  }, [createBusy]);

  const validateForm = useCallback(
    (state: FormState): { valid: boolean; payload?: IdpProviderRequestPayload } => {
      const errors: FormErrors = {};
      const trimmedKey = state.key.trim();
      if (trimmedKey.length < 3) {
        errors.key = "Key must be at least 3 characters.";
      }
      if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(trimmedKey)) {
        errors.key = "Key may only include lowercase letters, numbers, and hyphens.";
      }
      const trimmedName = state.name.trim();
      if (trimmedName.length < 3) {
        errors.name = "Name must be at least 3 characters.";
      }
      const driver = state.driver.trim();
      if (!driver) {
        errors.driver = "Driver is required.";
      }

      let config: Record<string, unknown> | null = null;
      try {
        config = JSON.parse(state.config) as Record<string, unknown>;
        if (config === null || typeof config !== "object" || Array.isArray(config)) {
          throw new Error("invalid");
        }
      } catch {
        errors.config = "Config must be valid JSON object.";
      }

      let meta: Record<string, unknown> | null = null;
      const metaTrimmed = state.meta.trim();
      if (metaTrimmed !== "") {
        try {
          const parsed = JSON.parse(metaTrimmed) as unknown;
          if (parsed !== null && typeof parsed === "object" && !Array.isArray(parsed)) {
            meta = parsed as Record<string, unknown>;
          } else {
            throw new Error("invalid meta");
          }
        } catch {
          errors.meta = "Meta must be valid JSON object or left blank.";
        }
      }

      if (Object.keys(errors).length > 0) {
        setFormErrors(errors);
        return { valid: false };
      }

      setFormErrors({});
      const payload: IdpProviderRequestPayload = {
        key: trimmedKey,
        name: trimmedName,
        driver: driver as IdpProviderRequestPayload["driver"],
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
      setFormErrors({ general: "Failed to create provider. Check inputs and try again." });
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
                <th scope="col">Driver</th>
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
      >
        <form
          className="d-flex flex-column gap-3"
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
          <div>
            <label htmlFor="idp-key" className="form-label">
              Provider key
            </label>
            <input
              id="idp-key"
              name="key"
              type="text"
              className={`form-control${formErrors.key ? " is-invalid" : ""}`}
              value={form.key}
              onChange={(event) => setForm((prev) => ({ ...prev, key: event.target.value }))}
              placeholder="okta-primary"
              disabled={createBusy}
              required
            />
            {formErrors.key ? <div className="invalid-feedback">{formErrors.key}</div> : null}
          </div>
          <div>
            <label htmlFor="idp-name" className="form-label">
              Display name
            </label>
            <input
              id="idp-name"
              name="name"
              type="text"
              className={`form-control${formErrors.name ? " is-invalid" : ""}`}
              value={form.name}
              onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
              placeholder="Okta Primary"
              disabled={createBusy}
              required
            />
            {formErrors.name ? <div className="invalid-feedback">{formErrors.name}</div> : null}
          </div>
          <div>
            <label htmlFor="idp-driver" className="form-label">
              Driver
            </label>
            <select
              id="idp-driver"
              name="driver"
              className={`form-select${formErrors.driver ? " is-invalid" : ""}`}
              value={form.driver}
              onChange={(event) => setForm((prev) => ({ ...prev, driver: event.target.value }))}
              disabled={createBusy}
              required
            >
              <option value="oidc">OIDC</option>
              <option value="saml">SAML</option>
              <option value="ldap">LDAP</option>
              <option value="entra">Entra ID</option>
            </select>
            {formErrors.driver ? <div className="invalid-feedback">{formErrors.driver}</div> : null}
          </div>
          <div className="form-check form-switch">
            <input
              id="idp-enabled"
              name="enabled"
              type="checkbox"
              className="form-check-input"
              role="switch"
              checked={form.enabled}
              onChange={(event) => setForm((prev) => ({ ...prev, enabled: event.target.checked }))}
              disabled={createBusy}
            />
            <label className="form-check-label" htmlFor="idp-enabled">
              Enable immediately
            </label>
          </div>
          <div>
            <label htmlFor="idp-config" className="form-label">
              Provider config (JSON)
            </label>
            <textarea
              id="idp-config"
              name="config"
              className={`form-control font-monospace${formErrors.config ? " is-invalid" : ""}`}
              rows={6}
              value={form.config}
              onChange={(event) => setForm((prev) => ({ ...prev, config: event.target.value }))}
              disabled={createBusy}
              required
            />
            {formErrors.config ? <div className="invalid-feedback">{formErrors.config}</div> : null}
          </div>
          <div>
            <label htmlFor="idp-meta" className="form-label">
              Meta (JSON, optional)
            </label>
            <textarea
              id="idp-meta"
              name="meta"
              className={`form-control font-monospace${formErrors.meta ? " is-invalid" : ""}`}
              rows={3}
              value={form.meta}
              onChange={(event) => setForm((prev) => ({ ...prev, meta: event.target.value }))}
              disabled={createBusy}
              placeholder='{"region": "us-east"}'
            />
            {formErrors.meta ? <div className="invalid-feedback">{formErrors.meta}</div> : null}
          </div>
        </form>
      </ConfirmModal>
    </section>
  );
}
