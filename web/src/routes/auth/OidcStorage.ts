const OIDC_PROVIDER_STORAGE_KEY = "phpgrc.oidc.provider";

export function rememberOidcProvider(identifier: string): void {
  if (!identifier) return;
  try {
    window.sessionStorage.setItem(OIDC_PROVIDER_STORAGE_KEY, identifier);
  } catch {
    // ignore storage errors (private mode, disabled storage, etc.)
  }
}

export function consumeOidcProvider(): string | null {
  try {
    const value = window.sessionStorage.getItem(OIDC_PROVIDER_STORAGE_KEY);
    if (value !== null) {
      window.sessionStorage.removeItem(OIDC_PROVIDER_STORAGE_KEY);
    }
    return value;
  } catch {
    return null;
  }
}
