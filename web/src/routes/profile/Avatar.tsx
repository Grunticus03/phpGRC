import { useEffect, useRef, useState } from "react";
import { apiPostFormData } from "../../lib/api";

type Meta = {
  original_name: string;
  mime: string;
  size_bytes: number;
  width: number;
  height: number;
  format: string;
};

type AvatarsDisabled = { code: "AVATARS_NOT_ENABLED" };
type AvatarTooLarge = { code: "AVATAR_TOO_LARGE"; limit: { max_width: number; max_height: number } };
type AvatarStub = { note: "stub-only"; file: Meta };
type AvatarOther = Record<string, unknown>;
type AvatarResponse = AvatarsDisabled | AvatarTooLarge | AvatarStub | AvatarOther;

function isDisabled(r: AvatarResponse): r is AvatarsDisabled {
  return (r as AvatarsDisabled).code === "AVATARS_NOT_ENABLED";
}
function isTooLarge(r: AvatarResponse): r is AvatarTooLarge {
  return (r as AvatarTooLarge).code === "AVATAR_TOO_LARGE";
}
function isStub(r: AvatarResponse): r is AvatarStub {
  return (r as AvatarStub).note === "stub-only" && !!(r as AvatarStub).file;
}

export default function ProfileAvatar(): JSX.Element {
  const [file, setFile] = useState<File | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const revokeRef = useRef<string | null>(null);

  useEffect(() => {
    if (revokeRef.current) {
      URL.revokeObjectURL(revokeRef.current);
      revokeRef.current = null;
    }
    if (file) {
      const url = URL.createObjectURL(file);
      revokeRef.current = url;
      setPreview(url);
    } else {
      setPreview(null);
    }
    return () => {
      if (revokeRef.current) URL.revokeObjectURL(revokeRef.current);
    };
  }, [file]);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setMsg(null);
    setMeta(null);
    if (!file) {
      setMsg("Choose an image first.");
      return;
    }
    const fd = new FormData();
    fd.append("file", file);
    try {
      const json = await apiPostFormData<AvatarResponse>("/api/avatar", fd);
      if (isDisabled(json)) {
        setMsg("Avatars feature disabled (stub).");
        return;
      }
      if (isTooLarge(json)) {
        setMsg(`Image too large. Max ${json.limit.max_width}x${json.limit.max_height}px.`);
        return;
      }
      if (isStub(json)) {
        setMeta(json.file);
        setMsg("Validated. Not stored (stub).");
        return;
      }
      setMsg("Request completed.");
    } catch {
      setMsg("Network error.");
    }
  }

  return (
    <div className="container py-3">
      <h1>Profile Avatar</h1>
      {msg && <div className="alert alert-info">{msg}</div>}

      <form onSubmit={onSubmit}>
        <div className="mb-3">
          <label className="form-label">Select image (WEBP/JPEG/PNG)</label>
          <input
            className="form-control"
            type="file"
            accept="image/webp,image/jpeg,image/png"
            onChange={(e) => setFile(e.currentTarget.files?.[0] ?? null)}
          />
        </div>
        <button className="btn btn-primary" type="submit">Validate (stub)</button>
      </form>

      {preview && (
        <div className="row mt-3">
          <div className="col-auto">
            <img src={preview} alt="preview" className="img-thumbnail" />
          </div>
        </div>
      )}

      {meta && (
        <div className="mt-3">
          <h2>Image Metadata</h2>
          <ul>
            <li>Name: {meta.original_name}</li>
            <li>MIME: {meta.mime}</li>
            <li>Size: {meta.size_bytes} bytes</li>
            <li>Dimensions: {meta.width}×{meta.height}</li>
            <li>Format: {meta.format}</li>
          </ul>
          <p className="text-muted">Phase 4 stub. No processing or storage yet.</p>
        </div>
      )}
    </div>
  );
}
