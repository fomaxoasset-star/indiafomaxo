<?php
declare(strict_types=1);
/* FOMAXO India: where a visitor is, from their IP address, looked up in the files in api/geo/ (DB-IP Lite, https://db-ip.com, CC BY 4.0).
   Gives a country code (IN, AE …) and, in India, the state. Approximate: mobile networks often show the state of the operator's hub.
   Nothing here stores the IP address. */

/* the visitor's address: the web server's own one, or the first public one a proxy passed on */
function fomaxo_geo_ip(): ?string {
  $pub = fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : null;
  if ($ip = $pub(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')))) return $ip;
  foreach (explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $ip) if ($ip = $pub(trim($ip))) return $ip;
  return null;
}

/* [country code or '', Indian state or ''] */
function fomaxo_geo(?string $ip): array {
  $bin = @inet_pton((string)$ip);
  if ($bin === false) return ['', ''];
  if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $bin = substr($bin, 12);   // ::ffff:1.2.3.4
  $v4 = strlen($bin) === 4; $key = $v4 ? $bin : substr($bin, 0, 8); $w = strlen($key);
  $r = fomaxo_geo_find(__DIR__ . '/geo/country' . ($v4 ? 4 : 6) . '.bin', $key, $w, $w + 2);
  $cc = $r === null ? '' : substr($r, $w, 2);
  if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'ZZ') return ['', ''];
  $st = '';
  if ($cc === 'IN' && ($r = fomaxo_geo_find(__DIR__ . '/geo/in' . ($v4 ? 4 : 6) . '.bin', $key, $w, 2 * $w + 1)) !== null
      && strcmp($key, substr($r, $w, $w)) <= 0) $st = FOMAXO_STATES[ord($r[2 * $w]) - 1] ?? '';
  return [$cc, $st];
}

/* the last record in a sorted file whose start is <= key (records start with the big-endian start address) */
function fomaxo_geo_find(string $file, string $key, int $w, int $size): ?string {
  $f = @fopen($file, 'rb');
  if (!$f) return null;
  $lo = 0; $hi = intdiv((int)filesize($file), $size) - 1; $best = null;
  while ($lo <= $hi) {
    $mid = ($lo + $hi) >> 1; fseek($f, $mid * $size); $rec = (string)fread($f, $size);
    if (strcmp(substr($rec, 0, $w), $key) <= 0) { $best = $rec; $lo = $mid + 1; } else $hi = $mid - 1;
  }
  fclose($f);
  return $best;
}

/* English country name from a two-letter code */
function fomaxo_country_name(string $cc): string {
  static $n = ['AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan', 'AG' => 'Antigua and Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albania', 'AM' => 'Armenia', 'AO' => 'Angola', 'AQ' => 'Antarctica', 'AR' => 'Argentina', 'AS' => 'American Samoa', 'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba', 'AX' => 'Åland Islands', 'AZ' => 'Azerbaijan', 'BA' => 'Bosnia and Herzegovina', 'BB' => 'Barbados', 'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BF' => 'Burkina Faso', 'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BI' => 'Burundi', 'BJ' => 'Benin', 'BL' => 'Saint Barthélemy', 'BM' => 'Bermuda', 'BN' => 'Brunei', 'BO' => 'Bolivia', 'BQ' => 'Caribbean Netherlands', 'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan', 'BV' => 'Bouvet Island', 'BW' => 'Botswana', 'BY' => 'Belarus', 'BZ' => 'Belize', 'CA' => 'Canada', 'CC' => 'Cocos (Keeling) Islands', 'CD' => 'Congo - Kinshasa', 'CF' => 'Central African Republic', 'CG' => 'Congo - Brazzaville', 'CH' => 'Switzerland', 'CI' => 'Côte d’Ivoire', 'CK' => 'Cook Islands', 'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China', 'CO' => 'Colombia', 'CR' => 'Costa Rica', 'CU' => 'Cuba', 'CV' => 'Cape Verde', 'CW' => 'Curaçao', 'CX' => 'Christmas Island', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DE' => 'Germany', 'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'DZ' => 'Algeria', 'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt', 'EH' => 'Western Sahara', 'ER' => 'Eritrea', 'ES' => 'Spain', 'ET' => 'Ethiopia', 'FI' => 'Finland', 'FJ' => 'Fiji', 'FK' => 'Falkland Islands (Malvinas)', 'FM' => 'Micronesia', 'FO' => 'Faroe Islands', 'FR' => 'France', 'GA' => 'Gabon', 'GB' => 'United Kingdom', 'GD' => 'Grenada', 'GE' => 'Georgia', 'GF' => 'French Guiana', 'GG' => 'Guernsey', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GL' => 'Greenland', 'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe', 'GQ' => 'Equatorial Guinea', 'GR' => 'Greece', 'GS' => 'South Georgia and the South Sandwich Islands', 'GT' => 'Guatemala', 'GU' => 'Guam', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HK' => 'Hong Kong', 'HM' => 'Heard Island and McDonald Islands', 'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti', 'HU' => 'Hungary', 'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man', 'IN' => 'India', 'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq', 'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy', 'JE' => 'Jersey', 'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya', 'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KI' => 'Kiribati', 'KM' => 'Comoros', 'KN' => 'Saint Kitts and Nevis', 'KP' => 'North Korea', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'KY' => 'Cayman Islands', 'KZ' => 'Kazakhstan', 'LA' => 'Laos', 'LB' => 'Lebanon', 'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LR' => 'Liberia', 'LS' => 'Lesotho', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia', 'LY' => 'Libya', 'MA' => 'Morocco', 'MC' => 'Monaco', 'MD' => 'Moldova', 'ME' => 'Montenegro', 'MF' => 'Saint Martin (French part)', 'MG' => 'Madagascar', 'MH' => 'Marshall Islands', 'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar', 'MN' => 'Mongolia', 'MO' => 'Macao', 'MP' => 'Northern Mariana Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius', 'MV' => 'Maldives', 'MW' => 'Malawi', 'MX' => 'Mexico', 'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NC' => 'New Caledonia', 'NE' => 'Niger', 'NF' => 'Norfolk Island', 'NG' => 'Nigeria', 'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue', 'NZ' => 'New Zealand', 'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia', 'PG' => 'Papua New Guinea', 'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PM' => 'Saint Pierre and Miquelon', 'PN' => 'Pitcairn', 'PR' => 'Puerto Rico', 'PS' => 'Palestine', 'PT' => 'Portugal', 'PW' => 'Palau', 'PY' => 'Paraguay', 'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Romania', 'RS' => 'Serbia', 'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia', 'SB' => 'Solomon Islands', 'SC' => 'Seychelles', 'SD' => 'Sudan', 'SE' => 'Sweden', 'SG' => 'Singapore', 'SH' => 'Saint Helena, Ascension and Tristan da Cunha', 'SI' => 'Slovenia', 'SJ' => 'Svalbard and Jan Mayen', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone', 'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia', 'SR' => 'Suriname', 'SS' => 'South Sudan', 'ST' => 'Sao Tome and Principe', 'SV' => 'El Salvador', 'SX' => 'Sint Maarten (Dutch part)', 'SY' => 'Syria', 'SZ' => 'Eswatini', 'TC' => 'Turks and Caicos Islands', 'TD' => 'Chad', 'TF' => 'French Southern Territories', 'TG' => 'Togo', 'TH' => 'Thailand', 'TJ' => 'Tajikistan', 'TK' => 'Tokelau', 'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan', 'TN' => 'Tunisia', 'TO' => 'Tonga', 'TR' => 'Türkiye', 'TT' => 'Trinidad and Tobago', 'TV' => 'Tuvalu', 'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraine', 'UG' => 'Uganda', 'UM' => 'United States Minor Outlying Islands', 'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VA' => 'Vatican City', 'VC' => 'Saint Vincent and the Grenadines', 'VE' => 'Venezuela', 'VG' => 'Virgin Islands, British', 'VI' => 'Virgin Islands, U.S.', 'VN' => 'Vietnam', 'VU' => 'Vanuatu', 'WF' => 'Wallis and Futuna', 'WS' => 'Samoa', 'XK' => 'Kosovo', 'YE' => 'Yemen', 'YT' => 'Mayotte', 'ZA' => 'South Africa', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe'];
  return $n[$cc] ?? $cc;
}
/* the flag emoji for a two-letter code */
function fomaxo_flag(string $cc): string {
  return preg_match('/^[A-Z]{2}$/', $cc) ? mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65) : '';
}
