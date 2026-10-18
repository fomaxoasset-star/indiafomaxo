<?php
declare(strict_types=1);
/* FOMAXO India — checkout PIN code: GET ?pin=560001 → {city, state}, so City and State fill in by themselves.
   Looked up in India Post's list (api.postalpincode.in). An unknown PIN, or the list not answering, gives {} and the
   shopper types them as before. State names are the ones in the checkout's State list (FOMAXO_STATES). */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const PIN_STATES = ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh',
  'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh',
  'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
  'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana',
  'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];
/* older spellings India Post still uses */
const PIN_ALIAS = ['chattisgarh' => 'Chhattisgarh', 'orissa' => 'Odisha', 'pondicherry' => 'Puducherry', 'dadra and nagar haveli' => 'Dadra and Nagar Haveli and Daman and Diu',
  'daman and diu' => 'Dadra and Nagar Haveli and Daman and Diu', 'the dadra and nagar haveli and daman and diu' => 'Dadra and Nagar Haveli and Daman and Diu',
  'andaman and nicobar' => 'Andaman and Nicobar Islands', 'nct of delhi' => 'Delhi', 'new delhi' => 'Delhi'];

function pin_out(array $d, int $age): void { header('Cache-Control: public, max-age=' . $age); echo json_encode((object)$d, JSON_UNESCAPED_UNICODE); exit; }

$pin = (string)($_GET['pin'] ?? '');
if (!preg_match('/^[1-9]\d{5}$/', $pin)) pin_out([], 86400);

$api = getenv('FOMAXO_PIN_API') ?: 'https://api.postalpincode.in/pincode/';
$raw = false;
if (function_exists('curl_init')) {
  $ch = curl_init($api . $pin);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4, CURLOPT_USERAGENT => 'fomaxo.in checkout']);
  $raw = curl_exec($ch); if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $raw = false;
  curl_close($ch);
}
$po = json_decode((string)$raw, true)[0]['PostOffice'][0] ?? null;
if (!is_array($po)) pin_out([], $raw === false ? 60 : 86400);   // list not answering: try again soon; unknown PIN: a day

$st = trim(str_replace('&', 'and', (string)($po['State'] ?? '')));
$key = strtolower(preg_replace('/\s+/', ' ', $st));
$state = PIN_ALIAS[$key] ?? (array_values(array_filter(PIN_STATES, fn($s) => strtolower($s) === $key))[0] ?? '');
$city = trim((string)($po['District'] ?? ''));
pin_out(array_filter(['city' => mb_substr($city, 0, 60), 'state' => $state]), 30 * 86400);
