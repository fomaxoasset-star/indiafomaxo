<?php
declare(strict_types=1);
/* FOMAXO India — the home page (fomaxo.in/, the root .htaccess sends it here): index.html exactly as it is, only without its
   code notes (about 30 KB less to download, api/lean-lib.php), and "not modified" in a few bytes when nothing changed. */
require __DIR__ . '/lean-lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
fx_send_page((string)@file_get_contents(dirname(__DIR__) . '/index.html'));
