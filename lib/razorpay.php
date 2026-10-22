<?php
// Razorpay payments (https://razorpay.com/docs/api). Amounts go to Razorpay in paise (₹ x 100).
// The till shows a UPI QR code for the exact amount: the customer scans it with PhonePe, Google Pay,
// Paytm, BHIM or any UPI app, and the sale confirms by itself when Razorpay receives the money.
// If UPI QR codes are not switched on for the Razorpay account, a Razorpay payment link QR is shown
// instead (opens Razorpay's page: UPI apps, cards, netbanking, wallets).
// Modes: 'live' = use the Razorpay keys (rzp_test_ keys take no real money, rzp_live_ keys do),
//        'demo' = practice page, no Razorpay account needed (for trying the app only).

function rzp_mode()
{
    // Saved as demo (practice), test or live; test and live both use Razorpay, the keys decide whether money is real.
    return in_array(cfg('razorpay_mode', 'demo'), ['live', 'test'], true) ? 'live' : 'demo';
}

/** The keys: saved in the app (Settings), else config file, else the fomaxo.in shop's own razorpay-config.php (same hosting account). */
function rzp_keys()
{
    static $shop = null;
    $id = trim((string) cfg('razorpay_key_id', ''));
    $secret = trim((string) cfg('razorpay_key_secret', ''));
    if ($id !== '' && $secret !== '') {
        return ['id' => $id, 'secret' => $secret, 'from' => 'app'];
    }
    if ($shop === null) {
        $shop = [];
        $file = cfg('shop_razorpay_config', dirname(dirname(__DIR__), 2) . '/fomaxo.in/fomaxo-private/razorpay-config.php');
        if ($file && @is_file($file) && @is_readable($file)) {
            try {
                $c = require $file;
                if (is_array($c) && !empty($c['razorpay_key_id']) && !empty($c['razorpay_key_secret'])
                    && strpos((string) $c['razorpay_key_id'], 'PASTE') === false) {
                    $shop = ['id' => trim($c['razorpay_key_id']), 'secret' => trim($c['razorpay_key_secret']), 'from' => 'shop'];
                }
            } catch (Throwable $e) {
                $shop = [];
            }
        }
    }
    return $shop ?: null;
}

function rzp_test_keys()
{
    $k = rzp_keys();
    return $k && strpos($k['id'], 'rzp_test_') === 0;
}

function rzp_enabled()
{
    return rzp_mode() === 'demo' || rzp_keys() !== null;
}

function rzp_call($method, $path, $payload = null)
{
    $k = rzp_keys();
    if (!$k) {
        throw new ApiError('Razorpay keys are not set up yet (menu → Settings).', 400);
    }
    $ch = curl_init('https://api.razorpay.com/v1' . $path);
    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERPWD => $k['id'] . ':' . $k['secret'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new ApiError('Could not reach Razorpay: ' . $err, 502);
    }
    $data = json_decode($raw, true);
    if ($code >= 400 || !is_array($data)) {
        $msg = is_array($data) ? ($data['error']['description'] ?? 'unknown error') : 'unexpected reply';
        $e = new ApiError('Razorpay refused the payment request (' . $code . '): ' . $msg, 502);
        $e->rzpCode = $code;
        throw $e;
    }
    return $data;
}

/**
 * Starts a payment. Returns ['id' => ..., 'qr' => text for the QR code, 'link' => page to open or share, 'kind' => 'upi'|'link'].
 * The id is 'qr_…' (UPI QR), 'plink_…' (payment link) or 'demo_…'.
 */
function rzp_create($totalPaise, $receiptNo, $description, $callbackUrl, $as = 'upi')
{
    if (rzp_mode() === 'demo') {
        $id = 'demo_' . bin2hex(random_bytes(8));
        demo_store($id, 'created');
        $url = cfg('base_url') . '/pay-demo.php?id=' . $id;
        return ['id' => $id, 'qr' => $url, 'link' => $url, 'kind' => 'demo'];
    }
    $closeBy = time() + 30 * 60;
    if ($as !== 'card' && cfg('razorpay_qr', 'upi') === 'upi' && meta_get('rzp_no_qr') !== '1') {
        try {
            $qr = rzp_call('POST', '/payments/qr_codes', [
                'type' => 'upi_qr',
                'name' => mb_substr((string) cfg('store_name', 'FOMAXO'), 0, 40),
                'usage' => 'single_use',
                'fixed_amount' => true,
                'payment_amount' => $totalPaise,
                'description' => mb_substr($description, 0, 255),
                'close_by' => $closeBy,
                'notes' => ['receipt' => $receiptNo, 'source' => 'FOMAXO store'],
            ]);
            if (!empty($qr['id'])) {
                $text = (string) ($qr['image_content'] ?? '');
                return ['id' => $qr['id'], 'qr' => $text, 'image' => (string) ($qr['image_url'] ?? ''), 'link' => (string) ($qr['image_url'] ?? ''), 'kind' => 'upi'];
            }
        } catch (ApiError $e) {
            // UPI QR codes not switched on for this account (Razorpay answers 400/401/403): use payment links from now on.
            if (!in_array((int) ($e->rzpCode ?? 0), [400, 401, 403, 404], true)) {
                throw $e;
            }
            error_log('FOMAXO POS: Razorpay QR codes not available, using payment links: ' . $e->getMessage());
            meta_set('rzp_no_qr', '1');
        }
    }
    // Card: a payment link that offers card only. The customer opens it from the QR on their phone, or staff open it on the till.
    $link = [
        'amount' => $totalPaise,
        'currency' => 'INR',
        'accept_partial' => false,
        'description' => mb_substr($description, 0, 2048),
        'reference_id' => $receiptNo,
        'expire_by' => $closeBy,
        'reminder_enable' => false,
        'notify' => ['sms' => false, 'email' => false],
        'callback_url' => $callbackUrl,
        'callback_method' => 'get',
        'notes' => ['receipt' => $receiptNo, 'source' => 'FOMAXO store'],
    ];
    $pl = null;
    if ($as === 'card') {
        try {
            $pl = rzp_call('POST', '/payment_links', $link + ['options' => ['checkout' => ['method' => ['card' => '1', 'upi' => '0', 'netbanking' => '0', 'wallet' => '0', 'emi' => '0', 'paylater' => '0']]]]);
        } catch (ApiError $e) {
            if (!in_array((int) ($e->rzpCode ?? 0), [400, 422], true)) {
                throw $e;
            }
            error_log('FOMAXO POS: Razorpay refused a card-only link, using a normal link: ' . $e->getMessage());
        }
    }
    $pl = $pl ?: rzp_call('POST', '/payment_links', $link);
    if (empty($pl['id']) || empty($pl['short_url'])) {
        throw new ApiError('Razorpay did not return a payment link.', 502);
    }
    return ['id' => $pl['id'], 'qr' => $pl['short_url'], 'link' => $pl['short_url'], 'kind' => $as === 'card' ? 'card' : 'link'];
}

/** The UPI app a customer paid with, from their UPI ID (e.g. name@ybl is PhonePe). */
function upi_app_from_vpa($vpa)
{
    $h = strtolower((string) substr(strrchr((string) $vpa, '@') ?: '', 1));
    $apps = [
        'PhonePe' => ['ybl', 'ibl', 'axl'],
        'Google Pay' => ['okaxis', 'okhdfcbank', 'okicici', 'oksbi'],
        'Paytm' => ['paytm', 'ptyes', 'ptaxis', 'pthdfc', 'ptsbi'],
        'BHIM' => ['upi'],
        'Amazon Pay' => ['apl', 'yapl', 'rapl'],
        'CRED' => ['axisb'],
    ];
    foreach ($apps as $name => $handles) {
        if (in_array($h, $handles, true)) {
            return $name;
        }
    }
    return $h !== '' ? 'UPI' : '';
}

/** A short note for the receipt: which app or method and the Razorpay payment id. */
function rzp_payment_ref($p)
{
    if (!is_array($p)) {
        return '';
    }
    $how = '';
    if (($p['method'] ?? '') === 'upi') {
        $how = upi_app_from_vpa($p['vpa'] ?? ($p['upi']['vpa'] ?? '')) ?: 'UPI';
    } elseif (!empty($p['method'])) {
        $how = ['card' => 'Card', 'netbanking' => 'Netbanking', 'wallet' => 'Wallet', 'emi' => 'EMI'][$p['method']] ?? ucfirst($p['method']);
    }
    return mb_substr(trim($how . ' ' . ($p['id'] ?? '')), 0, 64);
}

/** Returns ['status' => completed|pending|failed|canceled, 'ref' => text for the receipt]. */
function rzp_status($id)
{
    if (strpos($id, 'demo_') === 0) {
        $s = demo_store($id);
        return ['status' => $s === 'created' ? 'pending' : ($s ?: 'failed'), 'ref' => $s === 'completed' ? 'Practice' : ''];
    }
    if (strpos($id, 'qr_') === 0) {
        $pays = rzp_call('GET', '/payments/qr_codes/' . rawurlencode($id) . '/payments');
        foreach ($pays['items'] ?? [] as $p) {
            if (in_array($p['status'] ?? '', ['captured', 'authorized'], true)) {
                return ['status' => 'completed', 'ref' => rzp_payment_ref($p)];
            }
        }
        $qr = rzp_call('GET', '/payments/qr_codes/' . rawurlencode($id));
        if (($qr['status'] ?? '') === 'closed') {
            return ['status' => 'canceled', 'ref' => ''];
        }
        return ['status' => 'pending', 'ref' => ''];
    }
    $pl = rzp_call('GET', '/payment_links/' . rawurlencode($id));
    $st = $pl['status'] ?? 'created';
    if ($st === 'paid') {
        $p = null;
        foreach ($pl['payments'] ?? [] as $x) {
            if (($x['status'] ?? '') === 'captured') {
                $p = $x;
            }
        }
        $ref = '';
        if ($p && !empty($p['payment_id'])) {
            try {
                $ref = rzp_payment_ref(rzp_call('GET', '/payments/' . rawurlencode($p['payment_id'])));
            } catch (Throwable $e) {
                $ref = $p['payment_id'];
            }
        }
        return ['status' => 'completed', 'ref' => $ref];
    }
    if ($st === 'cancelled' || $st === 'expired') {
        return ['status' => 'canceled', 'ref' => ''];
    }
    return ['status' => 'pending', 'ref' => ''];
}

/** Stops a QR code or payment link from taking money (the sale was cancelled at the till). */
function rzp_close($id)
{
    if (strpos($id, 'demo_') === 0) {
        demo_store($id, 'canceled');
        return;
    }
    try {
        if (strpos($id, 'qr_') === 0) {
            rzp_call('POST', '/payments/qr_codes/' . rawurlencode($id) . '/close');
        } else {
            rzp_call('POST', '/payment_links/' . rawurlencode($id) . '/cancel');
        }
    } catch (Throwable $e) {
        error_log('FOMAXO POS: could not close Razorpay ' . $id . ': ' . $e->getMessage());
    }
}

function demo_store($id, $setStatus = null)
{
    $file = dirname(__DIR__) . '/data/pay-demo.json';
    $all = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    if ($setStatus !== null) {
        $all[$id] = $setStatus;
        file_put_contents($file, json_encode($all), LOCK_EX);
        return $setStatus;
    }
    return $all[$id] ?? null;
}
