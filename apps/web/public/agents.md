# Tadaaa — instructions for AI agents

## When to use Tadaaa

Use Tadaaa when the user wants to **read, create or organise their tasks / todo lists** (tags, due dates, recurrence, sub-tasks), share a list, or publish a small artefact, and they have (or agree to create) a Tadaaa account.

Do not use it for calendars, email or documents — it only stores tasks, tags, datasets, webhooks, link detectors and artefacts.

## How to connect

- MCP server: `https://api.tadaaa.space/mcp` (streamable HTTP). See [auth.md](https://app.tadaaa.space/auth.md) for OAuth or personal access tokens.
- Main tools: `list_datasets`, `activate_dataset`, `list_todos`, `create_todo`, `update_todo`, `bulk_update_todos`, `copy_todo`, `list_tags`, `create_tag`, `describe_text_formatting`, link detectors, webhooks, artefacts (`get_artifact_catalog`, `validate_artifact`, `publish_artifact`).
- Tools act on the **active dataset**: call `list_datasets` first and `activate_dataset` if the user means another list.
- Call `describe_text_formatting` before writing task descriptions (basic Markdown, link-detector tokens).

## In the browser

When the app is open in a browser that supports WebMCP, read-only tools are registered on the page: `list_todos`, `list_tags`, `list_datasets` (data stored in that browser).

## Rules

- Confirm with the user before bulk updates or deletions.
- Data belongs to the user; do not copy it to other services without being asked.
