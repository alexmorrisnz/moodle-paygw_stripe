# Prüfprotokoll

Datum: 25.09.2026  
Plugin-Version: 2026092500  
Ausgangscommit: `06d4cabcf415d8649624608a2d1a926aabcb1c82`

| Prüfung | Ergebnis |
| --- | --- |
| PHP 8.3.6: Syntax aller 51 Plugin-/Test-PHP-Dateien | Bestanden |
| Moodle Code Checker, PHP_CodeSniffer 3.13.2 | 0 Fehler, 0 Warnungen |
| `db/install.xml` gegen Moodle-XMLDB-XSD | Bestanden |
| Übereinstimmung Neuinstallation/Upgrade: 28 Rechnungsfelder und 2 eindeutige Indizes | Bestanden |
| Standalone-Regressionstests | 25 Szenarien, 218 Assertions bestanden |
| Gleichzeitige `invoice.paid`-Verarbeitung | 4 Prozesse, genau 1 Zahlung und 1 Auslieferung |
| Gleichzeitige Callbacks nach Finalisierung | 4 Prozesse, genau 1 Versandanforderung, keine Zahlung/Auslieferung |
| Versandfehler, verlorene Stripe-Antwort und lokaler Speicherfehler nach Versand | Wiederholung mit derselben Rechnung und demselben Schlüssel, kein doppelter Versand |
| Persistierter Versandstatus und abgelaufene Stripe-Idempotenzfrist | Bestätigten Versand nicht wiederholen; unklaren alten Versand zur Prüfung anhalten |
| Upgrade von 2026092400: tatsächlicher neuer Upgrade-Block mit SQLite-/XMLDB-Adaptern | Bestandsdaten erhalten, keine nachträglichen E-Mails, neue Käufe mit Versand |
| Checkout-Service, Subscription-Service, Product/Pricing-Service und `process.php` | Inhalt identisch mit Ausgangscommit |
| `git diff --check` | Bestanden |

Die Tests verwenden die tatsächlichen neuen Services, Modelle und Repositories,
das mitgelieferte Stripe-PHP-SDK sowie echte SQLite-Transaktionen und Dateisperren.
Moodle-Infrastruktur und Stripe-HTTP-Aufrufe werden durch isolierte Testadapter
ersetzt. Fehler bei Zahlung/Auslieferung und verlorene Antworten nach bereits
ausgeführten Stripe-Schreiboperationen werden gezielt simuliert.
Die Versandtests prüfen außerdem die Aufrufreihenfolge Finalisieren → Senden,
die Stripe-Rechnungs-E-Mail-Adresse als Empfänger sowie den Ausschluss von
Entwürfen, stornierten Rechnungen und abgebrochenen Erfassungsschritten. Auch
eine sofort über Guthaben bezahlte Rechnung wird erst durch `invoice.paid`
bereitgestellt; `invoice.sent` löst keine Einschreibung aus.

Enthalten ist außerdem ein GitHub-Actions-Workflow für diese Tests mit PHP 8.1
und 8.3. Er wurde in dieser Bearbeitung nicht auf GitHub ausgeführt. Die
Standalone-Fixtures sind vom Moodle-Stilchecker ausgenommen, da sie externe
Schnittstellen und mehrere Moodle-Namensräume in einer isolierten CLI-Umgebung
nachbilden.

Nicht ausgeführt: Installation/Upgrade in einer laufenden Moodle-Instanz,
Moodle-PHPUnit-/Behat-Gesamtsuite, echte Stripe-Portal-Interaktion, echte
Zahlungen und echter E-Mail-Versand. Stripes Testmodus sendet beim `/send`-Aufruf
keine echte E-Mail. Die dafür vorgesehene Abnahmeliste steht in
[INVOICE_PAYMENT.md](INVOICE_PAYMENT.md).
