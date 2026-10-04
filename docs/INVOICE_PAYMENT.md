# Rechnungszahlung – Version 2026092502

Diese Erweiterung basiert auf `alexmorrisnz/moodle-paygw_stripe`, Commit
`06d4cabcf415d8649624608a2d1a926aabcb1c82`. Sie ergänzt die Zahlungsart
**Invoice payment / Rechnungszahlung** im bestehenden Stripe-Zahlungsaccount.

## Installation und Einrichtung

1. Das ZIP als Moodle-Plugin `paygw_stripe` installieren beziehungsweise den
   vorhandenen Ordner `payment/gateway/stripe` durch den enthaltenen Ordner
   `stripe` ersetzen. Danach die Moodle-Datenbankaktualisierung ausführen.
2. Im betreffenden Moodle-Zahlungsaccount beim Stripe-Gateway die Zahlungsart
   **Rechnungszahlung** wählen. Für EUR-Rechnungen das **Bankland für
   EUR-Rechnungen** wählen (Standard Deutschland/DE; außerdem FR, IE und NL).
   Das ist das Land der von Stripe erzeugten Bankverbindung, nicht das Land der
   Rechnungsadresse. Einmalzahlung und Abonnement bleiben verfügbar.
3. Stripe-Schlüssel müssen den Zugriff auf Customers, Customer Portal
   Configurations/Sessions, Products, Prices, Invoice Items, Invoices und Webhook
   Endpoints erlauben. Customer Portal und das Unternehmensprofil in Stripe
   einrichten; vorhandene Rechnungs-/Zahlungsarteneinstellungen in Stripe prüfen.
   Ab 2026092502 außerdem Lesezugriff auf Invoice Payments und Payment Intents
   erlauben. Die Rechnungseinstellungen in Stripe müssen mindestens eine für
   die Rechnung nutzbare Zahlungsart enthalten.
4. Moodle muss über HTTPS erreichbar sein. Der bestehende Webhook-Endpunkt
   `/payment/gateway/stripe/webhook.php` muss von Stripe erreichbar sein.
5. Vor dem Produktiveinsatz die untenstehenden Abläufe mit Stripe-Testschlüsseln
   und einer Moodle-Testinstanz durchführen.

Ab Version **2026092502** bleiben bei neuen EUR-Rechnungskäufen die von Stripe
ermittelten Rechnungszahlungsarten erhalten. Die Einschränkung auf ausschließlich
Banküberweisung aus Version 2026092501 ist für neue Käufe behoben.

Stripe bestimmt beim Finalisieren zunächst selbst die verfügbaren Zahlungsarten
aus seinen Rechnungseinstellungen und Kundenvorgaben, einschließlich der
Beschränkungen für Währung, Betrag und Konto. Das Plugin liest die vollständige
Liste aus dem Standard-PaymentIntent der Rechnung über Invoice Payments.
Es bearbeitet den PaymentIntent nicht direkt. Über ein anschließendes
Invoice-Update wird EU-Banküberweisung ergänzt, bevor die Rechnung per E-Mail
versendet und ihre Hosted Invoice Page geöffnet wird:

- `payment_method_types` = von Stripe ermittelte Liste plus `customer_balance`
- `payment_method_options.customer_balance.funding_type = bank_transfer`
- `payment_method_options.customer_balance.bank_transfer.type = eu_bank_transfer`
- `payment_method_options.customer_balance.bank_transfer.eu_bank_transfer.country`
  entspricht dem konfigurierten Bankland.

EUR-Rechnungen bieten damit Banküberweisung zusätzlich an. Welche weiteren
Methoden tatsächlich angeboten werden, bestimmt Stripe anhand der konkreten
Rechnung; das Plugin erzwingt weder Karte noch eine feste Liste. Das Stripe-Konto
muss diese Zahlungsart unterstützen und freigeschaltet haben. Stripe erzeugt die
Bankverbindung und zeigt die Zahlungsanweisungen auf Rechnung/PDF und Hosted
Invoice Page. Andere Währungen verwenden weiter die Stripe-Invoicing-Vorgaben.

Version 2026092501 ergänzte das nullable Feld `banktransfercountry`.
Version 2026092502 ergänzt `paymentmethodstatus` und `paymentmethods` für die
einmalige Vorbereitung und den Wiederholungsfall. Die
Moodle-Datenbankaktualisierung ist erforderlich. Das Bankland wird beim Kaufstart
gespeichert und bleibt für Wiederholungen unverändert. Bestehende Kaufversuche
behalten ihren bisherigen Modus (`paymentmethodstatus=legacy`) und ihre
bisherigen Zahlungsparameter, auch wenn ein früherer Stripe-Aufruf eine verlorene
Antwort hatte. Bereits erzeugte Rechnungen werden nicht nachträglich geändert.
Insbesondere behalten Rechnungen aus Version 2026092501 ihre bisherige Auswahl;
deren Zahlungsarten können bei Bedarf direkt in Stripe geändert werden.
Zum Prüfen der neuen Funktion einen neuen EUR-Rechnungskauf starten.

Ab Version **2026092500** ist der E-Mail-Versand für neu begonnene Rechnungskäufe
automatisch aktiv. Es ist kein zusätzlicher Plugin-Schalter und keine
Moodle-SMTP-Konfiguration erforderlich: Stripe übernimmt den Versand an die im
Portal gespeicherte E-Mail-Adresse. Der Stripe-Schlüssel benötigt Schreibzugriff
auf Invoices einschließlich des Versandaufrufs. Die Zahlungsart muss weiterhin
**Rechnungszahlung** sein; „Einmalig“ mit „Automatic Invoices“ verwendet den
bisherigen Checkout-Ablauf.

Beim Update von 2026092400 die Moodle-Datenbankaktualisierung vollständig
ausführen, bevor der neue Kaufablauf verwendet wird. Sie ergänzt die drei
Versandfelder; vorhandene Kaufversuche erhalten `emailstatus=legacy` und werden
auch bei einem erneuten Callback nicht nachträglich versendet. Diese Rechnungen
können bei Bedarf direkt in Stripe versendet werden. Ein neu begonnener Kauf
erhält `emailstatus=pending`.

Die Migration ergänzt `invoice.paid` und `invoice.voided` an bestehenden Webhooks,
behält weitere Ereignisse, Signaturschlüssel und API-Versionen bei und legt die
lokale Rechnungstabelle an. Falls Stripe beim Upgrade nicht erreichbar ist, wird
die Ereigniskonfiguration vor dem nächsten Rechnungskauf erneut geprüft.

Ab Version 2026100400 verwenden alle Stripe-API-Aufrufe des Plugins
`2026-08-26.dahlia`, in der `flow_data.type=customer_update` verfügbar wurde.
Neue Webhook-Endpunkte werden mit dieser Version angelegt. Bereits bestehende
Endpunkte behalten ihre eigene festgelegte Event-Version und ihren Signaturschlüssel;
ihre Ereignisse werden weiterhin anhand der gespeicherten Zuordnung geprüft.
Das mitgelieferte Stripe-PHP-SDK verarbeitet die neuen Portal-Parameter mit
dieser expliziten API-Version.

## Ablauf

Moodle legt zunächst einen lokalen Kaufversuch an und verwendet die vorhandene
Zuordnung zwischen Moodle-Benutzer und Stripe Customer. Fehlende Kunden werden
angelegt. Für Portal-Kunden wird die Rechnungsidentität künftig von Stripe
verwaltet: Auch spätere Checkout-/Abonnementkäufe überschreiben deren Namen und
E-Mail nicht mehr aus dem Moodle-Profil. Spracheinstellungen werden weiterhin
aktualisiert. Adresse und Steuerdaten bleiben in Stripe.

Eine eigene Portal-Konfiguration erlaubt Name/Firmenname, Adresse, E-Mail und
VAT/Tax-ID. Rechnungshistorie, Zahlungsartenverwaltung, Abonnementverwaltung und
eine allgemeine Portal-Anmeldeseite sind deaktiviert. Eine fremde oder als
Standard markierte Portal-Konfiguration wird nicht umkonfiguriert.

Der direkte Customer-Update-Flow hat zwei verschiedene Rücksprungadressen:

- **Erfolgreiches Save:** `invoice.php`, mit einem zufälligen 256-Bit-Token und
  der lokalen Kauf-ID. Moodle prüft angemeldeten Benutzer, Token, Ablaufzeit,
  Portal-Session und Kaufstatus. Danach werden die aktuellen Customer-Daten aus
  Stripe gelesen. Rechnungsname, E-Mail sowie Adresse mit Straße und Land müssen
  vorhanden sein. Eine Tax-ID ist für Privatkunden nicht verpflichtend.
- **Normale Rückkehr:** `invoice_return.php`. Hier gibt es kein Erfolgstoken und
  keinen Aufruf zur Rechnungserstellung. Ein noch offener Erfassungsschritt wird
  abgebrochen. Browser-Zurück oder das Schließen der Portal-Seite führen ebenfalls
  nicht zur Rechnungserstellung; der unbestätigte lokale Kaufversuch läuft aus.

Nach erfolgreicher Rückkehr werden Product/Price wiederverwendet beziehungsweise
passend angelegt. Ein Entwurf mit `collection_method=send_invoice`,
`days_until_due=14` und `auto_advance=false` wird erstellt. Der Kaufartikel wird
**direkt diesem Entwurf** zugeordnet. Dadurch können andere offene Invoice Items
oder parallele Käufe nicht versehentlich in diese Rechnung aufgenommen werden.
Anschließend wird die Rechnung finalisiert. Bei neuen EUR-Käufen übernimmt
Moodle die von Stripe ermittelte Zahlungsartenliste und ergänzt Banküberweisung
über `invoices->update`. Erst nach erfolgreicher Vorbereitung ruft Moodle explizit
`invoices->sendInvoice($invoiceid, [], $options)` auf
(`POST /v1/invoices/{id}/send`) und leitet nach erfolgreicher API-Antwort zur
`hosted_invoice_url` um. `auto_advance=false` bleibt bestehen: Das Plugin löst
diesen Versand selbst aus. Auch eine bereits durch Guthaben bezahlte Rechnung
kann auf diese Weise als bezahlte Rechnung versendet werden.

Stripe verwendet die Rechnungs-E-Mail-Adresse aus den gespeicherten
Rechnungsdaten. Ein erfolgreicher API-Aufruf bestätigt die Annahme der
Versandanforderung, nicht die Zustellung im Posteingang. **Im Stripe-Testmodus
versendet dieser API-Aufruf keine echte E-Mail**, auch wenn `invoice.sent`
ausgelöst wird. Die tatsächliche Zustellung muss separat im Live-Betrieb geprüft
werden.

Stripe übernimmt Zahlung und Zahlungsarten auf der Hosted Invoice Page. Die
Checkout-Einstellungen für Gutscheine, Checkout-Zahlungsartenkonfiguration und
`invoicecreation` gelten für diese separate Rechnungsart nicht. Automatic Tax
und die konfigurierte Steuerbehandlung werden übernommen. Neue EUR-Käufe
verwenden die oben beschriebenen Banküberweisungsparameter; andere Währungen
und bestehende Kaufversuche folgen den Stripe-Invoicing-Einstellungen. Der in Moodle gespeicherte
Zahlungsbetrag entspricht dem tatsächlichen Rechnungsgesamtbetrag inklusive
gegebenenfalls berechneter Steuern, in der ursprünglichen Währung.

## Speicherung und zuverlässige Verarbeitung

`paygw_stripe_invoices` speichert Benutzer, ursprünglichen Zahlungsaccount,
Customer, Kaufreferenzen, Preis-/Betragsmomentaufnahme, Portal-Session,
Invoice/Invoice-Item, Status, Token-Hash, Zeitstempel sowie Moodle-Payment-ID und
`delivered`. `emailstatus`, `timeemailstarted` und `timeemailsent` speichern den
Versandstatus sowie den ersten Versandversuch und die erfolgreiche API-Annahme.
`banktransfercountry` speichert die Bankland-Auswahl neuer EUR-Käufe.
`paymentmethods` speichert die ermittelte Zahlungsartenliste als JSON vor dem
Invoice-Update; `paymentmethodstatus` wechselt von `pending` über `applying`
zu `ready`. Dadurch bleiben die Parameter des Idempotenzschlüssels
`payment-methods` auch bei verlorenen Antworten oder späteren Änderungen der
Stripe-Einstellungen identisch. Nach bestätigtem Erfolg wird nicht erneut
konfiguriert. Falls die Zahlungsarten nicht gelesen oder ergänzt werden können,
bleibt der Rechnungsversand aus; erneutes Laden setzt dieselbe Rechnung fort.
Bereits vollständig durch Guthaben bezahlte Rechnungen benötigen keine
Zahlungsartenauswahl und überspringen diesen Schritt.
`sent` bezeichnet die API-Annahme; `delivered` bezeichnet ausschließlich die
Bereitstellung des Moodle-Kaufs. `invoiceid` und `tokenhash` haben eindeutige
Indizes. Die Rechnung
enthält zusätzlich `userid`, `component`, `paymentarea`, `itemid`,
`transactionid`, `paymentaccountid`, `gateway`, `flow` und einen Site-Hash.

Der Webhook löst den ursprünglichen Account über die lokal gespeicherte
Invoice-ID auf. Die Metadaten eines eingehenden JSON-Objekts sind keine
Berechtigung zur Einschreibung. Erst nach Prüfung der Stripe-Signatur wird die
Rechnung bei Stripe erneut abgerufen und mit der lokalen Zuordnung abgeglichen.

Ausschließlich `invoice.paid` mit tatsächlich bezahlter Rechnung speichert die
Moodle-Zahlung und ruft `core_payment\helper::deliver_order` auf. Beide Schritte
und das Setzen von `delivered` laufen unter derselben Moodle-Sperre und in einer
Datenbanktransaktion. Der Versand und das Ereignis `invoice.sent` lösen keine
Zahlungsbuchung oder Einschreibung aus. Ein Fehler oder ein `false`-Ergebnis bei
der Auslieferung rollt die Datenbankänderungen zurück; HTTP 500 veranlasst Stripe
zur Wiederholung.
Die üblichen Moodle-Zahlungsanbieter müssen ihre Auslieferung wie üblich in der
Moodle-Datenbank ausführen; externe Nebenwirkungen eigener Zahlungsanbieter
benötigen deren eigene Idempotenz.

Wiederholte oder parallele Ereignisse erzeugen damit keine zweite Zahlung oder
Einschreibung. `invoice.voided` markiert eine unbezahlte Rechnung als `void`.
Danach kann ein neuer Kauf eine neue Rechnung erhalten. Bereits ausgelieferte
Käufe bleiben bei späteren Buchhaltungskorrekturen unverändert. Unbekannte
Rechnungen, einschließlich Checkout-, Abo- und Ersatzrechnungen, werden ignoriert.

## Rücksprungschutz und Fehlerbehandlung

Das Erfolgstoken wird nur über den serverseitigen Stripe-Aufruf als
`after_completion.redirect.return_url` übergeben. Die normale Return-URL enthält
dieses Token nicht. Lokal liegt nur der SHA-256-Hash; die Callback-Antworten
setzen `Cache-Control: no-store` und `Referrer-Policy: no-referrer`.

Ein unbestätigter Kaufversuch ist maximal eine Stunde gültig, bei kürzerem
Moodle-Sitzungslimit entsprechend kürzer. Eine Wiederholung eines erfolgreichen
Callbacks kann ausschließlich dieselbe Rechnung öffnen beziehungsweise den
angefangenen Vorgang fortsetzen. Ein Token kann weder auf einen anderen Benutzer
noch auf einen anderen Kauf übertragen werden.

Stripe stellt für diesen Portal-Flow keine separat prüfbare, signierte
Save-Bestätigung und keinen auslesbaren Abschlussstatus der Portal-Session
bereit. Die Unterscheidung Save/Abbruch beruht daher auf Stripes dokumentiertem
`after_completion`-Redirect und der geheimen, gebundenen Fortsetzungsadresse.
Die Adresse ist vertraulich zu behandeln und sollte in Webserver-/Proxy-Logs
redigiert werden. Die Tests simulieren diese API-Verträge; tatsächliches
Save-/Cancel-Verhalten ist mit dem eigenen Stripe-Testkonto zu prüfen.

Rechnungserstellung, Artikelanlage und Finalisierung verwenden pro Kauf und
Operation stabile Stripe-Idempotenzschlüssel und persistente Zwischenstände.
Nach einem Verbindungsfehler kann dieselbe Callback-Adresse erneut aufgerufen
werden. Weil Stripe Idempotenzschlüssel nach 24 Stunden entfernen darf, werden
unvollständige Rechnungserstellungen nach 23 Stunden nicht automatisch neu
angestoßen. In diesem Fall zeigt Moodle eine Aufforderung zur Prüfung an.
Die Administration kann den Vorgang anhand `transactionid` in Stripe prüfen,
einen unbrauchbaren Entwurf löschen beziehungsweise eine offene Rechnung stornieren
und den Benutzer danach neu kaufen lassen. Keine automatische Ersatzrechnung
oder Stornierung bereits bezahlter Rechnungen erfolgt.

Der Versand verwendet zusätzlich einen eigenen stabilen Idempotenzschlüssel
pro Rechnungskauf. Die Sperre, der vor dem Aufruf gespeicherte Versandversuch und
der persistente Erfolgsstatus verhindern erneute Versandanforderungen bei
parallelen Callbacks oder verlorenen Antworten. Scheitert die Versandanforderung,
bleibt dieselbe finalisierte Rechnung erhalten; Moodle zeigt eine Fehlermeldung
und leitet zunächst nicht weiter. Erneutes Laden derselben Callback-Seite setzt
den Versand mit demselben Schlüssel fort. Es gibt keinen separaten Cron-Versand.

Bei einem unklaren Versandstatus nach 23 Stunden fordert Moodle eine Prüfung
durch die Administration an, bevor erneut versendet wird. So wird ein eventuell
bereits ausgeführter Versand nach Ablauf der Stripe-Idempotenzfrist nicht
automatisch wiederholt. Die Rechnung anhand ihrer Invoice-ID in Stripe prüfen
und nur bei Bedarf dort manuell versenden; ihre Hosted Invoice Page bleibt
nutzbar und `invoice.paid` wird weiterhin verarbeitet. Ein bereits gespeicherter
Erfolgsstatus verhindert auch nach Ablauf dieser Frist einen erneuten API-Aufruf.

## Tests und Abnahme

Für Version 2026092502 wurden die Syntaxprüfung mit PHP 8.4.24 und alle
Standalone-Tests unter `tests/standalone/` erfolgreich ausgeführt: **37 Szenarien,
365 Prüfungen**, einschließlich Mehrprozess-Tests und Schemaabgleich von
Neuinstallation und Upgrade. Diese verwenden echte Plugin-Services,
Repositories und das mitgelieferte Stripe-SDK. Stripe-HTTP und Moodle-Infrastruktur
werden durch Testadapter ersetzt; SQLite prüft Transaktionen und Eindeutigkeit,
Dateisperren prüfen gleichzeitige Aufrufe in mehreren Prozessen.

```bash
php tests/standalone/run.php
```

Benötigt: PHP 8.1+, `pdo_sqlite`, `simplexml`, `mbstring`. Mit `pcntl` läuft
zusätzlich der Mehrprozess-Test. Diese Tests sind kein Live-End-to-End-Test;
es wurden keine echten Stripe-Objekte oder Moodle-Einschreibungen erzeugt.

Für die Abnahme in einer Moodle-/Stripe-Testumgebung:

1. **Neuinstallation und Upgrade:** Schema inklusive eindeutiger Invoice-ID
   prüfen; vorhandene Checkout-/Abo-Daten bleiben nutzbar.
2. **Abbruch:** Neues Portal öffnen und jeweils Cancel, Return, Browser-Zurück
   oder Schließen verwenden. In Stripe darf keine Rechnung entstehen.
3. **Speichern:** Firmenname, Adresse, E-Mail und optionale Tax-ID speichern.
   Hosted Invoice Page prüfen: korrekte Identität, Kaufartikel, Betrag, Steuer,
   Währung und 14 Tage Zahlungsziel. In Stripes API-Protokoll muss nach der
   Finalisierung genau ein erfolgreicher `/send`-Aufruf stehen. Noch kein
   Kurszugang. Testmodus: `invoice.sent` prüfen, keine echte E-Mail erwarten.
4. **EUR-Zahlungsauswahl:** In Stripe mehrere Rechnungszahlungsarten aktivieren
   und einen neuen EUR-Kauf durchführen. Auf der Hosted Invoice Page müssen die
   für diese Rechnung verfügbaren Arten neben Banküberweisung auswählbar sein.
   Im API-Protokoll zuerst Finalisierung, dann Invoice-Payment-Abfrage und
   Invoice-Update, dann Versand prüfen. Beim Update müssen
   die bisherigen Zahlungsarten sowie `customer_balance`, `bank_transfer`,
   `eu_bank_transfer` und das gewählte Bankland enthalten sein.
   Bankverbindung und Zahlungsanweisungen auf Hosted Invoice
   Page und PDF prüfen. Einen Test-Zahlungseingang simulieren: Kurszugang erst
   nach `invoice.paid`; dasselbe Ereignis mehrfach senden und genau eine
   Moodle-Zahlung bestätigen. Zusätzlich eine Nicht-EUR-Rechnung prüfen.
5. **Rücksprünge:** Callback erneut öffnen, anderen Benutzer verwenden und
   Kauf-ID/Token verändern. Es darf keine zusätzliche oder fremde Rechnung
   entstehen. Eine unmittelbar über Guthaben bezahlte Rechnung muss ebenfalls
   auf das Webhook-Ereignis warten.
6. **Storno und Korrektur:** Unbezahlte Rechnung in Stripe stornieren, neuen Kauf
   starten und neue Invoice-ID prüfen. Nach Bezahlung eine Stripe-Korrektur
   vornehmen: bestehende Einschreibung bleibt erhalten.
7. **Regression:** Einmalzahlung mit/ohne `invoicecreation`, asynchrone
   Checkout-Zahlung und Abonnementabschluss/-änderung/-kündigung testen.
   Nach einer Rechnungszahlung außerdem prüfen, dass ein späterer Kauf die
   Stripe-Firmenidentität nicht mit dem Moodle-Profil überschreibt.
8. **Versandfehler und Wiederholung:** Versandfehler simulieren, dieselbe
   Callback-Seite erneut laden und dieselbe Invoice-ID bestätigen. Parallele
   Rücksprünge dürfen keine mehrfachen Versandanforderungen verursachen.
   Bestehende Rechnungen aus Version 2026092400 bleiben beim Upgrade unversendet.
9. **Echte Zustellung:** Nach Freigabe für den Live-Betrieb einen kontrollierten
   Kauf durchführen und den Eingang bei der im Portal gespeicherten Adresse
   prüfen. Nicht die Moodle-Profiladresse als Empfänger voraussetzen.

## Stripe-Referenzen

- [Customer-Portal-Direktflows und erfolgreiche Rückkehr](https://docs.stripe.com/customer-management/portal-deep-links)
- [Customer-Update-Flow ab API 2026-08-26.dahlia](https://docs.stripe.com/changelog/dahlia/2026-08-26/adds-a-customer-update-deep-link-for-billing-portal-sessions)
- [Invoice-Erstellung](https://docs.stripe.com/api/invoices/create)
- [Rechnungszahlungsarten und gemeinsame Auswahl von Karte und Banküberweisung](https://docs.stripe.com/invoicing/payment-methods)
- [Standard-InvoicePayment und zugehöriger PaymentIntent](https://docs.stripe.com/api/invoice-payment/object)
- [Zahlungsarten einer Rechnung aktualisieren](https://docs.stripe.com/api/invoices/update)
- [Banküberweisungen auf Rechnungen, einschließlich EUR-Parameter](https://docs.stripe.com/invoicing/bank-transfer)
- [Invoice-Versand und Testmodus](https://docs.stripe.com/api/invoices/send)
- [Artikel an eine konkrete Rechnung binden](https://docs.stripe.com/api/invoiceitems/create)
- [Stripe-Idempotenz](https://docs.stripe.com/api/idempotent_requests)
