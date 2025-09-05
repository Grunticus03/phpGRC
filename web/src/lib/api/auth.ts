// Placeholder client wrappers. Replace with real fetch/axios later.

export async function login(_email: string, _password: string) {
  return { ok: true };
}

export async function logout() {
  return;
}

export async function me() {
  return { user: { id: 0, email: "placeholder@example.com", roles: [] } };
}

export async function totpEnroll() {
  return { otpauthUri: "otpauth://totp/phpGRC:placeholder", secret: "PLACEHOLDER" };
}

export async function totpVerify(_code: string) {
  return { ok: true };
}
