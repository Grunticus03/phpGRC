import { apiGet, apiPost } from "../api";

export type LoginResponse = { ok: boolean; token?: string; user?: { id: number; email: string; roles: string[] } };
export type MeResponse = { user: { id: number; email: string; roles: string[] } };
export type TotpEnrollResponse = { otpauthUri: string; secret: string };
export type TotpVerifyResponse = { ok: boolean };

export async function login(email: string, password: string): Promise<LoginResponse> {
  return apiPost<LoginResponse, { email: string; password: string }>("/api/auth/login", { email, password });
}

export async function logout(): Promise<void> {
  await apiPost<unknown, Record<string, never>>("/api/auth/logout", {});
}

export async function me(): Promise<MeResponse> {
  return apiGet<MeResponse>("/api/auth/me");
}

export async function totpEnroll(): Promise<TotpEnrollResponse> {
  return apiPost<TotpEnrollResponse, Record<string, never>>("/api/auth/totp/enroll", {});
}

export async function totpVerify(code: string): Promise<TotpVerifyResponse> {
  return apiPost<TotpVerifyResponse, { code: string }>("/api/auth/totp/verify", { code });
}
