# 1.0.0
- Erste Version: importiert everysize-Bestellungen in den Shop (Produkte
  mit Größenvarianten, Gastkunde, die everysize-Zahlungsart „everysize
  (PayPal)“ als Offline-Methode, Rechnung, der eingestellte Bestellstatus)
  und meldet die Shop-Bestellnummer an everysize zurück
- Meldet Versand mit Sendungsnummer und Versanddienstleister, nachgetragene
  oder korrigierte Sendungsnummern (bis 14 Tage, nur wenn everysize sie
  noch nicht hat), Storno/Retoure (MERCHANT_DECLINE vor der ersten
  Versandmeldung, RETOUR danach) und Stornos vor dem Versand automatisch
  voll erstattet (Einstellung, Standard an)
- Erstattungen aus dem Shop: Gutschrift aus der Rechnung mit „Erstatten“
  (online, auch teilweise) oder Button im Reiter „everysize“; everysize
  erstattet die PayPal-Zahlung im Auftrag des Händlers. „Offline
  erstatten“ bucht nur die Gutschrift. Ein zweimal abgeschicktes Formular
  erstattet nur einmal; nach einer Erstattung wird nichts mehr automatisch
  erstattet
- Reiter „everysize“ in der Bestellung: everysize-Bestellnummer,
  PayPal-Capture-ID, die Original-E-Mail der Kundin (nur zur Information),
  Import-Zeit, was gemeldet ist, und das Erstattungs-Formular
- Bestellliste: Spalte „everysize“ mit der everysize-Bestellnummer
  (filter- und sortierbar)
- Einstellungen unter Verkäufe → everysize Checkout: Zugangsdaten
  (verschlüsselt in der Shop-Datenbank), Verbindungstest mit der
  everysize-Shop-ID und der verbundenen Umgebung, Zugangsdaten entfernen,
  Store-View und Bestellstatus für den Import, Bestellbestätigung an/aus,
  automatischer Abgleich an/aus, automatische Erstattung für Stornos vor
  dem Versand
- Abgleich per `everysize:sync` (Import + Rückmeldungen, für den Cronjob)
  und die Halb-Schritte `everysize:order:import` / `everysize:order:export`;
  alle 5 Minuten in der Standard-Cron-Gruppe
- Protokoll-Seite unter Verkäufe → everysize Checkout → Protokoll: Läufe,
  die etwas übertragen haben oder fehlgeschlagen sind, Filter
  alle/erfolgreich/Fehler, 31 Tage
- Testumgebung: `EVERYSIZE_CHECKOUT_API_URL` akzeptiert jede
  `https://`-Adresse unter `everysize.com` (kein Port, kein Pfad) statt
  einer festen Liste; die Einstellungsseite zeigt dann die Testumgebung an
- Deutsche und englische Übersetzungen; Quelltexte englisch
- Lizenz: MIT
