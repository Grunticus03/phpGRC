import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import "./ToastProvider.css";

export type ToastTone = "success" | "info" | "warning" | "danger";

type ToastOptions = {
  message: string;
  tone?: ToastTone;
  durationMs?: number;
  id?: string;
};

type ToastState = {
  id: string;
  message: string;
  tone: ToastTone;
  dismissible: boolean;
  closing: boolean;
};

type ToastContextValue = {
  pushToast: (options: ToastOptions) => string;
  dismissToast: (id: string) => void;
  success: (message: string, options?: Omit<ToastOptions, "message" | "tone">) => string;
  info: (message: string, options?: Omit<ToastOptions, "message" | "tone">) => string;
  warning: (message: string, options?: Omit<ToastOptions, "message" | "tone">) => string;
  danger: (message: string, options?: Omit<ToastOptions, "message" | "tone">) => string;
};

const ToastContext = createContext<ToastContextValue | null>(null);

const DEFAULT_AUTO_DURATION = 4500;
const EXIT_DURATION = 220;

const createToastId = (): string => {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
};

export function ToastProvider({ children }: { children: ReactNode }): JSX.Element {
  const [toasts, setToasts] = useState<ToastState[]>([]);
  const timersRef = useRef(new Map<string, number>());

  const removeToast = useCallback((id: string) => {
    setToasts((prev) => prev.filter((toast) => toast.id !== id));
    const timer = timersRef.current.get(id);
    if (timer !== undefined) {
      window.clearTimeout(timer);
      timersRef.current.delete(id);
    }
  }, []);

  useEffect(() => {
    const timers = timersRef.current;
    return () => {
      timers.forEach((timer) => window.clearTimeout(timer));
      timers.clear();
    };
  }, []);

  const beginDismiss = useCallback(
    (id: string) => {
      setToasts((prev) =>
        prev.map((toast) => (toast.id === id ? { ...toast, closing: true } : toast))
      );
      window.setTimeout(() => removeToast(id), EXIT_DURATION);
    },
    [removeToast]
  );

  const pushToastInternal = useCallback(
    ({ message, tone = "info", durationMs, id: providedId }: ToastOptions): string => {
      const id = providedId ?? createToastId();
      const dismissible = true;
      const autoDuration =
        durationMs ??
        (tone === "success" || tone === "info"
          ? DEFAULT_AUTO_DURATION
          : 0);

      setToasts((prev) => [...prev, { id, message, tone, dismissible, closing: false }]);

      if (autoDuration > 0) {
        const timer = window.setTimeout(() => beginDismiss(id), autoDuration);
        timersRef.current.set(id, timer);
      }

      return id;
    },
    [beginDismiss]
  );

  const dismissToast = useCallback(
    (id: string) => {
      if (!toasts.some((toast) => toast.id === id)) {
        return;
      }
      beginDismiss(id);
    },
    [beginDismiss, toasts]
  );

  const contextValue = useMemo<ToastContextValue>(
    () => ({
      pushToast: pushToastInternal,
      dismissToast,
      success: (message, options) =>
        pushToastInternal({ message, tone: "success", ...options }),
      info: (message, options) => pushToastInternal({ message, tone: "info", ...options }),
      warning: (message, options) =>
        pushToastInternal({ message, tone: "warning", ...options }),
      danger: (message, options) => pushToastInternal({ message, tone: "danger", ...options }),
    }),
    [dismissToast, pushToastInternal]
  );

  return (
    <ToastContext.Provider value={contextValue}>
      {children}
      <ToastViewport
        toasts={toasts}
        onDismiss={dismissToast}
      />
    </ToastContext.Provider>
  );
}

type ToastViewportProps = {
  toasts: ToastState[];
  onDismiss: (id: string) => void;
};

function ToastViewport({ toasts, onDismiss }: ToastViewportProps): JSX.Element | null {
  if (typeof document === "undefined") {
    return null;
  }

  return createPortal(
    <div className="app-toast-viewport" role="region" aria-live="polite" aria-label="Notifications">
      {toasts.map((toast) => (
        <ToastItem key={toast.id} toast={toast} onDismiss={onDismiss} />
      ))}
    </div>,
    document.body
  );
}

type ToastItemProps = {
  toast: ToastState;
  onDismiss: (id: string) => void;
};

function ToastItem({ toast, onDismiss }: ToastItemProps): JSX.Element {
  const { id, tone, dismissible, message, closing } = toast;
  const className = `app-toast alert alert-${tone}`;
  const role = tone === "danger" || tone === "warning" ? "alert" : "status";

  return (
    <div
      className={className}
      role={role}
      data-visible={!closing}
    >
      <div className="app-toast-body">{message}</div>
      {dismissible ? (
        <button
          type="button"
          className="btn-close ms-2"
          aria-label="Close notification"
          onClick={() => onDismiss(id)}
        />
      ) : null}
    </div>
  );
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (!ctx) {
    throw new Error("useToast must be used within a ToastProvider");
  }
  return ctx;
}
