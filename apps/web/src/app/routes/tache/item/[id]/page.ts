import {html} from "lit";
import "../../../../components/todo-app";

export default function TodoItemRoutePage(params?: Record<string, string>) {
  return html`<todo-app .parentId=${params?.id ?? ""}></todo-app>`;
}
