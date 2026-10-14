# everysize Checkout — Modul für Magento 2

Bindet einen Magento-Shop an den everysize-Direktkauf an: Bestellungen, die
Kundinnen auf everysize.com aufgeben, landen als Bestellung im Shop;
Bestellnummer, Versand, Storno, Retoure und Erstattungen gehen zurück an
everysize.

Composer-Paket `everysize/checkout-magento2`, Modul `Everysize_Checkout`.

## Voraussetzungen

* Magento Open Source 2.4.4 oder neuer (getestet mit 2.4.9/PHP 8.4);
  Adobe Commerce funktioniert ebenso
* PHP ≥ 8.1 mit `curl` und `json`
* Magento-Cron (`bin/magento cron:run`) — der Abgleich hängt an der
  Standard-Cron-Gruppe

Kein PayPal-Modul nötig: bezahlt wird bei everysize. Importierte Bestellungen
tragen die Zahlungsart „everysize (PayPal)“ mit einer bezahlten Rechnung.
Erstattet wird über everysize (das Geld geht über das PayPal-Konto zurück,
mit dem die Kundin bezahlt hat) — siehe „Retoure und Erstattung“.

## Ablauf

| Richtung | Was | Wann |
|---|---|---|
| everysize → Shop | neue Bestellungen (`PROCESSING`, auch bereits stornierte `REVOKED`) ohne Händler-Bestellnummer | jeder Lauf |
| Shop → everysize | Magento-Bestellnummer | direkt nach dem Import |
| Shop → everysize | Versand mit Sendungsnummer und Versanddienstleister | Sendung angelegt |
| Shop → everysize | Sendungsnummer nachgetragen oder korrigiert | bis 14 Tage nach der Versandmeldung, nur wenn everysize sie noch nicht hat |
| Shop → everysize | Erstattung (ganz oder teilweise) | Gutschrift mit „Erstatten“ (online) oder Button im Reiter „everysize“ |
| Shop → everysize | Storno/Retoure als Rückmeldung | Artikel storniert oder Gutschrift angelegt |

Der Abgleich läuft alle 5 Minuten (Cron, Einstellung „Automatischer
Abgleich“) und in einem Lauf in beide Richtungen. Manuell:

```bash
bin/magento everysize:sync              # Import + Rückmeldungen
bin/magento everysize:order:import      # nur Import
bin/magento everysize:order:export      # nur Rückmeldungen
```

## Installation

Per Composer (`composer require everysize/checkout-magento2`) oder als ZIP: das ZIP
im Ordner `app/code` des Shops entpacken (es legt `app/code/Everysize/Checkout`
mit `registration.php` und `etc/` an). Danach:

```bash
bin/magento module:enable Everysize_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

## Konfiguration

Verkäufe → everysize Checkout → Einstellungen:

* **Zugangsdaten** — Client-ID und Client-Secret aus everysize Business
  („Shop-Integration“ → Magento 2). Das Secret wird verschlüsselt in der
  Shop-Datenbank gespeichert und ist dort allein nicht lesbar. Der
  „Verbindung testen“-Button zeigt die everysize-Shop-ID und die
  verbundene Umgebung.
* **Import** — Store-View für importierte Bestellungen, Bestellstatus
  (Standard „Verarbeitung“) und ob die Bestellbestätigung per Shop-Mail
  geht (dann versendet das Modul sie selbst nach dem Speichern; die Mail
  der Kundin geht in jedem Fall an die everysize-Weiterleitungsadresse,
  die Original-Adresse steht nur zur Information im Reiter „everysize“).
* **Abgleich** — Automatischer Abgleich (Cron) an/aus.
* **Erstattungen** — Ob ein Storno vor dem Versand automatisch voll über
  everysize erstattet wird (an, Standard) und ab wann.

Die Moduleinstellungen gelten shopweit (eine everysize-Verbindung pro
Installation).

## Retoure und Erstattung

* **Gutschrift aus der Rechnung, „Erstatten“ (online)** — Bestellung →
  Reiter „Rechnungen“ → Rechnung öffnen → „Gutschrift“ → Mengen/Beträge
  → **„Erstatten“**. Der Betrag der Gutschrift geht sofort über everysize
  an PayPal zurück, auch teilweise; lehnt everysize/PayPal ab, wird keine
  Gutschrift angelegt und Magento zeigt den Grund. Das ist der normale Weg.
* **„Offline erstatten“** bucht nur die Gutschrift und bewegt kein Geld —
  für Erstattungen, die direkt im PayPal-Konto gemacht wurden. Eine
  Gutschrift aus der Bestellansicht (Button „Gutschrift“ oben) ist in
  Magento immer offline.
* Jede Gutschrift meldet die betroffenen Artikel an everysize: vor dem
  gemeldeten Versand als Storno (`MERCHANT_DECLINE`), danach als Retoure.
* Ein Storno vor dem Versand ohne jede Erstattung erstattet das Modul im
  nächsten Lauf automatisch voll (Einstellung, siehe oben). Wurde schon
  etwas über everysize erstattet, wird nichts mehr automatisch erstattet.

## Reiter „everysize“ in der Bestellung

everysize-Bestellnummer, PayPal-Transaktion (Capture-ID), die Original-
E-Mail der Kundin (nur zur Information), was gemeldet ist, und das
Erstattungs-Formular für Beträge ohne Gutschrift: Betrag (vorbelegt mit dem
offenen Betrag), ganz oder teilweise — ein Formular, das zweimal abgeschickt
wird, erstattet nur einmal.
Bestellstatus und Sendungsnummern meldet das Modul selbstständig.

## Bestellliste

Spalte „everysize“ mit der everysize-Bestellnummer, filter- und
sortierbar.

## Testumgebung

Das Modul liest die API-Adresse aus der Umgebungsvariable
`EVERYSIZE_CHECKOUT_API_URL` (nur `https://`-Adressen unter
`everysize.com`): ist sie gesetzt, zeigt die Einstellungsseite die
Testumgebung an und überträgt nichts in die Produktion. Ohne die Variable
läuft das Modul immer gegen die Produktion.

## Tests

Die Unit-Tests laufen ohne Magento (reine PHP-Klassen mit Fakes für
Datenbank und HTTP):

```bash
composer install --working-dir=test-vendor   # einmalig
test-vendor/bin/phpunit --testdox
```

Ein Syntax-Check aller Quelldateien gegen PHP 8.1 (die Untergrenze des
Moduls) lässt sich mit einem PHP-8.1-Container fahren:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.1-cli \
    sh -c 'find . -name "*.php" -not -path "./test-vendor/*" | xargs -n1 php -l'
```

## Lizenz

MIT — siehe `LICENSE`.
