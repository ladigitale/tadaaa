import {css, unsafeCSS} from "lit";
import tailwindImport from "./tailwind.css?inline";

const tailwind = css`
  ${unsafeCSS(tailwindImport)}
`;

export default tailwind;
