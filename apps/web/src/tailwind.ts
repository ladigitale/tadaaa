import { css, unsafeCSS } from "lit";
import tailwindImport from "./css/tailwind.css?inline";

const tailwind = css`
  ${unsafeCSS(tailwindImport)}
`;

export default tailwind;