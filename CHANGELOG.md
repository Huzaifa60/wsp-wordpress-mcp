# Changelog — WebSensePro MCP Abilities

> **AI Agents:** Read `AGENTS.md` → `CHANGELOG.md` → `HISTORY.md` in that order before touching any source file.
> After every code change you **must** update `AGENTS.md` (if architecture/tools/hooks changed) and add an entry here.

All notable changes to this plugin are listed here. Ordered newest-first.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [2.0.0] — 2026-06-20

### Summary
The plugin is now fully self-contained. It ships its own native MCP server (a WordPress REST endpoint) and no longer requires a companion plugin or Node.js bridge to connect to AI clients.

### Added
- **Native MCP server** — REST endpoint `/wp-json/wsp-mcp/v1/mcp` (Streamable HTTP + JSON-RPC 2.0).
  - Rationale: WP.org cannot express a dependency on a GitHub-only plugin via `Requires Plugins:`; a self-contained plugin is the proven-approvable architecture (two WP.org-approved precedents both went native).
  - Handles: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`, `ping`, empty `resources/list` / `prompts/list`.
  - Protocol versions supported: `2024-11-05`, `2025-03-26`, `2025-06-18`, `2025-11-25`.
- **DB-backed session store** — table `{prefix}wsp_mcp_sessions`, fingerprint-bound, 24-hour sliding expiry, daily cron cleanup (`wsp_mcp_session_cleanup`).
- **Three auth paths** — plugin-generated API key (Bearer or `X-WSP-MCP-API-Key` header), WordPress Application Password (HTTP Basic). OAuth 2.0 deferred to v2.1.
- **MCP > Connection admin page** — shows the native endpoint URL and API key; one-click Regenerate; tabbed ready-to-paste config snippets for Claude Desktop, Cursor, Codex, Antigravity, and OpenClaw (API key hardcoded inline — avoids the `mcp-remote` "missing env var" failure).
- **Accordion Settings UI** — MCP > Settings groups abilities into collapsible sections; open/closed state persists in `localStorage`; live count badge per group.
- **Tool registry hook** — `do_action('wsp_mcp_register_tools', …)` so add-ons can register extra tools.
- `uninstall.php` drops the sessions table and all `wsp_mcp_*` options on plugin deletion.
- `readme.txt` for WordPress.org submission.

### Changed
- All existing `wsp_execute_*` callback logic is **unchanged** — only the transport changed from "register with mcp-adapter" to "register with the native tool registry" (`includes/tools/native-tools.php`).
- `dependency.php` repurposed: `wsp_mcp_abilities_api_available()` now gates dual-mode only; the native transport is always available.
- MCP > Config Files page now shows a deprecation notice pointing to MCP > Connection.

### Breaking changes
- None for end users. Pre-2.0 connections via the mcp-adapter keep working (dual-mode preserved).

### Migration (upgrading from v1.x)
- Existing mcp-adapter connections remain valid — the Abilities API path stays behind a `function_exists('wp_register_ability')` guard.
- New installs: use **MCP > Connection** to copy the native endpoint URL and API key.
- Claude Desktop users need the `npx -y mcp-remote` bridge (Claude Desktop config files don't support remote HTTP directly). Cursor, Codex, Antigravity support native remote HTTP natively.
- After enabling or adding tools, **fully reconnect the client** (restart Claude Desktop, not just open a new chat) — MCP clients cache `tools/list` at connect time.

### Files added
`includes/server/class-mcp-server.php`, `includes/server/class-session-store.php`,
`includes/server/class-auth.php`, `includes/tools/native-tools.php`,
`includes/admin/connection-page.php`, `readme.txt`, `LICENSE`

---

## [1.3.0] — 2026-06-19

### Summary
Adds Yoast SEO read/write abilities so AI clients can inspect and update SEO metadata on posts and pages.

### Added
- **`wsp/yoast-get-seo`** — returns SEO title, meta description, and focus keyphrase for a post or page. Requires `edit_posts`. OFF by default.
- **`wsp/yoast-update-seo`** — updates any combination of SEO title, meta description, and focus keyphrase. Rebuilds the Yoast indexable after saving. Requires `edit_posts`. OFF by default.
- Both abilities are gated on Yoast being active (`defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')`); they are silently absent when Yoast is not installed.
- Helper layer in `includes/abilities/yoast.php`: `wsp_yoast_is_active()`, `wsp_yoast_get_meta()`, `wsp_yoast_set_meta()`, `wsp_yoast_rebuild_indexable()`, `wsp_yoast_validate_post()`, `wsp_yoast_format_seo_data()`.
- Falls back to direct post-meta keys (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`) when `WPSEO_Meta` class is unavailable.

### Changed
- `registry.php` — two new entries added to `wsp_mcp_ability_registry()` under the "Yoast SEO" group.

### Migration
- No action required. Abilities appear in MCP > Settings under "Yoast SEO" only if Yoast SEO is active.

---

## [1.2.1] — 2026-06-17

### Summary
Minor fixes and additions to the Config Files admin page.

### Added
- **OpenClaw** tab on MCP > Config Files with a ready-to-paste JSON snippet (`~/.openclaw/openclaw.json`, uses `mcp.servers` schema + `mcp-remote` bridge).

### Fixed
- `create-page` ability: added `page_layout` input parameter (maps to `_wp_page_template` post meta) so AI clients can set the page template when creating a page.
- `create-page` ability: fixed Elementor initialization — new pages are now properly recognized by Elementor (`_elementor_edit_mode` meta set to `builder`).

---

## [1.2.0] — 2026-06-14

### Summary
Major refactor from a single-file plugin to a modular `includes/`-based structure. Adds a full suite of Elementor page-builder abilities.

### Added
- **Elementor abilities** (`includes/abilities/elementor.php`) — 9 abilities for reading and writing Elementor page structure:
  - Read: `elementor-list-pages`, `elementor-get-page`, `elementor-get-element`, `elementor-find-element`, `elementor-list-templates`
  - Write: `elementor-update-element`, `elementor-add-widget`, `elementor-add-container`, `elementor-remove-element`
  - All gated on `class_exists('\Elementor\Plugin')` and `edit_posts` capability.
- Helper functions for the Elementor data model: `wsp_elementor_get_data`, `wsp_elementor_save_data`, `wsp_elementor_generate_id`, `wsp_elementor_find_by_id`, `wsp_elementor_remove_by_id`, `wsp_elementor_update_by_id`, `wsp_elementor_insert_into`, `wsp_elementor_first_insertable`, `wsp_elementor_simplify_tree`, `wsp_elementor_search_tree`.

### Changed
- **Modular refactor** — moved all feature code out of the monolithic main file into `includes/abilities/` (posts, pages, taxonomy, comments, media, users, search, site). Main file reduced to a minimal loader + activation glue.
  - Rationale: single-file plugins are hard to review and extend; the new structure matches WP.org best practices.

### Migration
- No action required. Behavior is identical to v1.1.0 for all non-Elementor abilities.

---

## [1.1.0] — 2026-06-07

### Summary
Adds an admin page to generate ready-to-paste MCP config file snippets for Claude Desktop, Cursor, and Codex.

### Added
- **MCP > Config Files** admin page (`includes/admin/config-page.php`) — auto-fills the REST API URL and current WP username; user replaces the placeholder Application Password. Tabs for Claude Desktop, Cursor, and Codex (TOML).
- `readme.txt` (initial version).

---

## [1.0.0] — 2026-06-06

### Summary
Initial release. Registers WordPress content as MCP abilities via the WordPress Abilities API / mcp-adapter stack.

### Added
- Plugin scaffold: `wsp-wordpress-mcp.php` main file with all abilities inline.
- Read abilities (ON by default): `get-posts`, `get-pages`, `get-categories`, `get-tags`, `search`, `get-site-info`.
- Write abilities (OFF by default): `create-post`, `update-post`, `delete-post`, `create-page`, `update-page`, `delete-page`, `create-category`, `create-tag`.
- Sensitive read abilities (OFF by default): `get-comments`, `approve-comment`, `delete-comment`, `get-media`, `get-users`, `get-plugins`.
- Admin toggle UI (MCP > Settings) — per-ability on/off switches with write-action confirmation dialogs.
- Central ability registry (`wsp_mcp_ability_registry()`) driving both admin UI and ability registration.
- Dual-mode transport guard: `function_exists('wp_register_ability')` so the plugin degrades gracefully when the Abilities API is absent.
