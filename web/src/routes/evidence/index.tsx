import { useState } from "react";
import { apiPostFormData } from "../../lib/api";

type EvidenceStubFile = {
  original_name: string;
  mime: string;
  size_bytes: number;
};

type EvidenceUploadDisabled = { code: "EVIDENCE_NOT_ENABLED" };
type EvidenceUploadStub = { note: "stub-only"; file: EvidenceStubFile };
type EvidenceUploadOther = Record<string, unknown>;
type EvidenceUploadResponse = EvidenceUploadDisabled | EvidenceUploadStub | EvidenceUploadOther;

export default function EvidenceUpload(): JSX.Element {
  const [file, setFile] = useState<File | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [meta, setMeta] = useState<{ name: string; type: string; size: number } | null>(null);

  function isDisabled(r: EvidenceUploadResponse): r is EvidenceUploadDisabled {
    return (r as EvidenceUploadDisabled).code === "EVIDENCE_NOT_ENABLED";
  }

  function isStub(r: EvidenceUploadResponse): r is EvidenceUploadStub {
    return (r as EvidenceUploadStub).note === "stub-only" && !!(r as EvidenceUploadStub).file;
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setMsg(null);
    setMeta(null);
    if (!file) {
      setMsg("Choose a file first.");
      return;
    }
    const fd = new FormData();
    fd.append("file", file);
    try {
      const json = await apiPostFormData<EvidenceUploadResponse>("/evidence", fd);
      if (isDisabled(json)) {
        setMsg("Evidence feature disabled (stub).");
        return;
      }
      if (isStub(json)) {
        setMsg("Validated. Not stored (stub).");
        setMeta({
          name: json.file.original_name,
          type: json.file.mime,
          size: json.file.size_bytes,
        });
      } else {
        setMsg("Request completed.");
      }
    } catch {
      setMsg("Network error.");
    }
  }

  return (
    <div className="container py-3">
      <h1>Evidence Upload</h1>
      {msg && <div className="alert alert-info">{msg}</div>}
      <form onSubmit={onSubmit}>
        <div className="mb-3">
          <label className="form-label">File</label>
          <input
            className="form-control"
            type="file"
            onChange={(e) => setFile(e.currentTarget.files?.[0] ?? null)}
          />
        </div>
        <button className="btn btn-primary" type="submit">Validate (stub)</button>
      </form>

      {meta && (
        <div className="mt-3">
          <h2>File Metadata</h2>
          <ul>
            <li>Name: {meta.name}</li>
            <li>Type: {meta.type}</li>
            <li>Size: {meta.size} bytes</li>
          </ul>
          <p className="text-muted">Phase 4 stub. No storage yet.</p>
        </div>
      )}
    </div>
  );
}
