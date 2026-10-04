<?php
/* Copy this file to  domains/fomaxo.in/fomaxo-private/razorpay-config.php  (the folder NEXT TO public_html, not inside it)
   and paste your keys from the Razorpay Dashboard → Account & Settings → API Keys.
   Test keys start with rzp_test_ (no real money); live keys start with rzp_live_.
   Check the set-up any time by opening  https://fomaxo.in/api/razorpay.php?status  (it never shows the keys). */
return [
  'razorpay_key_id'     => 'rzp_test_PASTE_YOUR_KEY_ID',
  'razorpay_key_secret' => 'PASTE_YOUR_KEY_SECRET',
  // Optional: Razorpay → Settings → Webhooks → URL https://fomaxo.in/api/razorpay.php, events payment.captured and order.paid,
  // with this same secret. It records a payment even if the customer closes the page before returning.
  'razorpay_webhook_secret' => '',
];
