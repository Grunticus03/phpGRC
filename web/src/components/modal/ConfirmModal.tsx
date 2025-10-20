import { useEffect, useId, useRef, type ReactNode } from "react";

type ConfirmModalProps = {
  open: boolean;
  title: string;
  children?: ReactNode;
  busy?: boolean;
  confirmLabel?: string;
  cancelLabel?: string;
  confirmTone?: "primary" | "danger" | "secondary";
  onConfirm: () => void;
  onCancel: () => void;
  disableBackdropClose?: boolean;
  confirmDisabled?: boolean;
  initialFocus?: "confirm" | "none";
  hideCancelButton?: boolean;
  dialogClassName?: string;
  contentClassName?: string;
  bodyClassName?: string;
  footerClassName?: string;
  footerStart?: ReactNode;
};

export default function ConfirmModal({
  open,
  title,
  children,
  busy = false,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  confirmTone = "primary",
  onConfirm,
  onCancel,
  disableBackdropClose = false,
  confirmDisabled = false,
  initialFocus = "confirm",
  hideCancelButton = false,
  dialogClassName,
  contentClassName,
  bodyClassName,
  footerClassName,
  footerStart,
}: ConfirmModalProps): JSX.Element | null {
  const confirmButtonRef = useRef<HTMLButtonElement | null>(null);
  const titleId = useId();
  const bodyId = useId();

  useEffect(() => {
    if (!open || initialFocus !== "confirm") return;
    const timer = window.setTimeout(() => {
      confirmButtonRef.current?.focus();
    }, 0);
    return () => {
      window.clearTimeout(timer);
    };
  }, [open, busy, initialFocus]);

  if (!open) {
    return null;
  }

  const confirmClass =
    confirmTone === "danger"
      ? "btn btn-danger"
      : confirmTone === "secondary"
      ? "btn btn-secondary"
      : "btn btn-primary";

  return (
    <>
      <div
        className="modal-backdrop fade show"
        onClick={() => {
          if (!busy && !disableBackdropClose) {
            onCancel();
          }
        }}
      />
      <div
        className="modal fade show d-block"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={children ? bodyId : undefined}
        tabIndex={-1}
        onKeyDown={(event) => {
          if (event.key === "Escape" && !busy) {
            event.preventDefault();
            onCancel();
          }
        }}
      >
        <div className={dialogClassName ?? "modal-dialog modal-dialog-centered"}>
          <div className={contentClassName ?? "modal-content shadow"}>
            <div className="modal-header">
              <h2 className="modal-title fs-5" id={titleId}>
                {title}
              </h2>
              <button
                type="button"
                className="btn-close"
                aria-label="Close"
                onClick={onCancel}
                disabled={busy}
              />
            </div>
            <div className={bodyClassName ?? "modal-body"} id={children ? bodyId : undefined}>
              {children}
            </div>
            <div className={footerClassName ?? "modal-footer"}>
              {footerStart ? <div className="me-auto">{footerStart}</div> : null}
              {!hideCancelButton && (
                <button type="button" className="btn btn-outline-secondary" onClick={onCancel} disabled={busy}>
                  {cancelLabel}
                </button>
              )}
              <button
                type="button"
                className={confirmClass}
                onClick={onConfirm}
                disabled={busy || confirmDisabled}
                ref={confirmButtonRef}
              >
                {confirmLabel}
              </button>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
