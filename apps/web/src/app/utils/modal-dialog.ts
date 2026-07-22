import "@supersoniks/concorde/modal";
import "@supersoniks/concorde/modal-title";
import "@supersoniks/concorde/modal-content";
import "@supersoniks/concorde/modal-actions";
import "@supersoniks/concorde/button";
import {Modal} from "@supersoniks/concorde/modal";
import {html} from "lit";

function toErrorMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  if (typeof error === "string") return error;
  return "Une erreur est survenue.";
}

/**
 * Les actions de Modal.create sont rendues dans le shadowRoot,
 * et seulement après show() (contenu absent tant que hidden).
 */
function bindModalActionClicks(
  modal: Modal,
  handlers: Record<string, () => void>,
): void {
  const attach = () => {
    const actions = modal.shadowRoot?.querySelector("sonic-modal-actions");
    if (!actions) return;

    for (const [action, handler] of Object.entries(handlers)) {
      const button = actions.querySelector(`[data-action="${action}"]`);
      button?.addEventListener("click", handler);
    }
  };

  modal.addEventListener(
    "show",
    () => {
      void modal.updateComplete.then(attach);
    },
    {once: true},
  );
}

export function showAlert(title: string, message: string): Promise<void> {
  return new Promise((resolve) => {
    const modal = Modal.create({
      title,
      content: message,
      removeOnHide: true,
      actions: html`
        <sonic-button hideModal type="primary" data-action="ok">OK</sonic-button>
      `,
    });

    bindModalActionClicks(modal, {ok: () => resolve()});
    modal.addEventListener("hidden", () => resolve(), {once: true});
  });
}

export function showError(error: unknown, title = "Erreur"): Promise<void> {
  return showAlert(title, toErrorMessage(error));
}

export function confirmDialog(options: {
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
}): Promise<boolean> {
  return new Promise((resolve) => {
    let settled = false;
    /** Mis à true au clic Confirmer (avant la fin de l’anim hide). */
    let confirmed = false;

    const finish = (value: boolean) => {
      if (settled) return;
      settled = true;
      resolve(value);
    };

    const modal = Modal.create({
      title: options.title,
      content: options.message,
      removeOnHide: true,
      actions: html`
        <sonic-button hideModal data-action="cancel">
          ${options.cancelLabel ?? "Annuler"}
        </sonic-button>
        <sonic-button
          hideModal
          type=${options.danger ? "danger" : "primary"}
          data-action="confirm"
        >
          ${options.confirmLabel ?? "Confirmer"}
        </sonic-button>
      `,
    });

    bindModalActionClicks(modal, {
      cancel: () => {
        confirmed = false;
      },
      confirm: () => {
        confirmed = true;
      },
    });

    modal.addEventListener(
      "hidden",
      () => finish(confirmed),
      {once: true},
    );
  });
}
