import {html} from "lit";
import "../../../../../components/todo-edit-page";

export default function TodoEditRoutePage(
  params?: Record<string, string>,
) {
  return html`<todo-edit-page .todoId=${params?.id ?? ""}></todo-edit-page>`;
}
