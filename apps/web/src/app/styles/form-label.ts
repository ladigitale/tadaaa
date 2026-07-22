import {css} from "lit";

/**
 * Miroir du module `label` Concorde (`form-control.ts`) :
 * mêmes défauts de taille / graisse / espacement label → contrôle.
 */
export const formLabelStyles = css`
  :host {
    --sc-label-fs: var(--sc-_fs, 1rem);
    --sc-label-fw: var(--sc-label-font-weight, 500);
  }

  .form-label {
    display: block;
    margin-bottom: 0.22em;
    font-size: var(--sc-label-fs);
    font-weight: var(--sc-label-fw);
    line-height: 1.2;
    color: var(--sc-base-content, currentColor);
  }

  .form-field {
    display: flex;
    flex-direction: column;
    align-items: stretch;
  }

  .form-field-control {
    min-width: 0;
  }
`;
