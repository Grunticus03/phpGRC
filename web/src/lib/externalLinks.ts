const DEFAULT_API_DOCS_URL = "https://phpgrc.gruntlabs.net:8443/api-docs/";

export const API_DOCS_URL =
  typeof window !== "undefined"
    ? `https://${window.location.hostname}:8443/api-docs/`
    : DEFAULT_API_DOCS_URL;

