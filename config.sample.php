<?php
/*
 * FOMAXO India Store System - settings
 *
 * install.php writes these for you (as fomaxo-pos-in-config.php, one folder ABOVE the app folder,
 * next to public_html, where deploys never touch it and no visitor can open it).
 * Never share that file - it holds the database password.
 *
 * Razorpay keys, GSTIN and the store UPI ID are best set inside the app: menu → Settings.
 */

return [
    // ---- Database (Hostinger: Websites > Databases > MySQL Databases) ----
    // 'mysql' on Hostinger, or 'sqlite' for the built-in database file (db_path, outside public_html).
    'db_driver' => 'mysql',
    'db_host'   => 'localhost',        // on Hostinger this is always localhost
    'db_name'   => 'u123456789_fomaxoposin',
    'db_user'   => 'u123456789_posin',
    'db_pass'   => 'CHANGE-ME',
    // 'db_path' => '/home/u123456789/domains/pos.fomaxo.in/fomaxo-pos-in.sqlite',

    // ---- Store details (printed on every receipt) ----
    'store_name'    => 'FOMAXO',
    'store_tagline' => 'For Outstanding Minds, Acquiring Xtraordinary Opulence',
    'store_address' => 'Store address, Kerala, India',
    'store_phone'   => '+91 73491 05165',
    'store_gstin'   => '',             // GSTIN - GST on sales and Tax invoices once filled in
    'receipt_footer'=> 'Thank you for shopping with FOMAXO',

    // ---- Money ----
    'currency'           => 'INR',
    'gst_rate'           => 18,         // GST % on perfumes
    'prices_include_vat' => true,       // true = shelf prices already include GST
    'default_low_stock'  => 5,          // "running low" warning level for new products
    'staff_max_discount' => 10,         // biggest discount % staff can give (managers: no limit)

    // ---- Full web address of this app, no slash at the end ----
    'base_url' => 'https://pos.fomaxo.in',

    // ---- Payments ----
    // 'demo' = practice page, no Razorpay account needed (try the app first); 'live' = use the Razorpay keys
    // (rzp_test_ keys take no real money, rzp_live_ keys do). With no keys here or in Settings, the keys of
    // the fomaxo.in shop (fomaxo.in/fomaxo-private/razorpay-config.php) are used.
    'razorpay_mode'       => 'demo',
    'razorpay_key_id'     => '',
    'razorpay_key_secret' => '',
    'razorpay_qr'         => 'upi',     // 'upi' = UPI QR code (PhonePe, Google Pay, Paytm...), 'link' = Razorpay payment link
    'store_upi'           => '',        // the store's own UPI ID (e.g. fomaxo@ybl): the till shows a QR for the exact amount

    // ---- Products come from the website and stay in sync (every 10 minutes) ----
    'website_products_url' => 'https://fomaxo.in/api/pos-products.php',
    'website_auto_sync'    => true,

    // ---- FOMAXO Elite tiers: yearly spend in ₹ (store + fomaxo.in) ----
    'tiers' => [
        ['name' => 'Imperial', 'min' => 800000],
        ['name' => 'Elite',    'min' => 400000],
        ['name' => 'Gold',     'min' => 200000],
        ['name' => 'Black',    'min' => 100000],
    ],

    // Show errors on screen. Keep false on the live site.
    'debug' => false,
];
