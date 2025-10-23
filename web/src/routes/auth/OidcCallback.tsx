import { useEffect, useMemo, useState } from "react";
import { consumeIntendedPath, authOidcLogin, HttpError } from "../../lib/api";
import { consumeOidcProvider } from "./OidcStorage";

type ViewState =
  | { status: "working"; message: string }
  | { status: "error"; message: string }
  | { status: "done" };

const CALLBACK_PATH = "/auth/callback";

function decodeErrorDescription(raw: string | null): string {
  if (!raw) return "Sign-in failed. Please try again.";
  try {
    return decodeURIComponent(raw);
  } catch {
    return raw;
  }
}

export default function OidcCallback(): JSX.Element {
  const [state, setState] = useState<ViewState>({
    status: "working",
    message: "Finishing Microsoft Entra sign-in…",
  });

  const searchParams = useMemo(() => new URLSearchParams(window.location.search), []);

  useEffect(() => {
    const error = searchParams.get("error");
    if (error) {
      const reason = decodeErrorDescription(searchParams.get("error_description"));
      setState({
        status: "error",
        message: reason || `Sign-in failed: ${error}`,
      });
      return;
    }

    const code = searchParams.get("code");
    const idToken = searchParams.get("id_token");
    const stateParam = searchParams.get("state");

    if (!code && !idToken) {
      setState({
        status: "error",
        message: "Missing authorization response from Microsoft Entra. Please start again.",
      });
      return;
    }

    const provider = consumeOidcProvider();
    if (!provider) {
      setState({
        status: "error",
        message: "Sign-in session has expired. Please return to the login page and try again.",
      });
      return;
    }

    const payload: {
      provider: string;
      redirect_uri: string;
      code?: string;
      id_token?: string;
      state?: string;
    } = {
      provider,
      redirect_uri: `${window.location.origin}${CALLBACK_PATH}`,
    };

    if (code) {
      payload.code = code;
    } else if (idToken) {
      payload.id_token = idToken;
    }

    if (stateParam) {
      payload.state = stateParam;
    }

    authOidcLogin(payload)
      .then(() => {
        setState({ status: "done" });
        const destination = consumeIntendedPath() || "/dashboard";
        window.location.assign(destination);
      })
      .catch((err: unknown) => {
        console.error("OIDC login failed", err);
        let message =
          "Unable to finish Microsoft Entra sign-in. Please return to the login page and try again.";
        if (err instanceof HttpError) {
          const body = err.body;
          if (body && typeof body === "object") {
            const asObject = body as Record<string, unknown>;
            if (typeof asObject.message === "string" && asObject.message.trim() !== "") {
              message = asObject.message.trim();
            } else if (Array.isArray(asObject.errors)) {
              const first = asObject.errors.find((entry) => typeof entry === "string");
              if (typeof first === "string" && first.trim() !== "") {
                message = first.trim();
              }
            } else if (typeof asObject.error === "string" && asObject.error.trim() !== "") {
              const description =
                typeof asObject.error_description === "string"
                  ? asObject.error_description.trim()
                  : null;
              message = description && description !== "" ? description : asObject.error.trim();
            }
          }
        }
        setState({ status: "error", message });
      });
  }, [searchParams]);

  if (state.status === "error") {
    return (
      <div className="container py-5">
        <div className="row justify-content-center">
          <div className="col-12 col-md-8 col-lg-6">
            <div className="alert alert-danger" role="alert">
              {state.message}
            </div>
            <a className="btn btn-primary" href="/auth/login">
              Back to sign-in
            </a>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="container py-5">
      <div className="row justify-content-center">
        <div className="col-12 col-md-6 col-lg-4 text-center">
          <div className="spinner-border text-primary mb-3" role="status" aria-hidden="true" />
          <p className="text-muted mb-0">
            {state.status === "working" ? state.message : "Redirecting…"}
          </p>
        </div>
      </div>
    </div>
  );
}
