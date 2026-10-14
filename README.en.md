# everysize Checkout — Module for Magento 2

Connects a Magento shop to the everysize direct purchase: orders placed on
everysize.com arrive as orders in the shop; the merchant order number,
shipping, cancellations, returns and refunds are reported back to everysize.

Composer package `everysize/checkout-magento2`, module `Everysize_Checkout`.

## Requirements

* Magento Open Source 2.4.4 or newer (tested with 2.4.9/PHP 8.4);
  Adobe Commerce works as well
* PHP ≥ 8.1 with `curl` and `json`
* Magento cron (`bin/magento cron:run`) — the synchronisation runs in the
  default cron group

No PayPal module needed: payment happens at everysize. Imported orders carry
the payment method "everysize (PayPal)" with a paid invoice. Refunds go
through everysize (the money goes back through the PayPal account the
customer paid with) — see "Returns and refunds".

## Flow

| Direction | What | When |
|---|---|---|
| everysize → shop | new orders (`PROCESSING`, including already-revoked ones) without a merchant order number | every run |
| shop → everysize | the Magento order number | directly after the import |
| shop → everysize | shipping with tracking code and carrier | shipment created |
| shop → everysize | a tracking code added or corrected later | up to 14 days after the shipping report, only if everysize does not have it yet |
| shop → everysize | refund (full or partial) | credit memo with "Refund" (online) or button in the "everysize" tab |
| shop → everysize | cancellation/return as a report | item cancelled or credit memo created |

The synchronisation runs every 5 minutes (cron, setting "Automatic
synchronization") and covers both directions in one run. Manually:

```bash
bin/magento everysize:sync              # import + reports
bin/magento everysize:order:import      # import only
bin/magento everysize:order:export      # reports only
```

## Installation

Via Composer (`composer require everysize/checkout-magento2`) or as a ZIP: unpack
the ZIP in the shop's `app/code` folder (it creates `app/code/Everysize/Checkout`
with `registration.php` and `etc/`). Then:

```bash
bin/magento module:enable Everysize_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

## Configuration

Sales → everysize Checkout → Settings:

* **Credentials** — client ID and client secret from everysize Business
  ("Shop integration" → Magento 2). The secret is stored encrypted in the
  shop database and is not readable from there alone. The "Test connection"
  button shows the everysize shop ID and the connected environment.
* **Import** — store view for imported orders, order status (default
  "Processing") and whether the order confirmation goes out as a shop mail
  (the module sends it itself after saving; the customer's mail always goes
  to the everysize forwarding address, the original address is shown in the
  "everysize" tab for information only).
* **Synchronization** — automatic synchronization (cron) on/off.
* **Refunds** — whether a cancellation before shipping is refunded in full
  automatically through everysize (on, default) and from when on.

The module settings apply shop-wide (one everysize connection per
installation).

## Returns and refunds

* **Credit memo from the invoice, "Refund" (online)** — order → "Invoices"
  tab → open the invoice → "Credit Memo" → quantities/amounts →
  **"Refund"**. The credit memo's amount goes back through everysize to
  PayPal right away, partial amounts included; if everysize/PayPal refuse,
  no credit memo is created and Magento shows the reason. This is the normal
  way.
* **"Refund Offline"** only books the credit memo and moves no money — for
  refunds made directly in the PayPal account. A credit memo started from
  the order view ("Credit Memo" button at the top) is always offline in
  Magento.
* Every credit memo reports the affected items to everysize: before the
  reported shipping as a cancellation (`MERCHANT_DECLINE`), after it as a
  return.
* A cancellation before shipping without any refund is refunded in full
  automatically in the next run (setting, see above). Once anything was
  refunded through everysize, nothing is refunded automatically.

## The "everysize" tab in the order

everysize order number, PayPal transaction (capture ID), the customer's
original e-mail address (information only), what has been reported, and the
refund form for amounts without a credit memo: amount (pre-filled with the
open amount), full or partial — a form sent twice refunds once. Order status and tracking codes are reported by the
module on its own.

## Order grid

A column "everysize" with the everysize order number, filterable and
sortable.

## Test environment

The module reads the API address from the environment variable
`EVERYSIZE_CHECKOUT_API_URL` (only `https://` addresses under
`everysize.com`): if it is set, the settings page shows the test environment
and nothing reaches production. Without the variable the module always runs
against production.

## Tests

The unit tests run without Magento (pure PHP classes with fakes for
database and HTTP):

```bash
composer install --working-dir=test-vendor   # once
test-vendor/bin/phpunit --testdox
```

A syntax check of all source files against PHP 8.1 (the module's minimum)
can be run with a PHP 8.1 container:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.1-cli \
    sh -c 'find . -name "*.php" -not -path "./test-vendor/*" | xargs -n1 php -l'
```

## License

MIT — see `LICENSE`.
