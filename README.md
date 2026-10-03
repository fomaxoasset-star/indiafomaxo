# indiafomaxo
## Product reviews

Reviews are stored by `api/reviews.php` (PHP + SQLite) in a `fomaxo-private` folder next to `public_html`, so deploys never touch them.

- Customers rate 1–5 stars, write a review, give a name or post anonymously, add up to 3 photos, and mark reviews helpful.
- **Verified Purchaser**: after a payment is received, open `fomaxo.in/#/owner`, record the order, and send the customer the review link it creates. Reviews written from that link carry the badge, once per product.
- The owner page needs an owner key: a `config.php` in `fomaxo-private` (or `api/private-config.php`) returning `['admin_key_hash' => password_hash('your key', PASSWORD_DEFAULT)]`. Add `'moderate' => true` to hold new reviews until you publish them.
