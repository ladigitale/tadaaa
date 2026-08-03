import {html} from "lit";
import "../../../../../components/tag-edit-page";

export default (params?: {id?: string}) => {
  return html`<tag-edit-page .tagId=${params?.id ?? ""}></tag-edit-page>`;
};
