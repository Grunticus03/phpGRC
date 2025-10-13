import { useEffect, useMemo, useState, type FormEvent, type SyntheticEvent } from "react";
import { consumeIntendedPath, authLogin, consumeSessionExpired } from "../../lib/api";
import { getCachedThemeSettings, onThemeSettingsChange } from "../../theme/themeManager";
import { DEFAULT_THEME_SETTINGS, type ThemeSettings } from "../admin/themeData";

const DEFAULT_LOGO_SRC = "/api/images/phpGRC-light-horizontal-trans.png";

const brandAssetUrl = (assetId: string): string =>
  `/api/settings/ui/brand-assets/${encodeURIComponent(assetId)}/download`;

const resolvePrimaryLogo = (settings: ThemeSettings | null | undefined): string | null => {
  const brand = settings?.brand ?? DEFAULT_THEME_SETTINGS.brand;
  const primaryId = typeof brand?.primary_logo_asset_id === "string" ? brand.primary_logo_asset_id : null;
  if (!primaryId) return null;
  return `${brandAssetUrl(primaryId)}?v=${encodeURIComponent(primaryId)}`;
};

export default function Login(): JSX.Element {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [logoSrc, setLogoSrc] = useState<string | null>(() => resolvePrimaryLogo(getCachedThemeSettings()));
  const [layout, setLayout] = useState<"layout_1" | "layout_2">(() =>
    getCachedThemeSettings().theme.login?.layout === "layout_2" ? "layout_2" : "layout_1"
  );

  useEffect(() => {
    const unsubscribe = onThemeSettingsChange((next) => {
      setLogoSrc(resolvePrimaryLogo(next));
      setLayout(next.theme.login?.layout === "layout_2" ? "layout_2" : "layout_1");
    });
    return () => unsubscribe();
  }, []);

  useEffect(() => {
    if (consumeSessionExpired()) {
      setInfo("Session expired—please sign in again.");
    }
  }, []);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setErr(null);
    setBusy(true);
    try {
      await authLogin({ email, password });
      const dest = consumeIntendedPath() || "/dashboard";
      window.location.assign(dest);
    } catch {
      setErr("Invalid credentials or server error.");
    } finally {
      setBusy(false);
    }
  }

  const displayLogo = useMemo(() => logoSrc ?? DEFAULT_LOGO_SRC, [logoSrc]);
  const isLayout2 = layout === "layout_2";
  const handleLogoError = (event: SyntheticEvent<HTMLImageElement>) => {
    event.currentTarget.onerror = null;
    event.currentTarget.src = DEFAULT_LOGO_SRC;
  };
  const feedback = (
    <>
      {info ? (
        <div className="alert alert-warning text-center py-2" role="status">
          {info}
        </div>
      ) : null}

      {err ? (
        <div className="alert alert-danger text-center py-2" role="alert">
          {err}
        </div>
      ) : null}
    </>
  );

  const hasFeedback = info !== null || err !== null;

  const layout1 = (
    <>
      <div className="text-center mb-4">
        <img
          src={displayLogo}
          alt="Organization logo"
          height={72}
          style={{ width: "auto", maxWidth: "320px" }}
          onError={handleLogoError}
        />
      </div>
      <div className="card bg-body shadow-sm border-0 w-100" style={{ maxWidth: "420px" }}>
        <div className="card-body p-4">
          <h1 className="h4 text-center mb-4">Sign in</h1>
          {feedback}

          <form onSubmit={onSubmit} className="vstack gap-3 mt-3">
            <div>
              <input
                type="email"
                className="form-control form-control-lg text-center"
                value={email}
                onChange={(ev) => setEmail(ev.currentTarget.value)}
                required
                autoComplete="username"
                placeholder="Email"
                aria-label="Email address"
              />
            </div>
            <div>
              <input
                type="password"
                className="form-control form-control-lg text-center"
                value={password}
                onChange={(ev) => setPassword(ev.currentTarget.value)}
                required
                autoComplete="current-password"
                placeholder="Password"
                aria-label="Password"
              />
            </div>
            <button type="submit" className="btn btn-primary btn-lg w-100" disabled={busy}>
              {busy ? "Signing in..." : "Sign in"}
            </button>
          </form>
        </div>
      </div>
    </>
  );

  const layout2 = (
    <div className="w-100" style={{ maxWidth: "520px" }}>
      <h1 className="visually-hidden">Sign in</h1>
      <form onSubmit={onSubmit} className="vstack gap-3 align-items-start w-100">
        {hasFeedback ? <div className="ms-4 w-100">{feedback}</div> : null}
        <div className="position-relative w-100">
          <button
            type="submit"
            className="btn btn-primary btn-lg rounded-circle d-flex align-items-center justify-content-center position-absolute top-50 start-0"
            style={{ width: "72px", height: "72px", transform: "translate(10%, -50%)" }}
            disabled={busy}
          >
            {busy ? (
              <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
            ) : (
              <span aria-hidden="true">{"\u2192"}</span>
            )}
            <span className="visually-hidden">Submit credentials</span>
          </button>
          <div className="vstack gap-3 ms-4">
            <img
              src={displayLogo}
              alt="Organization logo"
              height={48}
              style={{ width: "auto", maxWidth: "200px" }}
              className="d-block"
              onError={handleLogoError}
            />
            <input
              type="email"
              className="form-control form-control-lg"
              value={email}
              onChange={(ev) => setEmail(ev.currentTarget.value)}
              required
              autoComplete="username"
              placeholder="Email"
              aria-label="Email address"
            />
            <input
              type="password"
              className="form-control form-control-lg"
              value={password}
              onChange={(ev) => setPassword(ev.currentTarget.value)}
              required
              autoComplete="current-password"
              placeholder="Password"
              aria-label="Password"
            />
          </div>
        </div>
      </form>
    </div>
  );

  return (
    <div className="d-flex flex-column min-vh-100 align-items-center justify-content-center bg-body-secondary px-3">
      {isLayout2 ? layout2 : layout1}
    </div>
  );
}
