<?php
// Run: php tests/notice-cookie-test.php (on PHP 7.4 and 8.x). Not loaded by WordPress.
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', '/');
$GLOBALS['coinsnap_givewp_test_cookies'] = [];
eval('namespace Coinsnap\Util; function setcookie($n, $v = "", $e = 0, $p = "") { $GLOBALS["coinsnap_givewp_test_cookies"][$n] = $v; return true; }');
function wp_json_encode($v) { return json_encode($v); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : (is_string($v) ? stripslashes($v) : $v); }
function sanitize_text_field($v) { return is_string($v) ? trim(strip_tags($v)) : ''; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
require __DIR__ . '/../library/Util/Notice.php';

$fail = 0;
function coinsnap_givewp_test_ok($cond, $msg) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $msg . "\n"; if (!$cond) { $fail++; } }
set_error_handler(function ($no, $str) { throw new \ErrorException($str, 0, $no); });

$show = function ($cookie) {
    $_COOKIE = ($cookie === null) ? [] : ['coinsnap_notices' => $cookie];
    ob_start();
    try {
        (new \Coinsnap\Util\Notice())->showNotices();
        return ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        return 'THROWN ' . get_class($e) . ': ' . $e->getMessage();
    }
};

// A cookie written by the browser never breaks the admin page (guide 6.1).
coinsnap_givewp_test_ok($show(null) === '', 'no cookie: nothing shown');
coinsnap_givewp_test_ok($show('O:8:"stdClass":0:{}') === '', 'serialized object as a plain cookie: ignored, no error');
coinsnap_givewp_test_ok($show('just text') === '', 'plain string cookie: ignored, no error');
coinsnap_givewp_test_ok($show(['a' => 'O:8:"stdClass":0:{}']) === '', 'serialized object in the notices array: ignored');
coinsnap_givewp_test_ok($show(['a' => ['nested']]) === '', 'nested array: ignored, no error');
coinsnap_givewp_test_ok($show(['a' => '{"type":"error"}']) === '', 'notice without message: ignored');
coinsnap_givewp_test_ok($show(['a' => '{"type":"error","message":"<b>Hi</b>"}']) === '<div class="notice notice-error"><p>Hi</p></div>', 'valid notice shown; missing dismissible flag gives no warning');
coinsnap_givewp_test_ok($show(['a' => '{"type":"success","message":"Ok","dismissible":1}']) === '<div class="notice notice-success is-dismissible"><p>Ok</p></div>', 'dismissible notice');

// addNotice keeps the type it was given.
(new \Coinsnap\Util\Notice())->addNotice('error', 'Boom');
(new \Coinsnap\Util\Notice())->addNotice('bogus', 'Other');
$set = array_values($GLOBALS['coinsnap_givewp_test_cookies']);
coinsnap_givewp_test_ok((json_decode($set[0] ?? '', true)['type'] ?? '') === 'error', 'error notice stays an error');
coinsnap_givewp_test_ok((json_decode($set[1] ?? '', true)['type'] ?? '') === 'info', 'unknown type becomes info');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
