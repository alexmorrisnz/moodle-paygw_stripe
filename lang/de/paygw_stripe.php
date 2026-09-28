<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * German strings for standalone invoice payments.
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['paymenttype:invoice'] = 'Rechnungszahlung';
$string['paymenttype_help'] = 'Bei Rechnungszahlung werden zuerst die Rechnungsdaten in Stripe erfasst. Danach wird eine separate Rechnung mit 14 Tagen Zahlungsziel per E-Mail versendet und geöffnet. Stripe versendet die E-Mail an die gespeicherte Rechnungs-E-Mail-Adresse. Der Kurszugang wird erst freigeschaltet, wenn Stripe die Rechnung als bezahlt meldet. Checkout-Gutscheincodes und die Checkout-Zahlungsartenkonfiguration gelten nicht für Rechnungen.';
$string['invalidinvoicebinding'] = 'Die Stripe-Rechnung passt nicht zum ursprünglichen Kauf. Bitte wenden Sie sich an die Administration.';
$string['invalidinvoicecontinuation'] = 'Dieser Link ist ungültig, abgelaufen oder abgebrochen. Bitte starten Sie den Kauf erneut.';
$string['invoicebillingcancelled'] = 'Sie sind von der Rechnungsdatenerfassung zurückgekehrt. Durch diese Rückkehr wurde keine neue Rechnung erstellt.';
$string['invoicepaymentmethodsfailed'] = 'Die Zahlungsarten der Rechnung konnten nicht vorbereitet werden. Bitte laden Sie diese Seite erneut, um dieselbe Rechnung fortzusetzen. Der Rechnungsversand wurde noch nicht angefordert. Wenden Sie sich bei anhaltenden Problemen an die Administration.';
$string['privacy:metadata:stripe_invoices:paymentmethodstatus'] = 'Status der Vorbereitung der Rechnungszahlungsarten';
$string['privacy:metadata:stripe_invoices:paymentmethods'] = 'Für diese Rechnung gespeicherte Stripe-Zahlungsarten';
$string['invoicebankcountry'] = 'Bankland für EUR-Rechnungen';
$string['invoicebankcountry_help'] = 'Neue EUR-Rechnungskäufe übernehmen die von Stripe aus den Rechnungseinstellungen ermittelten Zahlungsarten und bieten zusätzlich EU-Banküberweisung an. Wählen Sie das Land der von Stripe erzeugten Bankverbindung: Deutschland, Frankreich, Irland oder Niederlande. Standard ist Deutschland (DE). Gemeint ist nicht das Land der Rechnungsadresse. Die Auswahl wird beim Kaufstart gespeichert. Andere Währungen verwenden weiterhin die Stripe-Rechnungszahlungsarten.';
$string['invalidinvoicebankcountry'] = 'Wählen Sie ein unterstütztes Bankland für EUR: DE, FR, IE oder NL.';
$string['privacy:metadata:stripe_invoices:banktransfercountry'] = 'Gewähltes Bankland für den EUR-Rechnungskauf';
$string['invoicebillingincomplete'] = 'Bitte speichern Sie Ihren Rechnungsnamen, Ihre E-Mail-Adresse und Ihre Rechnungsadresse in Stripe, bevor Sie fortfahren.';
$string['invoicebusy'] = 'Die Rechnung wird gerade verarbeitet. Bitte versuchen Sie es gleich erneut.';
$string['invoicedeliveryfailed'] = 'Der bezahlte Kauf konnte noch nicht bereitgestellt werden. Stripe wird die Benachrichtigung erneut senden.';
$string['invoiceemailfailed'] = 'Die Rechnung wurde finalisiert, aber die Versandanforderung konnte nicht bestätigt werden. Bitte laden Sie diese Seite erneut, um es mit derselben Rechnung nochmals zu versuchen. Wenden Sie sich bei anhaltenden Problemen an die Administration.';
$string['invoiceemailrecoveryrequired'] = 'Der Versandstatus der Rechnung muss geprüft werden. Bitte lassen Sie die Administration die Rechnung in Stripe prüfen, bevor sie erneut versendet wird.';
$string['privacy:metadata:stripe_invoices:emailstatus'] = 'Status der Rechnungsversandanforderung an Stripe';
$string['privacy:metadata:stripe_invoices:timeemailstarted'] = 'Zeitpunkt des ersten Rechnungsversandversuchs';
$string['privacy:metadata:stripe_invoices:timeemailsent'] = 'Zeitpunkt der Annahme der Rechnungsversandanforderung durch Stripe';
$string['invoicerecoveryrequired'] = 'Dieser Rechnungsversuch muss geprüft werden. Bitte lassen Sie die Administration den Status in Stripe prüfen, bevor Sie den Kauf erneut versuchen.';
$string['invoiceunavailable'] = 'Diese Rechnung ist nicht verfügbar. Bitte wenden Sie sich an die Administration oder starten Sie den Kauf erneut, falls die Rechnung storniert wurde.';
