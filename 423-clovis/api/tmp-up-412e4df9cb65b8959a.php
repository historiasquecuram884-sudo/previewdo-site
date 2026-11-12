<?php // TEMPORARIO: recebe as imagens do Flow. Apagar depois do uso.
header('Access-Control-Allow-Origin: *');
$dir = dirname(__DIR__, 3) . '/dados-sites/up-423'; if (!is_dir($dir)) mkdir($dir, 0700, true);
$n = preg_replace('/[^a-z0-9.-]/', '', $_GET['n'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $n) { $d = file_get_contents('php://input'); if (strlen($d) < 8e6) file_put_contents("$dir/$n", $d); echo 'ok'; exit; }
if ($n && is_file("$dir/$n")) { header('Content-Type: application/octet-stream'); readfile("$dir/$n"); exit; }
foreach (scandir($dir) as $f) if ($f[0] !== '.') echo $f, ' ', filesize("$dir/$f"), "\n";
