<?php
declare(strict_types=1);
/* FOMAXO India — checkout opened from a link in our WhatsApp (?w=personal code): POST {who} → {phone} (10 digits, or '' when the code is not ours),
   so the Mobile box fills in by itself with the number we wrote to. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/store-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
out(['phone' => wa_who_phone((string)(input()['who'] ?? ''))]);
