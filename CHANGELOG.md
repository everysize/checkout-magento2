# 1.0.0
- Initial release: imports everysize orders into the shop (products with
  size variants, guest customer, the everysize payment method "everysize
  (PayPal)" as an offline method, invoice, the configured order status) and
  reports the merchant order number back to everysize
- Reports shipping with the tracking code and the carrier, tracking codes
  added or corrected later (up to 14 days, only if everysize does not have
  them yet), cancellations/returns (MERCHANT_DECLINE before the first
  shipping report, RETOUR after it) and automatic full refunds for
  cancellations before shipping (setting, on by default)
- Refunds from the shop: credit memo from the invoice with "Refund"
  (online, partial amounts included) or the button in the order tab
  "everysize"; everysize refunds the PayPal payment on the merchant's
  behalf. "Refund Offline" only books the credit memo. A form sent twice
  refunds once; after any refund nothing is refunded automatically
- Tab "everysize" in the order: everysize order number, PayPal capture ID,
  the customer's original e-mail address (information only), import time,
  what has been reported and the refund form
- Order grid: column "everysize" with the everysize order number
  (filterable, sortable)
- Settings under Sales → everysize Checkout: credentials (encrypted in the
  shop database), connection test with the everysize shop ID and the
  connected environment, removing the credentials, import store view and
  order status, shop order confirmation on/off, automatic synchronization
  on/off, automatic refund for cancellations before shipping
- Synchronization command `everysize:sync` (import + reports, for the cron
  job) and the half-steps `everysize:order:import` / `everysize:order:export`;
  runs every 5 minutes in the default cron group
- Sync protocol page under Sales → everysize Checkout → Protocol: runs that
  transmitted something or failed, filter all/ok/error, 31 days
- Test environment: `EVERYSIZE_CHECKOUT_API_URL` accepts any `https://`
  address under `everysize.com` (no port or path) instead of a fixed list;
  the admin page then shows "test environment"
- German and English translations; source strings are English
- License: MIT
