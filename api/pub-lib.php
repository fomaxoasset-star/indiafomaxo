<?php
declare(strict_types=1);
/* FOMAXO India — public copies of private pictures: photos uploaded on the admin page (media/p, from fomaxo-private/product-images)
   and review photos (media/r, from fomaxo-private/photos). The web server sends a copy itself, so a page full of pictures no longer
   holds one PHP process per picture (PHP processes ran out on 2026-10-21, see videos in video-lib.php).
   The root .htaccess sends a picture that has no copy yet to api/live.php?img=… or api/reviews.php?action=photo, which makes the copy.
   Every upload gets a new file name, so a copy never needs updating, only removing when its picture is deleted. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function fomaxo_pub(string $from, string $dir, string $name): bool {   // $dir: 'media/p' or 'media/r'
  $d = dirname(__DIR__) . "/$dir"; $to = "$d/$name";
  if (is_file($to)) return true;
  if (!is_file($from) || (!is_dir($d) && !@mkdir($d, 0755, true))) return false;
  $tmp = $to . '.' . bin2hex(random_bytes(4));
  try { if (!@copy($from, $tmp)) return false; } catch (Throwable $e) { @unlink($tmp); return false; }   // copy, not link(): link() is switched off on Hostinger
  @chmod($tmp, 0644); if (!@rename($tmp, $to)) { @unlink($tmp); return false; }
  return true;
}
function fomaxo_pub_drop(string $dir, string $name): void { if (preg_match('/^[a-z0-9-]{1,80}\.(webp|jpg)$/', $name)) @unlink(dirname(__DIR__) . "/$dir/$name"); }
