<?php
// Run: php tests/result-array-access-test.php (on PHP 7.4 and 8.x). Not loaded by WordPress.
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', '/');
require __DIR__ . '/../library/Result/AbstractResult.php';
require __DIR__ . '/../library/Result/Store.php';
require __DIR__ . '/../library/Result/Invoice.php';

$fail = 0;
function coinsnap_givewp_test_ok($cond, $msg) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $msg . "\n"; if (!$cond) { $fail++; } }

set_error_handler(function ($no, $str) { throw new \ErrorException($str, 0, $no); });
try {
    $r = new \Coinsnap\Result\Store(['code' => 200, 'result' => ['onchain' => true, 'lightning' => false]]);
    coinsnap_givewp_test_ok($r['code'] === 200, 'int value via array access');
    coinsnap_givewp_test_ok($r['result']['onchain'] === true, 'array value via array access');
    coinsnap_givewp_test_ok($r['missing'] === null, 'missing key gives null');
    coinsnap_givewp_test_ok(isset($r['code']) && !isset($r['missing']), 'isset works');
    $i = new \Coinsnap\Result\Invoice(['id' => 'inv1', 'status' => 'Settled']);
    coinsnap_givewp_test_ok($i['status'] === 'Settled', 'invoice result array access');
} catch (\Throwable $e) {
    coinsnap_givewp_test_ok(false, 'array access threw ' . get_class($e) . ': ' . $e->getMessage());
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
