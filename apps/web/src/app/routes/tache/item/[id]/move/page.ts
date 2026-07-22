import {html} from "lit";
import "../../../../../components/todo-move-page";

export default (params?: {id?: string}) => {
  return html`<todo-move-page .todoId=${params?.id ?? ""}></todo-move-page>`;
};
