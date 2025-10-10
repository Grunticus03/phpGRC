const EXACT_LABELS: Record<string, string> = {
  "application/pdf": "PDF document",
  "application/msword": "Microsoft Word document",
  "application/vnd.ms-word": "Microsoft Word document",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document": "Microsoft Word document",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.template": "Microsoft Word template",
  "application/rtf": "Rich Text document",
  "application/vnd.ms-excel": "Microsoft Excel spreadsheet",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": "Microsoft Excel spreadsheet",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.template": "Microsoft Excel template",
  "application/vnd.ms-powerpoint": "Microsoft PowerPoint presentation",
  "application/vnd.openxmlformats-officedocument.presentationml.presentation": "Microsoft PowerPoint presentation",
  "application/vnd.openxmlformats-officedocument.presentationml.slideshow": "Microsoft PowerPoint slideshow",
  "application/vnd.apple.keynote": "Apple Keynote presentation",
  "application/vnd.apple.numbers": "Apple Numbers spreadsheet",
  "application/vnd.apple.pages": "Apple Pages document",
  "application/vnd.android.package-archive": "Android APK package",
  "application/x-msdownload": "Windows executable",
  "application/x-ms-installer": "Windows installer package",
  "application/x-msi": "Windows installer package",
  "application/x-sh": "Shell script",
  "application/x-python-code": "Python script",
  "application/javascript": "JavaScript file",
  "text/javascript": "JavaScript file",
  "application/json": "JSON data",
  "application/xml": "XML document",
  "text/xml": "XML document",
  "text/html": "HTML document",
  "text/markdown": "Markdown document",
  "text/css": "CSS stylesheet",
  "text/csv": "CSV file",
  "text/plain": "Plain text",
  "application/zip": "ZIP archive",
  "application/x-zip-compressed": "ZIP archive",
  "application/x-7z-compressed": "7-Zip archive",
  "application/x-rar-compressed": "RAR archive",
  "application/vnd.rar": "RAR archive",
  "application/x-tar": "TAR archive",
  "application/gzip": "GZIP archive",
  "application/x-bzip": "BZIP archive",
  "application/x-bzip2": "BZIP2 archive",
  "application/x-iso9660-image": "ISO disk image",
  "application/octet-stream": "Binary file",
  "application/sql": "SQL script",
  "image/jpeg": "JPEG image",
  "image/jpg": "JPEG image",
  "image/png": "PNG image",
  "image/gif": "GIF image",
  "image/webp": "WEBP image",
  "image/bmp": "Bitmap image",
  "image/tiff": "TIFF image",
  "image/svg+xml": "SVG image",
  "image/heic": "HEIC image",
  "audio/mpeg": "MP3 audio",
  "audio/mp3": "MP3 audio",
  "audio/wav": "WAV audio",
  "audio/x-wav": "WAV audio",
  "audio/ogg": "Ogg audio",
  "audio/flac": "FLAC audio",
  "audio/aac": "AAC audio",
  "audio/webm": "WebM audio",
  "video/mp4": "MP4 video",
  "video/mpeg": "MPEG video",
  "video/webm": "WebM video",
  "video/quicktime": "QuickTime video",
  "video/x-msvideo": "AVI video",
  "video/x-matroska": "Matroska video",
  "video/x-ms-wmv": "WMV video",
};

const PATTERN_LABELS: Array<{ pattern: RegExp; label: string }> = [
  { pattern: /^application\/vnd\.openxmlformats-officedocument\.wordprocessingml\./, label: "Microsoft Word document" },
  { pattern: /^application\/vnd\.openxmlformats-officedocument\.spreadsheetml\./, label: "Microsoft Excel spreadsheet" },
  { pattern: /^application\/vnd\.openxmlformats-officedocument\.presentationml\./, label: "Microsoft PowerPoint presentation" },
  { pattern: /^application\/vnd\.ms-powerpoint\./, label: "Microsoft PowerPoint presentation" },
  { pattern: /^application\/vnd\.ms-excel\./, label: "Microsoft Excel spreadsheet" },
  { pattern: /^application\/vnd\.google-apps\.document$/, label: "Google Docs document" },
  { pattern: /^application\/vnd\.google-apps\.presentation$/, label: "Google Slides presentation" },
  { pattern: /^application\/vnd\.google-apps\.spreadsheet$/, label: "Google Sheets spreadsheet" },
];

const TYPE_DEFAULTS: Record<string, string> = {
  image: "Image",
  audio: "Audio",
  video: "Video",
  text: "Text document",
  application: "Application file",
  font: "Font file",
  model: "3D model",
  message: "Email message",
};

function capitalizeWords(value: string): string {
  return value
    .split(/[^a-zA-Z0-9]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function computeLabel(rawMime: string): string {
  const normalized = rawMime.trim().toLowerCase();
  if (!normalized) return "Unknown";

  const exact = EXACT_LABELS[normalized];
  if (exact) return exact;

  for (const { pattern, label } of PATTERN_LABELS) {
    if (pattern.test(normalized)) {
      return label;
    }
  }

  const parts = normalized.split("/");
  if (parts.length !== 2) {
    return rawMime;
  }

  const [type, subtypeRaw] = parts;
  const typeDefault = TYPE_DEFAULTS[type];

  const cleanedSubtype = subtypeRaw
    .replace(/^vnd\./, "")
    .replace(/\+xml$/, " XML")
    .replace(/\+json$/, " JSON")
    .replace(/\+zip$/, " ZIP")
    .replace(/\+octet-stream$/, " binary")
    .replace(/[._-]+/g, " ")
    .trim();

  if (cleanedSubtype.length === 0) {
    return typeDefault ?? rawMime;
  }

  const subtypeLabel = capitalizeWords(cleanedSubtype);

  if (typeDefault) {
    return `${subtypeLabel} ${typeDefault.toLowerCase()}`;
  }

  return subtypeLabel;
}

const MIME_LABEL_CACHE = new Map<string, string>();

export function describeMime(rawMime: string): string {
  const key = rawMime ?? "";
  if (MIME_LABEL_CACHE.has(key)) {
    return MIME_LABEL_CACHE.get(key) as string;
  }

  const label = computeLabel(rawMime);
  MIME_LABEL_CACHE.set(key, label);

  return label;
}

export function summarizeMimeList(mimes: string[]): string[] {
  return mimes.map((mime) => describeMime(mime));
}
