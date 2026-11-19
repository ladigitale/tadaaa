# Authenticating an agent with Tadaaa

Tadaaa's data API and MCP server live on **https://api.tadaaa.space**. The web app (app.tadaaa.space) needs no authentication for local-only use; cloud data requires an account.

## MCP endpoint

- URL: `https://api.tadaaa.space/mcp` (MCP streamable HTTP)
- Unauthenticated calls return `401` with `WWW-Authenticate: Bearer resource_metadata="https://api.tadaaa.space/.well-known/oauth-protected-resource"`.

## Option 1 — OAuth 2.1 (Claude.ai custom connector and other OAuth MCP clients)

- Protected resource metadata (RFC 9728): https://api.tadaaa.space/.well-known/oauth-protected-resource
- Authorization server metadata (RFC 8414): https://api.tadaaa.space/.well-known/oauth-authorization-server
- Authorization server / issuer: `https://api.tadaaa.space`
- Endpoints: `/oauth/authorize`, `/oauth/token`, `/oauth/register` (dynamic client registration), `/oauth/revoke`
- Grant: authorization code with PKCE (`S256`), refresh tokens
- Scopes: `mcp`, `offline_access`
- Tokens are per connection (`tdoa_…` access, `tdor_…` refresh) and can be revoked via `/oauth/revoke`.

In Claude.ai: add a custom connector with URL `https://api.tadaaa.space/mcp`, then **Connect** and sign in with your Tadaaa account.

## Option 2 — Personal access token (Cursor, scripts)

1. Create an account at https://app.tadaaa.space/account/register and confirm your email.
2. In the app, open **Connectivity → MCP** (https://app.tadaaa.space/connectivity/mcp) and create a token (`tada_…`).
3. Send it as `Authorization: Bearer tada_…` to `https://api.tadaaa.space/mcp`.

Treat tokens as secrets; rotate them from the same page.
