# indiafomaxo
## Product reviews

Reviews are stored by `api/reviews.php` (PHP + SQLite) in a `fomaxo-private` folder next to `public_html`, so deploys never touch them.

- Customers rate 1–5 stars, write a review, give a name or post anonymously, add up to 3 photos, and mark reviews helpful.
- **Verified Purchaser**: after a payment is received, open `fomaxo.in/#/owner`, record the order, and send the customer the review link it creates. Reviews written from that link carry the badge, once per product.
- The owner page needs an owner key: a `config.php` in `fomaxo-private` (or `api/private-config.php`) returning `['admin_key_hash' => password_hash('your key', PASSWORD_DEFAULT)]`. Add `'moderate' => true` to hold new reviews until you publish them.

## Checkout and payments

`fomaxo.in/#/checkout` takes delivery details and payment. The bag's **Checkout** button and every **Buy now** button lead there.

- **Online payment (UPI, cards, netbanking, wallets)** runs through Razorpay. `api/razorpay.php` prices the order on the server from `index.html`, creates the Razorpay order and checks Razorpay's signature before an order counts as paid.
- **Razorpay keys**: copy `api/razorpay-config.example.php` to `fomaxo-private/razorpay-config.php` (next to `public_html`) and paste the Key ID and Key Secret from the Razorpay Dashboard. Test keys (`rzp_test_…`) take no real money. Open `fomaxo.in/api/razorpay.php?status` to check the set-up.
- **Cash on delivery**: `STORE.checkout.cod` in `index.html` sets the minimum order and fee (`cod: null` turns it off). Orders go through `api/cod.php`.
- Every order is emailed to the store and the customer and saved in `fomaxo-private/orders/orders.csv`.
