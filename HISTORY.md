# HISTORY.md — WSP WordPress MCP: Research, Decisions & Build Rationale

> This file is the **historical record** of how v2.0 was researched, decided, and built.
> It is kept so future agents and contributors don't re-research the same ground.
> For **current** plugin behavior, architecture, and tool reference — read **AGENTS.md**.
> For **what changed per version** — read **CHANGELOG.md**.

---

## MCP Transport Architecture — Research & Strategic Decision (2026-06-19)

This section captures a full investigation into how this plugin connects to AI clients,
the dependency it currently relies on, WordPress.org approval implications, and the
decided direction.

### The pre-v2.0 dependency chain

The pre-2.0 plugin **did not implement any MCP transport itself**. It only registered "abilities"
via `wp_register_ability()`. For those abilities to be reachable by an AI client, the site
needed a **three-package stack**, none of which is on the WordPress.org plugin directory:

| Package | Role | Status |
|---|---|---|
| `wordpress/abilities-api` | Provides `wp_register_ability()`, `wp_register_ability_category()`, `wp_abilities_api_init` — the functions this plugin called directly | pre-1.0, GPL-2.0, designed to merge into WP core |
| `wordpress/mcp-adapter` | Bridges the Abilities API to the MCP protocol (the transport/endpoint) | v0.5.0, `minimum-stability: dev`, PHP 7.4+, GPL-2.0-or-later, `Requires at least: 6.9` |
| `wordpress/php-mcp-schema` | mcp-adapter's transitive dependency | v0.1.0 |

Key facts established by inspecting the repos directly:
- `mcp-adapter`'s `composer.json` requires only `php-mcp-schema` — it does **not** declare a
  Composer dependency on `abilities-api`. The Abilities API is expected to be present separately
  (it is a core-track feature plugin, likely already partly in WP 6.9 core).
- **Neither `abilities-api` nor `mcp-adapter` is on the WordPress.org plugin directory** (verified
  via `api.wordpress.org/plugins/info`). This means the official `Requires Plugins:` header
  (WP 6.5+, the sanctioned way to declare a plugin dependency) **cannot** be used — it only
  resolves plugins hosted on the WP.org directory by slug.
- All three are GPL-2.0-or-later, so bundling them is license-safe.
- The client connection also required the user to run the
  `@automattic/mcp-wordpress-remote` npm bridge (needs Node.js) — a far bigger adoption barrier
  for non-technical users than "install a second plugin."

### The three paths considered

- **Path A — Keep the dependency, harden it.** Add a `function_exists('wp_register_ability')` /
  `class_exists('WP\\MCP\\Core\\McpAdapter')` guard so the plugin degrades gracefully (admin
  notice) instead of fataling when the adapter is absent. Low effort, stays on the official
  Abilities-API track. **But:** still needs the companion plugin + Node.js bridge, and is the
  **riskiest for WP.org approval** because the plugin is non-functional standalone and can't
  declare its GitHub-only dependency via the official header.
- **Path B — Bundle all three packages via Composer.** Single-plugin install, but you vendor
  three pre-1.0 dev-stability core-bound packages (collision risk when the Abilities API lands in
  core; ongoing re-bundling), **and you still need the npm bridge.** Worst effort-to-payoff. **Ruled out.**
- **Path C — Go native: implement MCP yourself, no dependency.** Own the REST transport,
  JSON-RPC dispatch, session store. Self-contained single plugin,
  direct Claude/ChatGPT connection via "Add Custom Connector" (no Node.js), cleanest WP.org
  submission. **Cost:** you own the protocol/client-quirk maintenance treadmill.

### Decisive evidence — two WP.org-approved precedents both chose native (Path C)

Two independently-built, WordPress.org-approved MCP plugins were studied as references.
**Both rejected `mcp-adapter`/Abilities-API and built native MCP servers.**

#### Reference Plugin A (PHP 7.4+, WP 5.8+)
- Self-contained: hand-rolled `spl_autoload_register`, own namespace, no Composer.
- Transport: `register_rest_route('<ns>/v1', '/mcp', …)` with `permission_callback => '__return_true'`,
  auth enforced **inside** the handler (`Server::validate_auth`). Streamable HTTP (2025-11-25 spec),
  JSON-RPC dispatch (`initialize`, `tools/list`, `tools/call`, `ping`).
- Native **OAuth 2.0 server** via rewrite rules at domain root (`.well-known/oauth-protected-resource`,
  `.well-known/oauth-authorization-server`, `/authorize`, `/token`, `/register` + PKCE).
- Tools: one big inline array in `get_tools()` + `execute_tool()` switch; integrations (Woo, Elementor, ACF…) add tools conditionally.
- Dual auth: API key (custom header or `Authorization: Bearer`) OR OAuth bearer token; DB-backed sessions; rate limiting; Origin validation (DNS-rebinding guard).
- Hard-won production lessons in its comments: Cache-Control `no-store` on every response (edge-cache
  poisoning on Cloudflare/LiteSpeed), per-User-Agent dispatch on `GET /mcp` (4 iterations to satisfy
  Claude.ai/ChatGPT/mcp-remote), DB-backed sessions (object-cache eviction), per-tool capability
  checks (CVSS 8.1 fix).

#### Reference Plugin B (PHP 8.0+, WP 6.2+) — the cleanest template
- Self-contained: `require_once` of class files + **one file per tool** under
  `includes/tools/wp/` and `includes/tools/bridge/`. No Composer/vendor.
- Transport: own REST endpoint `/wp-json/<ns>/v1/message` (Streamable HTTP + JSON-RPC).
  One HTTP request per call — explicitly "works on shared hosting" (no long-lived SSE).
- Built-in **OAuth 2.0 server** (`/oauth/authorize`, `/token`, `/register`, PKCE) → connects
  directly via Claude's "Add Custom Connector" by pasting the site URL. **No Node.js bridge** for
  that path (a `mcp-remote` stdio snippet is still offered for Claude-Desktop config-file users).
- **Three auth paths:** Application Password, plugin-generated Bearer key, and OAuth token.
- Tool registration is trivially modular — the exact pattern copied for this plugin:
  ```php
  <Server>::register_tool(
      'get_site_info',
      [ 'description' => '…', 'inputSchema' => [ 'type'=>'object', 'properties'=>new stdClass() ] ],
      function ( array $args ): array|WP_Error {
          return <Server>::ok([ /* … */ ]);
      }
  );
  ```
- **Freemium model proven on WP.org:** ~45 tools work standalone; an optional API key unlocks
  ~12 more "bridge" tools. The external API is disclosed in an **"External Services"** readme section
  and the plugin is **fully functional without it** — the reason WP.org approved it.
- Other WP.org-compliance patterns worth copying: SSRF guards on URL media ingestion (reject
  private/loopback/cloud-metadata IPs, re-validate redirects), MIME verified against real bytes,
  filename denylist (`.php/.phtml/.htaccess`), credential-key denylist on post-meta writes,
  `require_cap()` capability enforcement, dbDelta-idempotent migrations gated by a `db_version`
  option, change-receipt + rollback audit trail.

### DECISION (2026-06-19): Go native (Path C), model on Reference Plugin B

**Rationale:**
1. **WP.org approval** — the two approved precedents are both native and self-functional. A plugin
   that "requires a GitHub-only companion plugin" is the riskiest path (non-functional standalone,
   no valid `Requires Plugins` header). Native is the proven-approvable architecture.
2. **Feature velocity** — the one-file-per-tool registry is the same modular structure already
   preferred here, and the per-feature cost is tiny.
3. **End-user UX** — native + OAuth means users paste a URL into Claude/ChatGPT and install
   **nothing else** (no second plugin, no Node.js). This is the biggest adoption win, especially
   for a non-developer audience.
4. The current dependency stack is pre-1.0, dev-stability, and core-bound — building on a moving
   target headed for core is the wrong long-term bet.

**The rewrite is of the transport layer, not the business logic.** The existing ability *logic*
(the `wsp_execute_*` functions) is reusable — the change is swapping "register with mcp-adapter
via `wp_register_ability()`" for "register with our own MCP server + tool registry."

---

## v2.0 Native Build — Milestone Plan (2026-06-19)

Eight milestones, dependency-ordered, each independently testable. The existing `wsp_execute_*`
ability logic (~1,222 lines) is reused; the rewrite is of the transport layer only.

| # | Milestone | Depends on | Status |
|---|---|---|---|
| **M0** | Interim v1.x safety guard (`function_exists` + admin notice) | — | **DONE** |
| **M1** | MCP transport core (REST route, JSON-RPC dispatch, rate limiting, CORS) | M0 | **DONE** |
| **M2** | Tool registry + port existing `wsp_execute_*` logic | M1 | **DONE** |
| **M3** | DB-backed session store (`Mcp-Session-Id`, fingerprint, cron cleanup) | M1 | **DONE** |
| **M4** | Auth completion (API key, Bearer, App Password, per-tool caps) | M1 | **DONE** |
| **M5** | OAuth 2.0 server (authorize/token/register + PKCE) | M1, M4 | **Deferred → v2.1** |
| **M6** | Admin/connection UX (Connection page, per-client tabs) | M2, M4 | **DONE** |
| **M7** | WP.org hardening + release (Plugin Check, readme.txt, uninstall) | all | **DONE** |

### Critical path

```
M0 ─► M1 ─┬─► M2 ──────────┐
           ├─► M3           ├─► M6 ─► M7 ─► (v2.0 MVP)
           └─► M4 ─► M5 ────┘         └─► (v2.1 adds M5)
```

### Shipping options chosen

- **MVP (no OAuth) shipped as v2.0** — M0–M4 + M6 + M7. Connects via Claude Desktop config + API key / App Password.
- **Full (URL-paste UX) as v2.1** — adds M5 (OAuth is the biggest/riskiest chunk and not required for a working release).

### No-breakage guarantee (dual-mode) — superseded in v2.2

v2.0 kept `registry.php`'s `wp_register_ability()` calls **behind a `function_exists` guard** AND
registered the native endpoint, so existing mcp-adapter users kept their connection while new users
got the native one-plugin path. No bundled adapter → no class collision → no fatal.

**v2.2.0 removed the dual-mode path entirely.** Ahead of WordPress.org submission, all
`wp_register_ability()` registrations, the `wp_abilities_api_*` hooks, `wsp_register_ability_category()`,
and the MCP > Config Files page (mcp-adapter / `@automattic/mcp-wordpress-remote` snippets) were
deleted. Rationale: WP.org review disfavours references to off-directory packages, the native server
had been the only recommended transport since v2.0, and carrying a second dead code path raised the
review surface for no user benefit. Cost: connections made before v2.0 through the MCP Adapter must
be re-created against the native endpoint (one-time, documented in the v2.2.0 changelog). The
`wsp_execute_*()` business logic was untouched — only the registration layer was removed.

### MCP spec version note

The protocol moves ~quarterly (`2024-11-05 → 2025-03-26 → 2025-06-18 → 2025-11-25`). A native
server must echo the client's requested `protocolVersion` when recognized and fall back to a
known-good default otherwise. This maintenance is the main cost of going native, but both
precedents show it is sustainable.
