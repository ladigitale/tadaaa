import {html} from "lit";

/** Optical lockup (~old size-8 vs 1.75rem); inline for Lit shadow roots. */
const BRAND_MARK_STYLE = "width:1.1em;height:1.1em;display:block";

/**
 * Tadaaa mark: outlined circle, check + sparks.
 * Disc/ring = same as title typo (currentColor / text-content). No gradients.
 */
export function tadaaaLogoMark(
  className = "size-8 shrink-0",
  style?: string,
) {
  return html`
    <svg
      class=${className}
      style=${style ?? ""}
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 40 40"
      aria-hidden="true"
      fill="none"
    >
      <circle
        cx="20"
        cy="20"
        r="18.25"
        stroke="currentColor"
        stroke-width="1.75"
      />
      <circle cx="20" cy="20" r="14.4" fill="currentColor" />
      <!-- Check + sparks: optical center on logo (20,20); NE bias corrected -->
      <g
        transform="translate(-2 3)"
        stroke="var(--sc-base, #fafaf9)"
        fill="var(--sc-base, #fafaf9)"
      >
        <path
          d="M13.4 21.2 16.6 24.3 24.2 16.5"
          fill="none"
          stroke-width="2.35"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
        <circle cx="24.2" cy="9.2" r="2.35" />
        <circle cx="28.8" cy="12.4" r="1.85" />
        <circle cx="31.6" cy="17.2" r="1.35" />
      </g>
    </svg>
  `;
}

/** Nav / wordmark lockup — mark scales with type (cap-height), baseline-aligned. */
export function tadaaaBrand(options?: {
  size?: "sm" | "lg" | "hero";
  className?: string;
}) {
  const size = options?.size ?? "sm";
  const sizeClass =
    size === "hero"
      ? "gap-[0.28em] text-5xl sm:text-6xl"
      : size === "lg"
        ? "gap-[0.28em] text-3xl"
        : "gap-[0.28em] text-[1.75rem]";

  return html`
    <span
      class="inline-flex items-end font-headings font-bold leading-none tracking-tight text-content ${sizeClass} ${options?.className ??
      ""}"
    >
      ${tadaaaLogoMark("mb-[0.06em] shrink-0", BRAND_MARK_STYLE)}
      <span>Tadaaa</span>
    </span>
  `;
}
