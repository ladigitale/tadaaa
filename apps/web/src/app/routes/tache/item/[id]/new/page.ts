import {html} from "lit";
import "../../../../../components/todo-create-page";

export default function TodoItemNewRoutePage(params?: Record<string, string>) {
  return html`<todo-create-page
    .parentId=${params?.id ?? ""}
  ></todo-create-page>`;
}
