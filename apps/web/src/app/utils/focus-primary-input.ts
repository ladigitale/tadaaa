import type {LitElement} from "lit";

type FocusableHost = Pick<LitElement, "updateComplete" | "renderRoot">;

const DEFAULT_SELECTOR =
  'sonic-input:not([type="date"]):not([type="hidden"])';

/**
 * Focus the most useful text field on a form page (first non-date sonic-input).
 * Digs into the shadow root so the caret lands in the native input.
 */
export async function focusPrimaryInput(
  host: FocusableHost,
  selector = DEFAULT_SELECTOR,
): Promise<void> {
  await host.updateComplete;

  const el = host.renderRoot.querySelector(selector);
  if (!el) return;

  const updatable = el as HTMLElement & {updateComplete?: Promise<unknown>};
  if (updatable.updateComplete) {
    await updatable.updateComplete;
  }

  const inner =
    updatable.shadowRoot?.querySelector<HTMLInputElement | HTMLTextAreaElement>(
      "input:not([type=hidden]), textarea",
    ) ?? null;

  const target = inner ?? updatable;
  if (typeof target.focus !== "function") return;

  // Defer one frame so SPA route paint / modal show don't steal focus back.
  requestAnimationFrame(() => {
    target.focus();
  });
}
