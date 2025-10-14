import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type SyntheticEvent } from "react";
import { consumeIntendedPath, authLogin, consumeSessionExpired } from "../../lib/api";
import { getCachedThemeSettings, onThemeSettingsChange } from "../../theme/themeManager";
import { DEFAULT_THEME_SETTINGS, type ThemeSettings } from "../admin/themeData";
import "./LoginLayout3.css";

const DEFAULT_LOGO_SRC = "/api/images/phpGRC-light-horizontal-trans.png";

const brandAssetUrl = (assetId: string): string =>
  `/api/settings/ui/brand-assets/${encodeURIComponent(assetId)}/download`;

const resolvePrimaryLogo = (settings: ThemeSettings | null | undefined): string | null => {
  const brand = settings?.brand ?? DEFAULT_THEME_SETTINGS.brand;
  const primaryId = typeof brand?.primary_logo_asset_id === "string" ? brand.primary_logo_asset_id : null;
  if (!primaryId) return null;
  return `${brandAssetUrl(primaryId)}?v=${encodeURIComponent(primaryId)}`;
};

const LAYOUT3_EMAIL_ENTER_MS = 650;
const LAYOUT3_EMAIL_EXIT_MS = 420;
const LAYOUT3_PASSWORD_ENTER_MS = 650;
const LAYOUT3_PASSWORD_EXIT_MS = 420;
const LAYOUT3_PASSWORD_SHAKE_MS = 320;
const LAYOUT3_PAGE_FADE_MS = 500;

type Layout3View = "email" | "password";
type Layout3PanelAnimation = "idle" | "enter" | "exit" | "shake";

export default function Login(): JSX.Element {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [logoSrc, setLogoSrc] = useState<string | null>(() => resolvePrimaryLogo(getCachedThemeSettings()));
  const [layout, setLayout] = useState<"layout_1" | "layout_2" | "layout_3">(() => {
    const cachedLayout = getCachedThemeSettings().theme.login?.layout;
    return cachedLayout === "layout_2" || cachedLayout === "layout_3" ? cachedLayout : "layout_1";
  });
  const [prefersReducedMotion, setPrefersReducedMotion] = useState<boolean>(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return false;
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  });
  const [layout3View, setLayout3View] = useState<Layout3View>("email");
  const [layout3Ready, setLayout3Ready] = useState(false);
  const [emailAnimation, setEmailAnimation] = useState<Layout3PanelAnimation>("idle");
  const [passwordAnimation, setPasswordAnimation] = useState<Layout3PanelAnimation>("idle");
  const [pageAnimation, setPageAnimation] = useState<"idle" | "fading">("idle");
  const emailInputRef = useRef<HTMLInputElement | null>(null);
  const passwordInputRef = useRef<HTMLInputElement | null>(null);
  const animationTimers = useRef<number[]>([]);
  const focusFrame = useRef<number | null>(null);
  const isLayout3 = layout === "layout_3";

  const schedule = useCallback(
    (callback: () => void, delay: number) => {
      if (typeof window === "undefined" || prefersReducedMotion) {
        callback();
        return;
      }
      const id = window.setTimeout(() => {
        callback();
        animationTimers.current = animationTimers.current.filter((entry) => entry !== id);
      }, delay);
      animationTimers.current.push(id);
    },
    [prefersReducedMotion]
  );

  useEffect(() => {
    const unsubscribe = onThemeSettingsChange((next) => {
      setLogoSrc(resolvePrimaryLogo(next));
      const nextLayout = next.theme.login?.layout;
      setLayout(nextLayout === "layout_2" || nextLayout === "layout_3" ? nextLayout : "layout_1");
    });
    return () => unsubscribe();
  }, []);

  useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return;
    const media = window.matchMedia("(prefers-reduced-motion: reduce)");
    const listener = (event: MediaQueryListEvent) => setPrefersReducedMotion(event.matches);
    media.addEventListener("change", listener);
    return () => media.removeEventListener("change", listener);
  }, []);

  useEffect(() => {
    return () => {
      animationTimers.current.forEach((timer) => window.clearTimeout(timer));
      animationTimers.current = [];
      if (focusFrame.current !== null && typeof window !== "undefined") {
        window.cancelAnimationFrame(focusFrame.current);
      }
    };
  }, []);

  useEffect(() => {
    if (!isLayout3) {
      setLayout3Ready(false);
      setLayout3View("email");
      setEmailAnimation("idle");
      setPasswordAnimation("idle");
      setPageAnimation("idle");
      setErr(null);
      setPassword("");
      return;
    }
    setLayout3Ready(true);
    setLayout3View("email");
    setPageAnimation("idle");
    setEmailAnimation(prefersReducedMotion ? "idle" : "enter");
    setPasswordAnimation("idle");
    setErr(null);
    if (!prefersReducedMotion) {
      schedule(() => {
        setEmailAnimation((current) => (current === "enter" ? "idle" : current));
      }, LAYOUT3_EMAIL_ENTER_MS);
    }
  }, [isLayout3, prefersReducedMotion, schedule]);

  useEffect(() => {
    if (!isLayout3) return;
    if (typeof window === "undefined") return;
    const target = layout3View === "email" ? emailInputRef.current : passwordInputRef.current;
    if (!target) return;
    if (focusFrame.current !== null) {
      window.cancelAnimationFrame(focusFrame.current);
    }
    focusFrame.current = window.requestAnimationFrame(() => {
      target.focus();
      if (layout3View === "email" && typeof target.select === "function") {
        target.select();
      }
      focusFrame.current = null;
    });
  }, [isLayout3, layout3View]);

  useEffect(() => {
    if (consumeSessionExpired()) {
      setInfo("Session expired—please sign in again.");
    }
  }, []);

  const advanceToPassword = useCallback(() => {
    if (!isLayout3 || layout3View !== "email") return;
    const emailField = emailInputRef.current;
    if (emailField && !emailField.checkValidity()) {
      emailField.reportValidity();
      return;
    }
    setErr(null);
    setPassword("");
    setEmailAnimation("exit");
    setLayout3View("password");
    setPasswordAnimation(prefersReducedMotion ? "idle" : "enter");
    if (!prefersReducedMotion) {
      schedule(() => {
        setEmailAnimation((current) => (current === "exit" ? "idle" : current));
      }, LAYOUT3_EMAIL_EXIT_MS);
      schedule(() => {
        setPasswordAnimation((current) => (current === "enter" ? "idle" : current));
      }, LAYOUT3_PASSWORD_ENTER_MS);
    }
  }, [isLayout3, layout3View, prefersReducedMotion, schedule]);

  const handleBackToEmail = useCallback(() => {
    if (!isLayout3 || layout3View !== "password" || pageAnimation === "fading") return;
    setErr(null);
    setBusy(false);
    setPassword("");
    setPasswordAnimation("exit");
    setLayout3View("email");
    setEmailAnimation(prefersReducedMotion ? "idle" : "enter");
    if (!prefersReducedMotion) {
      schedule(() => {
        setPasswordAnimation((current) => (current === "exit" ? "idle" : current));
      }, LAYOUT3_PASSWORD_EXIT_MS);
      schedule(() => {
        setEmailAnimation((current) => (current === "enter" ? "idle" : current));
      }, LAYOUT3_EMAIL_ENTER_MS);
    }
  }, [isLayout3, layout3View, pageAnimation, prefersReducedMotion, schedule]);

  const triggerPasswordShake = useCallback(() => {
    if (!isLayout3) return;
    setPasswordAnimation("shake");
    schedule(() => {
      setPasswordAnimation((current) => (current === "shake" ? "idle" : current));
    }, LAYOUT3_PASSWORD_SHAKE_MS);
  }, [isLayout3, schedule]);

  const triggerLayout3Success = useCallback(
    (destination: string) => {
      if (!isLayout3) {
        if (typeof window !== "undefined") {
          window.location.assign(destination);
        }
        return;
      }
      setLayout3View("password");
      setPageAnimation("fading");
      setPasswordAnimation("exit");
      schedule(() => {
        setPasswordAnimation((current) => (current === "exit" ? "idle" : current));
      }, LAYOUT3_PASSWORD_EXIT_MS);
      schedule(() => {
        if (typeof window !== "undefined") {
          window.location.assign(destination);
        }
      }, LAYOUT3_PAGE_FADE_MS);
    },
    [isLayout3, schedule]
  );

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isLayout3 && layout3View === "email") {
      advanceToPassword();
      return;
    }

    setErr(null);
    setBusy(true);
    let didSucceed = false;
    try {
      await authLogin({ email, password });
      didSucceed = true;
      const dest = consumeIntendedPath() || "/dashboard";
      if (isLayout3) {
        triggerLayout3Success(dest);
      } else if (typeof window !== "undefined") {
        window.location.assign(dest);
      }
    } catch {
      setErr("Invalid credentials or server error.");
      if (isLayout3) {
        triggerPasswordShake();
      }
    } finally {
      if (!isLayout3 || !didSucceed) {
        setBusy(false);
      }
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
  const layout3WrapperClassName = [
    "login-layout3",
    layout3Ready ? "is-ready" : "",
    pageAnimation === "fading" ? "is-fading" : "",
    busy ? "is-busy" : "",
  ]
    .filter(Boolean)
    .join(" ");
  const layout3EmailClassName = [
    "login-layout3__panel",
    "login-layout3__panel--email",
    layout3View === "email" ? "is-active" : "is-inactive",
    emailAnimation === "enter" ? "is-entering" : "",
    emailAnimation === "exit" ? "is-exiting" : "",
  ]
    .filter(Boolean)
    .join(" ");
  const layout3PasswordClassName = [
    "login-layout3__panel",
    "login-layout3__panel--password",
    layout3View === "password" ? "is-active" : "is-inactive",
    passwordAnimation === "enter" ? "is-entering" : "",
    passwordAnimation === "exit" ? "is-exiting" : "",
    passwordAnimation === "shake" ? "is-shaking" : "",
  ]
    .filter(Boolean)
    .join(" ");

  const layout3 = (
    <div className={layout3WrapperClassName}>
      <div className="login-layout3__logo-wrapper">
        <img
          src={displayLogo}
          alt="Organization logo"
          height={72}
          style={{ width: "auto", maxWidth: "320px" }}
          onError={handleLogoError}
        />
      </div>
      <div className="login-layout3__panel-container">
        <section className={layout3EmailClassName} aria-hidden={layout3View !== "email"}>
          <form onSubmit={onSubmit} className="login-layout3__form">
            {hasFeedback && layout3View === "email" ? (
              <div className="login-layout3__feedback" aria-live="polite">
                {feedback}
              </div>
            ) : null}
            <input
              ref={emailInputRef}
              type="email"
              className="form-control form-control-lg text-center"
              value={email}
              onChange={(ev) => setEmail(ev.currentTarget.value)}
              required
              autoComplete="username"
              placeholder="Email"
              aria-label="Email address"
              disabled={layout3View !== "email" || busy}
            />
            <button
              type="submit"
              className="btn btn-primary btn-lg w-100 login-layout3__primary-btn mt-3"
              disabled={layout3View !== "email" || busy}
            >
              Continue
            </button>
          </form>
        </section>
        <section className={layout3PasswordClassName} aria-hidden={layout3View !== "password"}>
          <form onSubmit={onSubmit} className="login-layout3__form">
            <div className="login-layout3__password-header">
              <button
                type="button"
                className="btn btn-link login-layout3__back"
                onClick={handleBackToEmail}
                aria-label="Back to email entry"
                disabled={busy || pageAnimation === "fading"}
              >
                <span aria-hidden="true">{"\u2190"}</span>
              </button>
              <span className="login-layout3__password-title">Sign in</span>
            </div>
            {hasFeedback && layout3View === "password" ? (
              <div className="login-layout3__feedback" aria-live="polite">
                {feedback}
              </div>
            ) : null}
            <input
              ref={passwordInputRef}
              type="password"
              className="form-control form-control-lg"
              value={password}
              onChange={(ev) => setPassword(ev.currentTarget.value)}
              required
              autoComplete="current-password"
              placeholder="Password"
              aria-label="Password"
              disabled={layout3View !== "password" || busy}
            />
            <button
              type="submit"
              className="btn btn-primary btn-lg w-100 login-layout3__primary-btn mt-3"
              disabled={busy || pageAnimation === "fading"}
            >
              {busy ? "Signing in..." : "Sign in"}
            </button>
          </form>
        </section>
      </div>
    </div>
  );

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
      <div className="mb-3">
        <img
          src={displayLogo}
          alt="Organization logo"
          height={48}
          style={{ width: "auto", maxWidth: "200px" }}
          className="d-block"
          onError={handleLogoError}
        />
      </div>
      <form onSubmit={onSubmit} className="mt-3">
        {hasFeedback ? <div className="mb-3">{feedback}</div> : null}
        <div className="d-flex align-items-center gap-3 flex-wrap">
          <div className="flex-grow-1 vstack gap-3">
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
          <button
            type="submit"
            className="btn btn-primary btn-lg rounded-circle d-flex align-items-center justify-content-center"
            style={{ width: "64px", height: "64px" }}
            disabled={busy}
          >
            {busy ? (
              <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
            ) : (
              <span aria-hidden="true">{"\u2192"}</span>
            )}
            <span className="visually-hidden">Submit credentials</span>
          </button>
        </div>
      </form>
    </div>
  );

  return (
    <div className="d-flex flex-column min-vh-100 align-items-center justify-content-center bg-body-secondary px-3">
      {isLayout3 ? layout3 : isLayout2 ? layout2 : layout1}
    </div>
  );
}
