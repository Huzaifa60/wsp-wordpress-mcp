<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_mcp_config_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $current_user = wp_get_current_user();
    $username     = $current_user->exists() ? $current_user->user_login : 'your-wordpress-user-name';
    $api_url      = untrailingslashit( rest_url( 'mcp/mcp-adapter-default-server' ) );

    $site_domain = parse_url( get_site_url(), PHP_URL_HOST );
    $toml_key    = 'wsp-' . trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $site_domain ) ), '-' );

    $openclaw_json = "\"mcp\": {\n"
        . "    \"servers\": {\n"
        . "        \"wsp-wordpress-mcp\": {\n"
        . "            \"command\": \"npx\",\n"
        . "            \"args\": [\"-y\", \"@automattic/mcp-wordpress-remote@latest\"],\n"
        . "            \"env\": {\n"
        . "                \"WP_API_URL\": \"{$api_url}\",\n"
        . "                \"WP_API_USERNAME\": \"{$username}\",\n"
        . "                \"WP_API_PASSWORD\": \"replace-with-your-application-password\"\n"
        . "            }\n"
        . "        }\n"
        . "    }\n"
        . "},";

    $config_array = array(
        'mcpServers' => array(
            'wsp-wordpress-mcp' => array(
                'command' => 'npx',
                'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
                'env'     => array(
                    'WP_API_URL'      => $api_url,
                    'WP_API_USERNAME' => $username,
                    'WP_API_PASSWORD' => 'replace-with-your-application-password',
                ),
            ),
        ),
    );
    $config_json = wp_json_encode( $config_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

    $toml_config = "[mcp_servers.{$toml_key}]\n"
        . "command = \"npx\"\n"
        . "args = [\"-y\", \"@automattic/mcp-wordpress-remote@latest\"]\n"
        . "\n"
        . "[mcp_servers.{$toml_key}.env]\n"
        . "WP_API_URL = \"{$api_url}\"\n"
        . "WP_API_USERNAME = \"{$username}\"\n"
        . "WP_API_PASSWORD = \"replace-with-your-application-password\"";
    ?>
    <style>
        .wsp-wrap{max-width:860px;margin:30px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .wsp-header{display:flex;align-items:center;gap:12px;margin-bottom:24px}
        .wsp-header h1{margin:0;font-size:22px;font-weight:700;color:#1d2327}
        .wsp-desc{color:#646970;margin-bottom:16px;font-size:13.5px;line-height:1.65}
        .wsp-tabs{display:flex;gap:0;margin-bottom:0;border-bottom:2px solid #dcdcde}
        .wsp-tab-btn{background:none;border:none;padding:10px 22px;font-size:14px;font-weight:600;color:#787c82;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s}
        .wsp-tab-btn:hover{color:#1d2327}
        .wsp-tab-btn.wsp-tab-active{color:#0073aa;border-bottom-color:#0073aa}
        .wsp-tab-panel{display:none}
        .wsp-tab-panel.wsp-tab-panel-active{display:block}
        .wsp-config-box{background:#fff;border:1px solid #dcdcde;border-radius:0 0 8px 8px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .wsp-instructions{padding:20px;border-bottom:1px solid #f0f0f1;background:#fff}
        .wsp-instructions p{margin:0 0 10px 0;color:#3c434a;font-size:14px}
        .wsp-instructions p:last-child{margin:0}
        .wsp-instructions code{background:#f0f0f1;padding:3px 6px;border-radius:4px;font-size:13px;color:#d63638;font-family:monospace}
        .wsp-config-header{background:#f6f7f7;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #dcdcde}
        .wsp-config-title{font-weight:700;font-size:13.5px;color:#1d2327;margin:0}
        .wsp-copy-btn{font-size:12px;color:#0073aa;cursor:pointer;background:none;border:none;padding:0;font-weight:600;display:flex;align-items:center;gap:4px;transition:color .2s}
        .wsp-copy-btn:hover{color:#00a32a}
        .wsp-code-area{background:#1e1e1e;color:#d4d4d4;padding:20px;margin:0;font-family:Consolas,Monaco,monospace;font-size:13.5px;line-height:1.6;overflow-x:auto;white-space:pre}
    </style>

    <div class="wsp-wrap">
        <div class="wsp-header">
            <h1>⚙️ Connection Config</h1>
        </div>
        <p class="wsp-desc">
            Connect your AI tool to this WordPress site. Your API URL and username are auto-detected —
            just generate an <strong>Application Password</strong> and paste it in.
        </p>

        <div class="wsp-tabs">
            <button type="button" class="wsp-tab-btn wsp-tab-active" data-tab="claude">Claude Desktop</button>
            <button type="button" class="wsp-tab-btn" data-tab="cursor">Cursor</button>
            <button type="button" class="wsp-tab-btn" data-tab="codex">Codex</button>
            <button type="button" class="wsp-tab-btn" data-tab="antigravity">Antigravity</button>
            <button type="button" class="wsp-tab-btn" data-tab="openclaw">OpenClaw</button>
        </div>

        <div class="wsp-tab-panel wsp-tab-panel-active" id="wsp-tab-claude">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Copy the code and paste it into your <code>claude_desktop_config.json</code> file.</p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">claude_desktop_config.json</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-claude">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-claude"><?php echo esc_html( $config_json ); ?></pre>
            </div>
        </div>

        <div class="wsp-tab-panel" id="wsp-tab-cursor">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Copy the code and paste it into <code>~/.cursor/mcp.json</code> (global) or <code>.cursor/mcp.json</code> in your project root.</p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">~/.cursor/mcp.json</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-cursor">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-cursor"><?php echo esc_html( $config_json ); ?></pre>
            </div>
        </div>

        <div class="wsp-tab-panel" id="wsp-tab-codex">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Copy the code and paste it into your <code>~/.codex/config.toml</code> file.</p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">~/.codex/config.toml</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-codex">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-codex"><?php echo esc_html( $toml_config ); ?></pre>
            </div>
        </div>
        <div class="wsp-tab-panel" id="wsp-tab-openclaw">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Open <code>~/.openclaw/openclaw.json</code> and paste the copied block on the line right after the opening <code>{</code> at the top of the file.</p>
                    <p>4. Save the file and restart OpenClaw.</p>
                    <p>5. Restart the gateway: <code>openclaw gateway restart</code></p>
                    <p>6. Verify the connection: <code>openclaw mcp status --verbose</code></p>
                    <p>7. Test connectivity: <code>openclaw mcp probe wsp-wordpress-mcp</code></p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">~/.openclaw/openclaw.json</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-openclaw">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-openclaw"><?php echo esc_html( $openclaw_json ); ?></pre>
            </div>
        </div>
        <div class="wsp-tab-panel" id="wsp-tab-antigravity">
            <div class="wsp-config-box">
                <div class="wsp-instructions">
                    <p>1. Go to <strong>Users &gt; Profile</strong> and scroll down to generate a new <strong>Application Password</strong>.</p>
                    <p>2. In the code below, replace <code>replace-with-your-application-password</code> with your new password.</p>
                    <p>3. Copy the code and paste it into <code>~/.gemini/config/mcp_config.json</code>, or open <strong>Manage MCP Servers &gt; View raw config</strong> in Antigravity.</p>
                </div>
                <div class="wsp-config-header">
                    <span class="wsp-config-title">~/.gemini/config/mcp_config.json</span>
                    <button type="button" class="wsp-copy-btn" id="wsp-copy-antigravity">
                        <span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> Copy to Clipboard
                    </button>
                </div>
                <pre class="wsp-code-area" id="wsp-code-antigravity"><?php echo esc_html( $config_json ); ?></pre>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.wsp-tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.wsp-tab-btn').forEach(function(b){ b.classList.remove('wsp-tab-active'); });
                document.querySelectorAll('.wsp-tab-panel').forEach(function(p){ p.classList.remove('wsp-tab-panel-active'); });
                btn.classList.add('wsp-tab-active');
                document.getElementById('wsp-tab-' + btn.dataset.tab).classList.add('wsp-tab-panel-active');
            });
        });

        function makeCopyBtn(btnId, codeId) {
            var btn  = document.getElementById(btnId);
            var code = document.getElementById(codeId);
            if (!btn || !code) return;
            btn.addEventListener('click', function() {
                navigator.clipboard.writeText(code.innerText).then(function() {
                    var orig = btn.innerHTML;
                    btn.innerHTML = '<span class="dashicons dashicons-yes-alt" style="font-size:16px;width:16px;height:16px;"></span> Copied!';
                    btn.style.color = '#00a32a';
                    setTimeout(function(){ btn.innerHTML = orig; btn.style.color = ''; }, 2500);
                }).catch(function(){ alert('Failed to copy. Please select and copy manually.'); });
            });
        }
        makeCopyBtn('wsp-copy-claude',      'wsp-code-claude');
        makeCopyBtn('wsp-copy-cursor',      'wsp-code-cursor');
        makeCopyBtn('wsp-copy-codex',       'wsp-code-codex');
        makeCopyBtn('wsp-copy-antigravity', 'wsp-code-antigravity');
        makeCopyBtn('wsp-copy-openclaw',    'wsp-code-openclaw');
    });
    </script>
    <?php
}
