<?php

/**
 * Asyntai - AI Chatbot
 *
 * AI assistant / chatbot - Provides instant answers to your website visitors
 *
 * @package    Asyntai
 * @version    1.0.0
 * @author     Asyntai
 * @link       https://asyntai.com
 * @license    MIT
 */

class pluginAsyntai extends Plugin
{
    /**
     * Initialize the database fields with default values
     */
    public function init()
    {
        $this->dbFields = array(
            'site_id' => '',
            'script_url' => 'https://asyntai.com/static/js/chat-widget.js',
            'account_email' => ''
        );
    }

    /**
     * Inject the chatbot script into the site head
     * This is more universally supported by themes than siteBodyEnd
     */
    public function siteHead()
    {
        $siteId = trim($this->getValue('site_id'));

        // Don't inject if not configured
        if (empty($siteId)) {
            return false;
        }

        $scriptUrl = trim($this->getValue('script_url'));
        if (empty($scriptUrl)) {
            $scriptUrl = 'https://asyntai.com/static/js/chat-widget.js';
        }

        // Inject the script tag directly (async and defer for non-blocking)
        return '<script src="' . htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8') . '" data-asyntai-id="' . htmlspecialchars($siteId, ENT_QUOTES, 'UTF-8') . '" async defer></script>';
    }

    /**
     * Add link to admin sidebar
     */
    public function adminSidebar()
    {
        global $L;
        $pluginName = $L->get('plugin-data')['name'] ?? 'Asyntai - AI Chatbot';
        return '<a class="nav-link" href="' . HTML_PATH_ADMIN_ROOT . 'configure-plugin/pluginAsyntai">' . $pluginName . '</a>';
    }

    /**
     * Add admin head content (CSS for settings page)
     */
    public function adminHead()
    {
        global $url;

        // Only load on plugin settings page
        $slug = $url->slug();
        if (strpos($slug, 'configure-plugin/') === false || strpos($slug, 'syntai') === false) {
            return false;
        }

        $css = <<<CSS
<style>
#asyntai-settings-wrap {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}
#asyntai-status {
    padding: 16px 20px;
    background: #ffffff;
    border-radius: 6px;
    margin: 20px 0;
    font-size: 16px;
    font-weight: 500;
    color: #1e293b;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
#asyntai-status.connected {
    border: 1px solid #00a32a;
    border-left: 4px solid #00a32a;
}
#asyntai-status.disconnected {
    border: 1px solid #d63638;
    border-left: 4px solid #d63638;
}
#asyntai-status strong {
    color: #1e293b;
    font-weight: 600;
}
.asyntai-box {
    max-width: 820px;
    margin: 40px auto;
    padding: 48px 32px;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 10px 30px rgba(0,0,0,0.08);
    text-align: center;
}
.asyntai-title {
    font-size: 28px;
    font-weight: 700;
    line-height: 1.2;
    margin-bottom: 12px;
    color: #1e293b;
}
.asyntai-subtitle {
    font-size: 18px;
    line-height: 1.5;
    margin-bottom: 32px;
    color: #64748b;
}
.asyntai-btn-primary {
    display: inline-block;
    padding: 16px 48px;
    font-size: 18px;
    font-weight: 600;
    color: #fff !important;
    background: #6366f1;
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none !important;
}
.asyntai-btn-primary:hover {
    background: #4f46e5;
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
    color: #fff !important;
    text-decoration: none !important;
}
.asyntai-btn-secondary {
    display: inline-block;
    padding: 8px 16px;
    font-size: 14px;
    font-weight: 500;
    color: #64748b;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    margin-left: 12px;
}
.asyntai-btn-secondary:hover {
    background: #e2e8f0;
    color: #475569;
}
.asyntai-hint {
    margin-top: 16px;
    font-size: 14px;
    color: #94a3b8;
}
.asyntai-hint a {
    color: #6366f1;
    text-decoration: underline;
}
.asyntai-tip {
    margin-top: 24px;
    padding: 16px;
    background: #f8fafc;
    border-radius: 8px;
    font-size: 14px;
    color: #64748b;
}
.asyntai-tip strong {
    color: #1e293b;
}
.asyntai-tip a {
    color: #6366f1;
    text-decoration: underline;
}
#asyntai-alert {
    padding: 12px 16px;
    margin: 16px 0;
    border-radius: 8px;
    font-size: 14px;
    display: none;
}
#asyntai-alert.alert-success {
    background: #d1fae5;
    border: 1px solid #10b981;
    color: #065f46;
}
#asyntai-alert.alert-danger {
    background: #fee2e2;
    border: 1px solid #ef4444;
    color: #991b1b;
}
/* Loading overlay */
#asyntai-loading-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.9);
    z-index: 99999;
    justify-content: center;
    align-items: center;
    flex-direction: column;
}
#asyntai-loading-overlay.active {
    display: flex;
}
.asyntai-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #e5e7eb;
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: asyntai-spin 1s linear infinite;
}
@keyframes asyntai-spin {
    to { transform: rotate(360deg); }
}
.asyntai-loading-text {
    margin-top: 16px;
    font-size: 18px;
    font-weight: 500;
    color: #1e293b;
}
</style>
CSS;

        return $css;
    }

    /**
     * Handle POST data - Bludit's standard way to save plugin settings
     */
    public function post()
    {
        // Check for our custom AJAX action
        if (isset($_POST['asyntai_action'])) {
            $action = $_POST['asyntai_action'];

            if ($action === 'save') {
                $siteId = isset($_POST['site_id']) ? trim($_POST['site_id']) : '';
                $scriptUrl = isset($_POST['script_url']) ? trim($_POST['script_url']) : '';
                $accountEmail = isset($_POST['account_email']) ? trim($_POST['account_email']) : '';

                if (!empty($siteId)) {
                    $this->db['site_id'] = $siteId;
                    $this->db['script_url'] = !empty($scriptUrl) ? $scriptUrl : 'https://asyntai.com/static/js/chat-widget.js';
                    $this->db['account_email'] = $accountEmail;

                    // Save to disk!
                    $saved = $this->save();

                    // Clean output and send JSON
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(array('success' => $saved, 'saved' => $this->db));
                    exit;
                }
            } elseif ($action === 'reset') {
                $this->db['site_id'] = '';
                $this->db['script_url'] = 'https://asyntai.com/static/js/chat-widget.js';
                $this->db['account_email'] = '';

                // Save to disk!
                $saved = $this->save();

                // Clean output and send JSON
                while (ob_get_level()) {
                    ob_end_clean();
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('success' => $saved));
                exit;
            }
        }

        // Let parent handle normal form submission
        parent::post();
    }

    /**
     * Build the admin configuration form
     */
    public function form()
    {
        global $L;

        $siteId = trim($this->getValue('site_id'));
        $accountEmail = trim($this->getValue('account_email'));
        $connected = !empty($siteId);

        $statusClass = $connected ? 'connected' : 'disconnected';
        $statusColor = $connected ? '#00a32a' : '#d63638';
        $statusText = $connected ? $L->get('connected') : $L->get('not-connected');
        $statusExtra = ($connected && !empty($accountEmail)) ? ' ' . $L->get('as') . ' <strong>' . htmlspecialchars($accountEmail, ENT_QUOTES, 'UTF-8') . '</strong>' : '';
        $resetBtn = $connected ? '<button type="button" id="asyntai-reset" class="asyntai-btn-secondary">' . $L->get('reset') . '</button>' : '';

        $iframeSrc = 'https://asyntai.com/wp-auth?platform=bludit';

        // Build the form HTML
        $html = '<div id="asyntai-settings-wrap">';

        // Loading overlay
        $html .= '<div id="asyntai-loading-overlay"><div class="asyntai-spinner"></div><div class="asyntai-loading-text">' . $L->get('connecting') . '</div></div>';

        // Status bar
        $html .= '<div id="asyntai-status" class="' . $statusClass . '">';
        $html .= $L->get('status') . ': <span style="color:' . $statusColor . ';font-weight:600;">' . $statusText . '</span>';
        $html .= $statusExtra . $resetBtn;
        $html .= '</div>';

        // Alert area
        $html .= '<div id="asyntai-alert"></div>';

        // Connected box
        $html .= '<div id="asyntai-connected-box" style="display:' . ($connected ? 'block' : 'none') . ';">';
        $html .= '<div class="asyntai-box">';
        $html .= '<div class="asyntai-title">' . $L->get('enabled-title') . '</div>';
        $html .= '<div class="asyntai-subtitle">' . $L->get('enabled-desc') . '</div>';
        $html .= '<a class="asyntai-btn-primary" href="https://asyntai.com/dashboard" target="_blank" rel="noopener">' . $L->get('open-panel') . '</a>';
        $html .= '<div class="asyntai-tip">';
        $html .= '<strong>' . $L->get('tip') . ':</strong> ' . $L->get('tip-text');
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        // Connect popup UI
        $html .= '<div id="asyntai-popup-wrap" style="display:' . ($connected ? 'none' : 'block') . ';">';
        $html .= '<div class="asyntai-box">';
        $html .= '<div class="asyntai-title" style="margin-bottom:24px;">' . $L->get('create-account') . '</div>';
        $html .= '<button type="button" id="asyntai-connect-btn" class="asyntai-btn-primary">' . $L->get('get-started') . '</button>';
        $html .= '<div class="asyntai-hint">' . sprintf($L->get('popup-blocked'), '<a href="' . htmlspecialchars($iframeSrc, ENT_QUOTES, 'UTF-8') . '" id="asyntai-fallback-link" target="_blank" rel="noopener">' . $L->get('open-connect-window') . '</a>') . '</div>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        // Get the admin URL for AJAX requests
        $adminUrl = HTML_PATH_ADMIN_ROOT . 'configure-plugin/pluginAsyntai';

        // JavaScript for connection flow
        $html .= '<script>
(function(){
    var currentState = null;
    var ajaxUrl = ' . json_encode($adminUrl) . ';
    var iframeSrc = ' . json_encode($iframeSrc) . ';
    var popupWindow = null;
    var popupCheckInterval = null;

    function showLoading(show) {
        var overlay = document.getElementById("asyntai-loading-overlay");
        if (overlay) {
            overlay.className = show ? "active" : "";
        }
    }

    function showAlert(msg, ok){
        var el = document.getElementById("asyntai-alert");
        if(!el) return;
        el.style.display = "block";
        el.className = ok ? "alert-success" : "alert-danger";
        el.textContent = msg;
    }

    function generateState(){
        return "bludit_" + Math.random().toString(36).substr(2, 9);
    }

    function updateFallbackLink(){
        var fallbackLink = document.getElementById("asyntai-fallback-link");
        if(fallbackLink && currentState){
            fallbackLink.href = iframeSrc + (iframeSrc.indexOf("?") > -1 ? "&" : "?") + "state=" + encodeURIComponent(currentState);
        }
    }

    function openPopup(){
        currentState = generateState();
        updateFallbackLink();
        var url = iframeSrc + (iframeSrc.indexOf("?") > -1 ? "&" : "?") + "state=" + encodeURIComponent(currentState);
        var w = 800, h = 720;
        var y = window.top.outerHeight / 2 + window.top.screenY - (h / 2);
        var x = window.top.outerWidth / 2 + window.top.screenX - (w / 2);
        popupWindow = window.open(url, "asyntai_connect", "toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=" + w + ",height=" + h + ",top=" + y + ",left=" + x);
        if(!popupWindow){
            showAlert(' . json_encode($L->get('popup-alert')) . ', false);
            return;
        }

        // Start checking if popup is closed
        popupCheckInterval = setInterval(function() {
            if (popupWindow && popupWindow.closed) {
                clearInterval(popupCheckInterval);
                // Show loading when popup closes - user completed the flow
                showLoading(true);
            }
        }, 500);

        pollForConnection(currentState);
    }

    // Initialize fallback link on page load
    currentState = generateState();
    updateFallbackLink();

    function pollForConnection(state){
        var attempts = 0;
        function check(){
            if(attempts++ > 120) {
                showLoading(false);
                showAlert("Connection timeout. Please try again.", false);
                return;
            }
            var script = document.createElement("script");
            var cb = "asyntai_cb_" + Date.now();
            script.src = "https://asyntai.com/connect-status.js?state=" + encodeURIComponent(state) + "&cb=" + cb;
            window[cb] = function(data){
                try{ delete window[cb]; }catch(e){}
                if(data && data.site_id){
                    // Clear popup check interval
                    if (popupCheckInterval) clearInterval(popupCheckInterval);
                    // Show loading immediately when connection is detected
                    showLoading(true);
                    saveConnection(data);
                    return;
                }
                setTimeout(check, 500);
            };
            script.onerror = function(){ setTimeout(check, 1000); };
            document.head.appendChild(script);
        }
        setTimeout(check, 800);
    }

    function saveConnection(data){
        showAlert(' . json_encode($L->get('connecting')) . ', true);

        var formData = new FormData();
        formData.append("asyntai_action", "save");
        formData.append("site_id", data.site_id || "");
        if(data.script_url) formData.append("script_url", data.script_url);
        if(data.account_email) formData.append("account_email", data.account_email);

        // Get CSRF token - try multiple possible element IDs
        var tokenEl = document.getElementById("jstokenCSRF") || document.querySelector("input[name=tokenCSRF]");
        if(tokenEl) formData.append("tokenCSRF", tokenEl.value);

        fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: formData
        })
        .then(function(r){
            if(!r.ok) throw new Error("HTTP " + r.status);
            return r.json();
        })
        .then(function(json){
            if(!json || !json.success) throw new Error(json && json.error || "Save failed");

            // Hide loading
            showLoading(false);
            showAlert(' . json_encode($L->get('success')) . ', true);

            var status = document.getElementById("asyntai-status");
            if(status){
                status.className = "connected";
                var html = ' . json_encode($L->get('status') . ': <span style="color:#00a32a;font-weight:600;">' . $L->get('connected') . '</span>') . ';
                if(data.account_email){
                    html += " ' . $L->get('as') . ' <strong>" + data.account_email + "</strong>";
                }
                html += \' <button type="button" id="asyntai-reset" class="asyntai-btn-secondary">' . $L->get('reset') . '</button>\';
                status.innerHTML = html;
                attachResetHandler();
            }

            var box = document.getElementById("asyntai-connected-box");
            if(box) box.style.display = "block";
            var wrap = document.getElementById("asyntai-popup-wrap");
            if(wrap) wrap.style.display = "none";
        })
        .catch(function(err){
            showLoading(false);
            showAlert(' . json_encode($L->get('error-save')) . ' + (err && err.message || err), false);
        });
    }

    function resetConnection(){
        if(!confirm(' . json_encode($L->get('reset-confirm')) . ')) return;

        var formData = new FormData();
        formData.append("asyntai_action", "reset");

        // Get CSRF token
        var tokenEl = document.getElementById("jstokenCSRF") || document.querySelector("input[name=tokenCSRF]");
        if(tokenEl) formData.append("tokenCSRF", tokenEl.value);

        fetch(ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: formData
        })
        .then(function(r){
            if(!r.ok) throw new Error("HTTP " + r.status);
            return r.json();
        })
        .then(function(json){
            if(!json || !json.success) throw new Error(json && json.error || "Reset failed");
            window.location.reload();
        })
        .catch(function(err){
            showAlert(' . json_encode($L->get('reset-failed')) . ' + (err && err.message || err), false);
        });
    }

    function attachResetHandler(){
        var resetBtn = document.getElementById("asyntai-reset");
        if(resetBtn && !resetBtn.hasAttribute("data-attached")){
            resetBtn.setAttribute("data-attached", "true");
            resetBtn.addEventListener("click", function(e){
                e.preventDefault();
                resetConnection();
            });
        }
    }

    document.addEventListener("click", function(ev){
        var t = ev.target;
        if(t && t.id === "asyntai-connect-btn"){
            ev.preventDefault();
            openPopup();
        }
        if(t && t.id === "asyntai-reset"){
            ev.preventDefault();
            resetConnection();
        }
        if(t && t.id === "asyntai-fallback-link"){
            currentState = generateState();
            updateFallbackLink();
            setTimeout(function(){ pollForConnection(currentState); }, 1000);
        }
    });

    // Attach handler on initial load if reset button exists
    attachResetHandler();
})();
</script>';

        return $html;
    }
}
