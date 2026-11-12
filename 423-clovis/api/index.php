<?php
header('Content-Type: application/json');
$out = ['php' => PHP_VERSION, 'sqlite' => extension_loaded('pdo_sqlite'), 'curl' => extension_loaded('curl'), 'dir' => __DIR__];
foreach ([dirname(__DIR__, 3) . '/dados-previewdo', dirname(__DIR__, 2) . '/../dados-previewdo'] as $d) {
  $ok = is_dir($d) || @mkdir($d, 0700, true);
  $out['try'][] = [$d, $ok, $ok ? is_writable($d) : false];
}
$out['home'] = getenv('HOME');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
