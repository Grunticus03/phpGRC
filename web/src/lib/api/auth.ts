// Placeholder client wrappers. Replace with real fetch/axios later.

export type LoginResponse = { ok: boolean };
export type MeResponse = { user: { id: number; email: string; roles: string[] } };
export type TotpEnrollResponse = { otpauthUri: string; secret: string };
export type TotpVerifyResponse = { ok: boolean };

export async function login(email: string, password: string): Promise<LoginResponse> {
  // Mark parameters as intentionally unused for now.
  void email;
  void password;
  return { ok: true };
}

export async function logout(): Promise<void> {
  return;
}

export async function me(): Promise<MeResponse> {
  return { user: { id: 0, email: "placeholder@example.com", roles: [] } };
}

export async function totpEnroll(): Promise<TotpEnrollResponse> {
  return { otpauthUri: "otpauth://totp/phpGRC:placeholder", secret: "PLACEHOLDER" };
}

export async function totpVerify(code: string): Promise<TotpVerifyResponse> {
  void code;
  return { ok: true };
}
