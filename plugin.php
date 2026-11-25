<?php

/**
 * Asyntai - AI Chatbot
 *
 * AI assistant / chatbot - Provides instant answers to your website visitors
 *
 * @package    Asyntai
 * @version    1.1.0
 * @author     Asyntai
 * @link       https://asyntai.com
 * @license    MIT
 */

class pluginAsyntai extends Plugin
{
    const DEFAULT_SCRIPT = 'https://asyntai.com/static/js/chat-widget.js';

    /** How long a connect handshake stays valid, in seconds. */
    const STATE_TTL = 900;

    /** Pages per feed request when Asyntai asks for no size. */
    const PAGE_SIZE = 50;

    /** Largest page size the feed answers with. */
    const MAX_PAGE_SIZE = 100;

    /** Allowed clock difference for a signed feed request, in seconds. */
    const MAX_SKEW = 300;

    public function init()
    {
        $this->dbFields = array(
            'site_id' => '',
            'script_url' => self::DEFAULT_SCRIPT,
            'account_email' => '',
            // Page sharing. The tokens are kept here because this database
            // file is never served (it starts with a PHP exit line) and is
            // not touched by the plugin form.
            'share_pages' => false,
            'feed_token' => '',
            'pending_token' => '',
            'state' => '',
            'state_at' => 0,
        );
        // This plugin saves through its own buttons.
        $this->formButtons = false;
    }

    // ------------------------------------------------------------------
    // Site
    // ------------------------------------------------------------------

    /** Load the widget after the page's load event, so it never delays the page. */
    public function siteHead()
    {
        $siteId = trim((string) $this->getValue('site_id'));
        if ($siteId === '') {
            return false;
        }

        $scriptUrl = trim((string) $this->getValue('script_url'));
        if ($scriptUrl === '') {
            $scriptUrl = self::DEFAULT_SCRIPT;
        }

        return '<script>(function(){var l=function(){var s=document.createElement("script");s.src=' . json_encode($scriptUrl) . ';s.async=true;s.setAttribute("data-asyntai-id",' . json_encode($siteId) . ');document.head.appendChild(s)};if(document.readyState==="complete"){l()}else{window.addEventListener("load",l)}})();</script>';
    }

    /** The signed feed. Only requests that carry ?asyntai_feed= are touched. */
    public function beforeAll()
    {
        if (!isset($_GET['asyntai_feed'])) {
            return;
        }
        $which = (string) $_GET['asyntai_feed'];
        if ($which !== 'products' && $which !== 'pages') {
            return;
        }

        $error = $this->feedRefusal();
        if ($error !== null) {
            $this->sendJson(array('ok' => false, 'error' => $error), 403);
        }

        $page = max(1, (int) $this->query('page'));
        $limit = (int) $this->query('limit');
        if ($limit < 1) {
            $limit = self::PAGE_SIZE;
        }
        $limit = min($limit, self::MAX_PAGE_SIZE);

        if ($which === 'products') {
            $this->sendJson($this->productsAnswer($page));
        }
        $this->sendJson($this->pagesAnswer($page, $limit));
    }

    /** Why a feed request is refused, or null when it may be answered. */
    private function feedRefusal()
    {
        if (!$this->getValue('share_pages')) {
            return 'Sharing is switched off';
        }

        $ts = $this->query('ts');
        if ($ts === '' || !ctype_digit($ts)) {
            return 'Missing timestamp';
        }
        if (abs(time() - (int) $ts) > self::MAX_SKEW) {
            return 'Request expired';
        }

        $sig = strtolower($this->query('sig'));
        $message = 'page=' . $this->query('page') . '&limit=' . $this->query('limit')
            . '&ids=' . $this->query('ids') . '&ts=' . $ts;
        foreach ($this->acceptedTokens() as $token) {
            if (hash_equals(hash_hmac('sha256', $message, $token), $sig)) {
                return null;
            }
        }
        return 'Bad signature';
    }

    /** The live token, plus the one parked during a sign-in. */
    private function acceptedTokens()
    {
        $tokens = array();
        foreach (array('feed_token', 'pending_token') as $key) {
            $value = (string) $this->getValue($key);
            if (strlen($value) >= 32) {
                $tokens[] = $value;
            }
        }
        return $tokens;
    }

    /** Bludit has no products: the product feed only carries the site and its pages link. */
    private function productsAnswer($page)
    {
        global $site;

        return array(
            'ok' => true,
            'store' => array(
                'name' => (string) $site->title(),
                'url' => rtrim(DOMAIN_BASE, '/'),
                'currency' => '',
                'version' => defined('BLUDIT_VERSION') ? BLUDIT_VERSION : '',
                'pages_url' => $this->feedUrl('pages'),
            ),
            'page' => $page,
            'pages' => 0,
            'total' => 0,
            'categories' => array(),
            'products' => array(),
        );
    }

    /** Published posts, sticky posts and static pages, as plain text. */
    private function pagesAnswer($page, $limit)
    {
        global $pages;

        $keys = array();
        // published, static, sticky; never drafts, scheduled or autosaves.
        $all = $pages->getList(1, -1, true, true, true, false, false);
        foreach ((array) $all as $key) {
            try {
                $item = new Page($key);
            } catch (Exception $e) {
                continue;
            }
            if ($item->noindex()) {
                continue;
            }
            $keys[] = $key;
        }

        $total = count($keys);
        $pageCount = $total === 0 ? 0 : (int) ceil($total / $limit);
        $items = array();
        foreach (array_slice($keys, ($page - 1) * $limit, $limit) as $key) {
            try {
                $item = new Page($key);
            } catch (Exception $e) {
                continue;
            }
            $content = $this->plainText($item->content());
            $title = trim(html_entity_decode((string) $item->title(), ENT_QUOTES, 'UTF-8'));
            if ($title === '' && $content === '') {
                continue;
            }
            $record = array(
                'type' => $item->isStatic() ? 'page' : 'post',
                'title' => $title !== '' ? $title : $key,
                'url' => (string) $item->permalink(true),
                'content' => $content !== '' ? $content : $title,
            );
            $summary = trim(html_entity_decode((string) $item->description(), ENT_QUOTES, 'UTF-8'));
            if ($summary !== '') {
                $record['summary'] = $summary;
            }
            $items[] = $record;
        }

        return array(
            'ok' => true,
            'page' => $page,
            'pages' => $pageCount,
            'total' => $total,
            'items' => $items,
        );
    }

    /** HTML to readable text, keeping line breaks between blocks. */
    private function plainText($html)
    {
        $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', (string) $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote|pre|section|article|table|ul|ol)>#i', "\n", $html);
        $html = preg_replace('#<(td|th)\b[^>]*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private function query($key)
    {
        return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : '';
    }

    private function sendJson($data, $status = 200)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function feedUrl($which)
    {
        return DOMAIN_BASE . '?asyntai_feed=' . $which;
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function adminSidebar()
    {
        global $L;
        $pluginName = $L->get('plugin-data')['name'] ?? 'Asyntai - AI Chatbot';
        return '<a class="nav-link" href="' . HTML_PATH_ADMIN_ROOT . 'configure-plugin/pluginAsyntai">' . $pluginName . '</a>';
    }

    /**
     * The settings screen actions. Bludit only calls this for a logged-in
     * admin, after it has checked the CSRF token of the request.
     */
    public function post()
    {
        $action = isset($_POST['asyntai_action']) ? (string) $_POST['asyntai_action'] : '';
        switch ($action) {
            case 'prepare':
                $this->sendJson($this->actionPrepare());
            case 'poll':
                $this->sendJson($this->actionPoll());
            case 'share':
                $this->sendJson($this->actionShare());
            case 'status':
                $this->sendJson($this->actionStatus());
            case 'reset':
                $this->sendJson($this->actionReset());
        }
        // Nothing else is saved from this screen.
        return true;
    }

    /**
     * Start a connect. With sharing ticked, a fresh feed token is parked at
     * Asyntai first. Without it, connecting works as in 1.0.0.
     */
    private function actionPrepare()
    {
        global $login;

        $share = isset($_POST['share']) && $_POST['share'] === '1';
        $state = 'bludit_' . bin2hex(random_bytes(12));

        $this->db['pending_token'] = '';
        if ($share) {
            $token = bin2hex(random_bytes(24));
            $body = $this->http('POST', $this->origin() . '/api/v1/wp-search/stage/', array(
                'state' => $state,
                'platform' => 'bludit',
                'feed_protocol' => 'store_feed_v1',
                'product' => 'chat',
                'site_url' => rtrim(DOMAIN_BASE, '/'),
                'feed_url' => $this->feedUrl('products'),
                'feed_token' => $token,
            ));
            $decoded = $body === null ? null : json_decode($body, true);
            if (!is_array($decoded) || empty($decoded['ok'])) {
                return array('ok' => false, 'error' => 'Could not reach Asyntai. Check that this site can make outgoing HTTPS requests.');
            }
            // Asyntai starts reading at sign-in, so the feed accepts this
            // token until the sign-in finishes.
            $this->db['pending_token'] = $token;
        }
        $this->db['state'] = $state;
        $this->db['state_at'] = time();
        $this->save();

        $email = '';
        if (isset($login) && is_object($login) && method_exists($login, 'username')) {
            try {
                $user = new User($login->username());
                $email = (string) $user->email();
            } catch (Exception $e) {
                $email = '';
            }
        }

        $url = $this->origin() . '/wp-auth?' . http_build_query(array(
            'platform' => 'bludit',
            'state' => $state,
            'product' => 'chat',
            'site_url' => rtrim(DOMAIN_BASE, '/'),
            'wp_email' => $email,
        ));

        return array('ok' => true, 'state' => $state, 'url' => $url);
    }

    /** Has the owner finished signing in? Asked from here, never from the browser. */
    private function actionPoll()
    {
        $state = isset($_POST['state']) ? trim((string) $_POST['state']) : '';
        if ($state === '' || !hash_equals((string) $this->getValue('state'), $state)) {
            return array('ok' => false, 'error' => 'Unknown connection attempt. Please press Get started again.');
        }
        if (time() - (int) $this->getValue('state_at') > self::STATE_TTL) {
            return array('ok' => false, 'error' => 'This connection attempt has expired. Please press Get started again.');
        }

        $body = $this->http('GET', $this->origin() . '/api/v1/wp-search/connect-status/?state=' . rawurlencode($state));
        $decoded = $body === null ? null : json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['ready']) || empty($decoded['data']['site_id'])) {
            return array('ok' => true, 'ready' => false);
        }

        return $this->finish($decoded['data']);
    }

    /** Store the sign-in result. A parked feed token becomes the live one. */
    private function finish($data)
    {
        $siteId = trim((string) $data['site_id']);
        if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $siteId)) {
            return array('ok' => false, 'error' => 'Invalid site id.');
        }

        $this->db['site_id'] = $siteId;
        $scriptUrl = isset($data['script_url']) ? trim((string) $data['script_url']) : '';
        $this->db['script_url'] = preg_match('#^https://#i', $scriptUrl) ? $scriptUrl : self::DEFAULT_SCRIPT;
        $email = isset($data['account_email']) ? trim((string) $data['account_email']) : '';
        $this->db['account_email'] = mb_substr($email, 0, 255);

        $pending = (string) $this->getValue('pending_token');
        if ($pending !== '') {
            $this->db['feed_token'] = $pending;
            $this->db['share_pages'] = true;
            $this->db['pending_token'] = '';
        }
        $this->db['state'] = '';
        $this->db['state_at'] = 0;
        $this->save();

        return array(
            'ok' => true,
            'ready' => true,
            'account_email' => $email,
            'sharing' => (bool) $this->getValue('share_pages'),
        );
    }

    /** Switch page sharing on or off. */
    private function actionShare()
    {
        $on = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        if ($on && (string) $this->getValue('feed_token') === '') {
            // Connected before sharing existed, or after a reset: Asyntai has
            // no token for this site yet, so the owner signs in once more.
            return array('ok' => false, 'needs_connect' => true);
        }
        $this->db['share_pages'] = $on;
        $this->save();

        return array('ok' => true, 'enabled' => $on);
    }

    /** What Asyntai says about the last read of the shared pages. */
    private function actionStatus()
    {
        $siteId = trim((string) $this->getValue('site_id'));
        if ($siteId === '') {
            return array('ok' => true, 'sync' => null);
        }
        $body = $this->http('GET', $this->origin() . '/api/v1/search-widget/status/?widget_id=' . rawurlencode($siteId), null, 8);
        $decoded = $body === null ? null : json_decode($body, true);
        $sync = (is_array($decoded) && isset($decoded['sync']) && is_array($decoded['sync'])) ? $decoded['sync'] : null;

        return array('ok' => true, 'sync' => $sync === null ? null : array(
            'status' => (string) (isset($sync['status']) ? $sync['status'] : ''),
            'count' => (int) (isset($sync['count']) ? $sync['count'] : 0),
            'error' => (string) (isset($sync['error']) ? $sync['error'] : ''),
        ));
    }

    /** Forget the connection. The feed token goes with it. */
    private function actionReset()
    {
        foreach ($this->dbFields as $key => $default) {
            $this->db[$key] = $default;
        }
        $this->save();

        return array('ok' => true);
    }

    /** Asyntai's address. A test site may set ASYNTAI_CHATBOT_ORIGIN. */
    private function origin()
    {
        $env = getenv('ASYNTAI_CHATBOT_ORIGIN');
        if (!is_string($env) && isset($_SERVER['ASYNTAI_CHATBOT_ORIGIN'])) {
            $env = (string) $_SERVER['ASYNTAI_CHATBOT_ORIGIN'];
        }
        if (is_string($env) && preg_match('#^https?://[^/\s]+$#', $env)) {
            return $env;
        }
        return 'https://asyntai.com';
    }

    /** A small HTTP client: curl when present, PHP streams otherwise. */
    private function http($method, $url, $json = null, $timeout = 15)
    {
        $body = $json === null ? null : json_encode($json);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headers = array('Accept: application/json');
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            curl_setopt_array($ch, array(
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'Asyntai-Bludit/1.1.0',
            ));
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($out === false || $code < 200 || $code >= 300) ? null : (string) $out;
        }

        $context = stream_context_create(array('http' => array(
            'method' => $method,
            'header' => "Accept: application/json\r\n" . ($body !== null ? "Content-Type: application/json\r\n" : ''),
            'content' => $body === null ? '' : $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'user_agent' => 'Asyntai-Bludit/1.1.0',
        )));
        $out = @file_get_contents($url, false, $context);
        if ($out === false || !isset($http_response_header[0]) || !preg_match('#\s2\d\d\s#', $http_response_header[0])) {
            return null;
        }
        return (string) $out;
    }

    public function adminHead()
    {
        global $url;

        $slug = $url->slug();
        if (strpos($slug, 'configure-plugin/') === false || strpos($slug, 'syntai') === false) {
            return false;
        }

        return <<<CSS
<style>
#asyntai-settings-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #111; }
#asyntai-status { padding: 16px 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; margin: 20px 0; font-size: 16px; }
#asyntai-status strong { font-weight: 600; }
.asyntai-box { max-width: 820px; margin: 32px auto; padding: 40px 32px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; text-align: center; }
.asyntai-title { font-size: 26px; font-weight: 700; line-height: 1.2; margin-bottom: 12px; }
.asyntai-subtitle { font-size: 17px; line-height: 1.5; margin-bottom: 28px; color: #555; }
.asyntai-btn-primary { display: inline-block; padding: 14px 40px; font-size: 17px; font-weight: 600; color: #fff !important; background: #111; border: 0; border-radius: 10px; cursor: pointer; text-decoration: none !important; }
.asyntai-btn-primary:hover { background: #333; }
.asyntai-btn-secondary { display: inline-block; padding: 6px 14px; font-size: 14px; color: #333; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; margin-left: 12px; }
.asyntai-share { display: flex; gap: 10px; align-items: flex-start; justify-content: center; text-align: left; max-width: 560px; margin: 0 auto 24px; font-size: 15px; color: #333; }
.asyntai-share input { margin: 4px 10px 0 0; flex: none; }
.asyntai-share small { display: block; color: #666; margin-top: 2px; }
.asyntai-tip { margin-top: 24px; padding: 14px; background: #f9fafb; border-radius: 8px; font-size: 14px; color: #555; }
.asyntai-tip a { color: #111; text-decoration: underline; }
#asyntai-sync { margin-top: 10px; font-size: 14px; color: #555; }
#asyntai-alert { padding: 12px 16px; margin: 16px 0; border-radius: 8px; font-size: 14px; display: none; }
#asyntai-alert.alert-success { background: #ecfdf5; border: 1px solid #10b981; color: #065f46; }
#asyntai-alert.alert-danger { background: #fef2f2; border: 1px solid #ef4444; color: #991b1b; }
</style>
CSS;
    }

    public function form()
    {
        global $L;

        $siteId = trim((string) $this->getValue('site_id'));
        $accountEmail = trim((string) $this->getValue('account_email'));
        $connected = $siteId !== '';
        $sharing = (bool) $this->getValue('share_pages');
        $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

        $statusText = $connected
            ? '<span style="color:#00a32a;font-weight:600;">' . $e($L->get('connected')) . '</span>'
              . ($accountEmail !== '' ? ' ' . $e($L->get('as')) . ' <strong>' . $e($accountEmail) . '</strong>' : '')
              . ' <button type="button" id="asyntai-reset" class="asyntai-btn-secondary">' . $e($L->get('reset')) . '</button>'
            : '<span style="color:#d63638;font-weight:600;">' . $e($L->get('not-connected')) . '</span>';

        $html = '<div id="asyntai-settings-wrap">';
        $html .= '<div id="asyntai-status">' . $e($L->get('status')) . ': ' . $statusText . '</div>';
        $html .= '<div id="asyntai-alert"></div>';

        if ($connected) {
            $html .= '<div class="asyntai-box">';
            $html .= '<div class="asyntai-title">' . $e($L->get('enabled-title')) . '</div>';
            $html .= '<div class="asyntai-subtitle">' . $e($L->get('enabled-desc')) . '</div>';
            $html .= '<label class="asyntai-share"><input type="checkbox" id="asyntai-share-toggle"' . ($sharing ? ' checked' : '') . '>'
                . '<span>' . $e($L->get('share-label')) . '<small>' . $e($L->get('share-help')) . '</small></span></label>';
            $html .= '<a class="asyntai-btn-primary" href="https://asyntai.com/dashboard" target="_blank" rel="noopener">' . $e($L->get('open-panel')) . '</a>';
            $html .= '<div id="asyntai-sync"></div>';
            $html .= '<div class="asyntai-tip"><strong>' . $e($L->get('tip')) . ':</strong> ' . $L->get('tip-text') . '</div>';
            $html .= '</div>';
        } else {
            $html .= '<div class="asyntai-box">';
            $html .= '<div class="asyntai-title" style="margin-bottom:24px;">' . $e($L->get('create-account')) . '</div>';
            $html .= '<label class="asyntai-share"><input type="checkbox" id="asyntai-share-connect" checked>'
                . '<span>' . $e($L->get('share-label')) . '<small>' . $e($L->get('share-help')) . '</small></span></label>';
            $html .= '<button type="button" id="asyntai-connect-btn" class="asyntai-btn-primary">' . $e($L->get('get-started')) . '</button>';
            $html .= '<div id="asyntai-sync"></div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        $strings = array(
            'waiting' => $L->get('waiting'),
            'success' => $L->get('success'),
            'popup' => $L->get('popup-alert'),
            'resetConfirm' => $L->get('reset-confirm'),
            'needsConnect' => $L->get('needs-connect'),
            'shared' => $L->get('pages-shared'),
            'reading' => $L->get('pages-reading'),
            'error' => $L->get('error-save'),
            'timeout' => $L->get('timeout'),
        );

        $html .= '<script>
(function(){
    var ajaxUrl = ' . json_encode(HTML_PATH_ADMIN_ROOT . 'configure-plugin/pluginAsyntai') . ';
    var T = ' . json_encode($strings) . ';
    var connected = ' . ($connected ? 'true' : 'false') . ';

    function token(){
        var el = document.getElementById("jstokenCSRF") || document.querySelector("input[name=tokenCSRF]");
        return el ? el.value : "";
    }
    function call(action, fields){
        var fd = new FormData();
        fd.append("asyntai_action", action);
        fd.append("tokenCSRF", token());
        for (var k in (fields || {})) { fd.append(k, fields[k]); }
        return fetch(ajaxUrl, {method: "POST", credentials: "same-origin", body: fd})
            .then(function(r){ if(!r.ok) throw new Error("HTTP " + r.status); return r.json(); });
    }
    function alertBox(msg, ok){
        var el = document.getElementById("asyntai-alert");
        if(!el) return;
        el.style.display = msg ? "block" : "none";
        el.className = ok ? "alert-success" : "alert-danger";
        el.textContent = msg || "";
    }

    function connect(){
        var box = document.getElementById("asyntai-share-connect");
        var share = (!box || box.checked) ? "1" : "0";
        // Open the window now, while the click still counts, then point it
        // at the sign-in page once the handshake is ready.
        var w = 800, h = 720;
        var y = window.top.outerHeight / 2 + window.top.screenY - (h / 2);
        var x = window.top.outerWidth / 2 + window.top.screenX - (w / 2);
        var win = window.open("about:blank", "asyntai_connect", "width=" + w + ",height=" + h + ",top=" + y + ",left=" + x + ",scrollbars=yes,resizable=yes");
        if(!win){ alertBox(T.popup, false); return; }
        call("prepare", {share: share}).then(function(res){
            if(!res.ok){ win.close(); alertBox(res.error || T.error, false); return; }
            win.location.href = res.url;
            alertBox(T.waiting, true);
            poll(res.state, 0);
        }).catch(function(err){ win.close(); alertBox(T.error + (err && err.message || err), false); });
    }

    function poll(state, n){
        if(n > 300){ alertBox(T.timeout, false); return; }
        setTimeout(function(){
            call("poll", {state: state}).then(function(res){
                if(!res.ok){ alertBox(res.error || T.error, false); return; }
                if(!res.ready){ poll(state, n + 1); return; }
                alertBox(T.success, true);
                setTimeout(function(){ window.location.reload(); }, 900);
            }).catch(function(){ poll(state, n + 1); });
        }, 2000);
    }

    function share(el){
        call("share", {enabled: el.checked ? "1" : "0"}).then(function(res){
            if(res.needs_connect){ el.checked = false; alertBox(T.needsConnect, false); return; }
            if(!res.ok){ el.checked = !el.checked; alertBox(res.error || T.error, false); return; }
            alertBox("", true);
            status();
        }).catch(function(err){ el.checked = !el.checked; alertBox(T.error + (err && err.message || err), false); });
    }

    function status(){
        var out = document.getElementById("asyntai-sync");
        var box = document.getElementById("asyntai-share-toggle");
        if(!out || !connected) return;
        if(!box || !box.checked){ out.textContent = ""; return; }
        call("status").then(function(res){
            var s = res && res.sync;
            if(!s){ out.textContent = ""; return; }
            if(s.status === "running" || s.status === "pending"){ out.textContent = T.reading; return; }
            out.textContent = T.shared.replace("%s", String(s.count || 0));
        }).catch(function(){ out.textContent = ""; });
    }

    function reset(){
        if(!confirm(T.resetConfirm)) return;
        call("reset").then(function(){ window.location.reload(); })
            .catch(function(err){ alertBox(T.error + (err && err.message || err), false); });
    }

    document.addEventListener("click", function(ev){
        var t = ev.target;
        if(!t) return;
        if(t.id === "asyntai-connect-btn"){ ev.preventDefault(); connect(); }
        if(t.id === "asyntai-reset"){ ev.preventDefault(); reset(); }
    });
    document.addEventListener("change", function(ev){
        if(ev.target && ev.target.id === "asyntai-share-toggle"){ share(ev.target); }
    });
    status();
})();
</script>';

        return $html;
    }
}
