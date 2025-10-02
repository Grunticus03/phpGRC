import { useEffect, useState } from "react";
import { consumeIntendedPath, authLogin, consumeSessionExpired } from "../../lib/api";

export default function Login() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (consumeSessionExpired()) {
      setInfo("Session expired—please sign in again.");
    }
  }, []);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
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

  return (
    <div className="mx-auto mt-24 max-w-md rounded-2xl border p-6 shadow">
      <h1 className="mb-4 text-xl font-semibold">Sign in</h1>

      {info && <div className="mb-3 rounded-md bg-yellow-50 p-2 text-sm text-yellow-800">{info}</div>}

      <form onSubmit={onSubmit} className="space-y-4">
        <div>
          <label className="mb-1 block text-sm font-medium">Email</label>
          <input
            type="email"
            className="w-full rounded-md border px-3 py-2"
            value={email}
            onChange={(ev) => setEmail(ev.currentTarget.value)}
            required
            autoComplete="username"
          />
        </div>
        <div>
          <label className="mb-1 block text-sm font-medium">Password</label>
          <input
            type="password"
            className="w-full rounded-md border px-3 py-2"
            value={password}
            onChange={(ev) => setPassword(ev.currentTarget.value)}
            required
            autoComplete="current-password"
          />
        </div>
        {err && <div className="rounded-md bg-red-50 p-2 text-sm text-red-700">{err}</div>}
        <button
          type="submit"
          className="w-full rounded-md bg-black px-4 py-2 text-white disabled:opacity-50"
          disabled={busy}
        >
          {busy ? "Signing in..." : "Sign in"}
        </button>
      </form>
    </div>
  );
}
