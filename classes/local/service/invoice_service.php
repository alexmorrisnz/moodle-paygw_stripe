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

declare(strict_types=1);

namespace paygw_stripe\local\service;

use core_payment\helper;
use core_payment\local\entities\payable;
use paygw_stripe\gateway;
use paygw_stripe\local\model\invoice;
use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\local\repository\customer_repository;
use Stripe\Event;
use Stripe\Invoice as stripe_invoice;
use Stripe\StripeClient;

/**
 * Standalone invoices. Portal completion creates invoices; only webhooks deliver.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invoice_service {
    /** @var string[] Supported countries for Stripe's EUR bank account details. */
    public const EU_BANK_TRANSFER_COUNTRIES = ['DE', 'FR', 'IE', 'NL'];
    /** @var string Default country for new EUR invoice purchases. */
    public const DEFAULT_BANK_TRANSFER_COUNTRY = 'DE';

    /** @var StripeClient Stripe API client. */
    private StripeClient $stripe;
    /** @var invoice_repository */
    private invoice_repository $repository;
    /** @var product_pricing_service */
    private product_pricing_service $pricing;

    /**
     * Initialise the independent invoice services and repository.
     *
     * @param StripeClient $stripe
     */
    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;
        $this->repository = new invoice_repository();
        $this->pricing = new product_pricing_service($stripe);
    }

    /**
     * Reopen a matching invoice, or save a new purchase and collect billing details.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @return string Portal URL or Hosted Invoice Page
     */
    public function start_payment(
        object $config,
        payable $payable,
        string $description,
        float $cost,
        string $component,
        string $paymentarea,
        int $itemid
    ): string {
        global $USER, $CFG;
        $bankcountry = null;
        if (strtolower($payable->get_currency()) === 'eur') {
            $bankcountry = strtoupper(trim((string) ($config->invoicebankcountry ?? self::DEFAULT_BANK_TRANSFER_COUNTRY)));
            if (!in_array($bankcountry, self::EU_BANK_TRANSFER_COUNTRIES, true)) {
                throw new \moodle_exception('invalidinvoicebankcountry', 'paygw_stripe');
            }
        }

        $amount = (int) round($this->pricing->get_unit_amount($cost, $payable->get_currency()));
        $currency = strtolower($payable->get_currency());
        $userid = (int) $USER->id;
        $paymentaccountid = $payable->get_account_id();
        $existingurl = $this->existing_invoice_url(
            $userid,
            $paymentaccountid,
            $component,
            $paymentarea,
            $itemid,
            $amount,
            $currency
        );
        if ($existingurl !== null) {
            return $existingurl;
        }

        (new webhook_service($this->stripe))->ensure_invoice_events($payable->get_account_id());

        $customer = (new customer_service($this->stripe))->get_invoice_customer($USER);
        $token = bin2hex(random_bytes(32));
        $now = time();

        $record = new invoice(
            id: null,
            userid: $userid,
            paymentaccountid: $paymentaccountid,
            customerid: $customer->id,
            component: $component,
            paymentarea: $paymentarea,
            itemid: $itemid,
            amount: $amount,
            currency: $currency,
            description: $description,
            automatictax: !empty($config->enableautomatictax),
            taxbehavior: $config->defaulttaxbehavior ?? 'inclusive',
            tokenhash: hash('sha256', $token),
            timecreated: $now,
            timeexpires: $now + min((int) $CFG->sessiontimeout, HOURSECS),
            timemodified: $now,
            banktransfercountry: $bankcountry,
            paymentmethodstatus: $bankcountry === null ? 'legacy' : 'pending',
        );
        $lock = $this->purchase_lock($userid, $paymentaccountid, $component, $paymentarea, $itemid);
        try {
            $existingurl = $this->existing_invoice_url(
                $userid,
                $paymentaccountid,
                $component,
                $paymentarea,
                $itemid,
                $amount,
                $currency
            );
            if ($existingurl !== null) {
                return $existingurl;
            }
            $record = $this->repository->save($record);
        } finally {
            $lock->release();
        }

        $success = new \moodle_url('/payment/gateway/stripe/invoice.php', ['request' => $record->id, 'token' => $token]);
        $return = new \moodle_url('/payment/gateway/stripe/invoice_return.php', [
            'request' => $record->id, 'sesskey' => sesskey(),
        ]);
        $portal = (new customer_portal_service($this->stripe))->create_session(
            $customer->id,
            $success->out(false),
            $return->out(false),
            (new locale_service())->get_stripe_locale_for_user($USER)
        );

        $record->portalsessionid = $portal->id;
        $this->repository->save($record);
        return $portal->url;
    }

    /**
     * Reopen a bound Stripe invoice, without duplicating unfinished billing flows.
     *
     * @param int $userid
     * @param int $paymentaccountid
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @param int $amount
     * @param string $currency
     * @return string|null Hosted Invoice Page, or null when a new purchase is allowed
     */
    private function existing_invoice_url(
        int $userid,
        int $paymentaccountid,
        string $component,
        string $paymentarea,
        int $itemid,
        int $amount,
        string $currency
    ): ?string {
        while ($record = $this->repository->find_active_purchase(
            $userid,
            $paymentaccountid,
            $component,
            $paymentarea,
            $itemid,
            $amount,
            $currency,
            time()
        )) {
            $lock = $this->lock($record->id);
            try {
                $record = $this->repository->find_by_id($record->id);
                if (in_array($record->status, ['cancelled', 'void', 'uncollectible', 'expired'], true) || $record->delivered) {
                    continue;
                }
                if ($record->status === 'billing') {
                    if ($record->timeexpires < time()) {
                        continue;
                    }
                    throw new \moodle_exception('invoicealreadyactive', 'paygw_stripe');
                }
                if (!$record->invoiceid) {
                    throw new \moodle_exception('invoicerecoveryrequired', 'paygw_stripe');
                }

                $stripeinvoice = $this->stripe->invoices->retrieve($record->invoiceid);
                if (
                    !$this->is_bound($record, $stripeinvoice) ||
                    ($record->amounttotal !== null && $record->amounttotal !== (int) $stripeinvoice->total)
                ) {
                    throw new \moodle_exception('invalidinvoicebinding', 'paygw_stripe');
                }

                if (in_array($stripeinvoice->status, ['void', 'uncollectible'], true)) {
                    $record->status = $stripeinvoice->status;
                    $record->timemodified = time();
                    $this->repository->save($record);
                    continue;
                }
                if (!in_array($stripeinvoice->status, ['open', 'paid'], true) || !$stripeinvoice->hosted_invoice_url) {
                    throw new \moodle_exception('invoiceunavailable', 'paygw_stripe');
                }

                // Paid invoices awaiting their webhook must not trigger another purchase.
                $record->status = $stripeinvoice->status;
                $record->amounttotal = (int) $stripeinvoice->total;
                $record->timemodified = time();
                $this->repository->save($record);
                (new customer_repository())->release_billing_identity($record->userid, $record->customerid);
                $stripeinvoice = $this->configure_invoice_payment_methods($record, $stripeinvoice);
                $this->send_invoice_email($record, $stripeinvoice);
                return $stripeinvoice->hosted_invoice_url;
            } finally {
                $lock->release();
            }
        }
        return null;
    }

    /**
     * Serialize creation of requests for the same purchase.
     *
     * @param int $userid
     * @param int $paymentaccountid
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @return \core\lock\lock
     */
    private function purchase_lock(
        int $userid,
        int $paymentaccountid,
        string $component,
        string $paymentarea,
        int $itemid
    ): \core\lock\lock {
        $key = hash('sha256', implode("\0", [
            $userid,
            $paymentaccountid,
            $component,
            $paymentarea,
            $itemid,
        ]));
        $factory = \core\lock\lock_config::get_lock_factory('paygw_stripe');
        $lock = $factory->get_lock('invoice_purchase_' . $key, 10);
        if (!$lock) {
            throw new \moodle_exception('invoicebusy', 'paygw_stripe');
        }
        return $lock;
    }

    /**
     * Serialise callback retries and webhook deliveries for the same purchase.
     *
     * @param int $id
     * @return \core\lock\lock
     */
    private function lock(int $id): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('paygw_stripe');
        $lock = $factory->get_lock('invoice_' . $id, 10);
        if (!$lock) {
            throw new \moodle_exception('invoicebusy', 'paygw_stripe');
        }
        return $lock;
    }

    /**
     * Cancel an ordinary Portal return; it can never create an invoice.
     *
     * @param int $id
     * @param int $userid
     */
    public function cancel_billing(int $id, int $userid): void {
        $lock = $this->lock($id);
        try {
            $record = $this->repository->find_by_id($id);
            if (!$record || $record->userid !== $userid) {
                throw new \moodle_exception('invalidinvoicecontinuation', 'paygw_stripe');
            }

            if ($record->status === 'billing') {
                $record->status = 'cancelled';
                $record->timemodified = time();
                $this->repository->save($record);
            }
            if ($record->status === 'cancelled') {
                (new customer_repository())->release_billing_identity($record->userid, $record->customerid);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Continue only the matching user's saved billing flow; retries reuse its invoice.
     *
     * @param int $id
     * @param int $userid
     * @param string $token
     * @return string Hosted Invoice Page
     */
    public function complete_billing(int $id, int $userid, string $token): string {
        $lock = $this->lock($id);
        try {
            $record = $this->repository->find_by_id($id);
            if (
                !$record || $record->userid !== $userid || !$record->portalsessionid ||
                !hash_equals($record->tokenhash, hash('sha256', $token)) ||
                in_array($record->status, ['cancelled', 'void', 'expired'], true)
            ) {
                throw new \moodle_exception('invalidinvoicecontinuation', 'paygw_stripe');
            }

            if ($record->status === 'billing') {
                if ($record->timeexpires < time()) {
                    $record->status = 'expired';
                    $this->repository->save($record);
                    (new customer_repository())->release_billing_identity($record->userid, $record->customerid);
                    throw new \moodle_exception('invalidinvoicecontinuation', 'paygw_stripe');
                }

                // No profile synchronisation: use the billing identity saved in Stripe.
                $customer = $this->stripe->customers->retrieve($record->customerid, ['expand' => ['tax_ids']]);
                if (
                    !empty($customer->deleted) || empty($customer->name) || empty($customer->email) ||
                    empty($customer->address->line1) || empty($customer->address->country)
                ) {
                    throw new \moodle_exception('invoicebillingincomplete', 'paygw_stripe');
                }

                $record->status = 'creating';
                $record->timeconfirmed = time();
                $record->timemodified = time();
                $this->repository->save($record);
            }

            // Stripe may forget idempotency keys after 24h. Never retry uncertain writes beyond 23h.
            if ($record->status === 'creating' && $record->timeconfirmed + 23 * HOURSECS < time()) {
                throw new \moodle_exception('invoicerecoveryrequired', 'paygw_stripe');
            }

            $stripeinvoice = $this->create_invoice($record);
            if (in_array($stripeinvoice->status, ['void', 'uncollectible'], true) || !$stripeinvoice->hosted_invoice_url) {
                throw new \moodle_exception('invoiceunavailable', 'paygw_stripe');
            }

            $stripeinvoice = $this->configure_invoice_payment_methods($record, $stripeinvoice);
            $this->send_invoice_email($record, $stripeinvoice);

            return $stripeinvoice->hosted_invoice_url;
        } finally {
            $lock->release();
        }
    }

    /**
     * Stable Stripe idempotency key per purchase and operation.
     *
     * @param invoice $record
     * @param string $operation
     * @return array
     */
    private function options(invoice $record, string $operation): array {
        return ['idempotency_key' => 'moodle-invoice-' . $record->tokenhash . '-' . $operation];
    }

    /**
     * Bind every external object to an immutable local purchase and this Moodle site.
     *
     * @param invoice $record
     * @return array
     */
    private function metadata(invoice $record): array {
        global $CFG;
        return [
            'gateway' => 'paygw_stripe',
            'flow' => 'invoice',
            'moodle_site' => hash('sha256', $CFG->wwwroot),
            'transactionid' => (string) $record->id,
            'paymentaccountid' => (string) $record->paymentaccountid,
            'userid' => (string) $record->userid,
            'component' => $record->component,
            'paymentarea' => $record->paymentarea,
            'itemid' => (string) $record->itemid,
        ];
    }

    /**
     * Create a draft first so its one item cannot leak into another pending invoice.
     * Checkpoints are persisted before finalization can emit invoice.paid.
     *
     * @param invoice $record
     * @return stripe_invoice
     */
    private function create_invoice(invoice $record): stripe_invoice {
        if (!$record->priceid) {
            $cost = $this->major_amount($record->amount, $record->currency);
            $payable = new payable($cost, strtoupper($record->currency), $record->paymentaccountid);
            [, $price] = $this->pricing->create_product_and_price(
                (object) ['enableautomatictax' => $record->automatictax, 'defaulttaxbehavior' => $record->taxbehavior],
                $payable,
                $record->description,
                $cost,
                $record->component,
                $record->paymentarea,
                (string) $record->itemid
            );

            // Shared Checkout prices may have a different, immutable tax behaviour.
            if ($record->automatictax && $price->tax_behavior !== $record->taxbehavior) {
                $price = $this->pricing->create_price(
                    $record->currency,
                    $price->product,
                    $record->amount,
                    true,
                    $record->taxbehavior
                );
            }
            $record->priceid = $price->id;
            $this->repository->save($record);
        }

        if (!$record->invoiceid) {
            $params = [
                'customer' => $record->customerid,
                'currency' => $record->currency,
                'collection_method' => 'send_invoice',
                'days_until_due' => 14,
                'auto_advance' => false,
                'pending_invoice_items_behavior' => 'exclude',
                'description' => $record->description,
                'automatic_tax' => ['enabled' => $record->automatictax],
                'discounts' => [],
                'metadata' => $this->metadata($record),
            ];

            // A null snapshot preserves pre-upgrade requests, including uncertain Stripe writes.
            if (
                $record->currency === 'eur' && $record->banktransfercountry !== null &&
                $record->paymentmethodstatus === 'legacy'
            ) {
                $params['payment_settings'] = $this->bank_transfer_settings($record, ['customer_balance']);
            }

            // New purchases let Stripe resolve invoice defaults when finalizing.
            $stripeinvoice = $this->stripe->invoices->create($params, $this->options($record, 'create'));
            $record->invoiceid = $stripeinvoice->id;
            $this->repository->save($record);
        } else {
            $stripeinvoice = $this->stripe->invoices->retrieve($record->invoiceid);
        }

        if (!$this->is_bound($record, $stripeinvoice)) {
            throw new \moodle_exception('invalidinvoicebinding', 'paygw_stripe');
        }

        if ($stripeinvoice->status === 'draft') {
            if (!$record->invoiceitemid) {
                $item = $this->stripe->invoiceItems->create([
                    'customer' => $record->customerid,
                    'invoice' => $record->invoiceid,
                    'pricing' => ['price' => $record->priceid],
                    'quantity' => 1,
                    'discountable' => false,
                    'metadata' => $this->metadata($record),
                ], $this->options($record, 'item'));
                $record->invoiceitemid = $item->id;
                $this->repository->save($record);
            }

            $stripeinvoice = $this->stripe->invoices->finalizeInvoice(
                $record->invoiceid,
                ['auto_advance' => false],
                $this->options($record, 'finalize')
            );
        }

        // Never deliver here, even if a credit balance immediately paid the invoice.
        $record->amounttotal = $stripeinvoice->total;
        $record->status = $stripeinvoice->status;
        $record->timemodified = time();
        $this->repository->save($record);

        if (in_array($stripeinvoice->status, ['open', 'paid'], true)) {
            // Finalization snapshots the invoice's billing identity before profile sync resumes.
            (new customer_repository())->release_billing_identity($record->userid, $record->customerid);
        }

        return $stripeinvoice;
    }

    /**
     * Add EUR transfer options without replacing any other payment-method options.
     *
     * @param invoice $record
     * @param string[] $methods Complete payment-method list.
     * @return array
     */
    private function bank_transfer_settings(invoice $record, array $methods): array {
        return [
            'payment_method_types' => $methods,
            'payment_method_options' => [
                'customer_balance' => [
                    'funding_type' => 'bank_transfer',
                    'bank_transfer' => [
                        'type' => 'eu_bank_transfer',
                        'eu_bank_transfer' => ['country' => $record->banktransfercountry],
                    ],
                ],
            ],
        ];
    }

    /**
     * Keep the payment methods Stripe resolved for this invoice and append bank transfer.
     * Snapshot the complete list before updating; never repeat the update after success.
     *
     * @param invoice $record
     * @param stripe_invoice $stripeinvoice
     * @return stripe_invoice
     */
    private function configure_invoice_payment_methods(invoice $record, stripe_invoice $stripeinvoice): stripe_invoice {
        if (in_array($record->paymentmethodstatus, ['legacy', 'ready'], true)) {
            return $stripeinvoice;
        }

        // Fully credited invoices need no payment choice and may have no PaymentIntent.
        if ($stripeinvoice->status === 'paid') {
            $record->paymentmethodstatus = 'ready';
            $this->repository->save($record);
            return $stripeinvoice;
        }

        if ($stripeinvoice->status !== 'open' || $record->currency !== 'eur' || !$record->banktransfercountry) {
            throw new \moodle_exception('invoicepaymentmethodsfailed', 'paygw_stripe');
        }

        try {
            if ($record->paymentmethodstatus === 'pending') {
                $methods = $this->resolved_invoice_payment_methods($record);
                $methods = array_values(array_unique([...$methods, 'customer_balance']));
                $record->paymentmethods = json_encode($methods, JSON_THROW_ON_ERROR);
                $record->paymentmethodstatus = 'applying';
                $this->repository->save($record);
            }

            $methods = json_decode($record->paymentmethods, true, 512, JSON_THROW_ON_ERROR);
            $updated = $this->stripe->invoices->update(
                $record->invoiceid,
                ['payment_settings' => $this->bank_transfer_settings($record, $methods)],
                $this->options($record, 'payment-methods')
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \moodle_exception('invoicepaymentmethodsfailed', 'paygw_stripe');
        }

        if (!$this->is_bound($record, $updated)) {
            throw new \moodle_exception('invalidinvoicebinding', 'paygw_stripe');
        }

        $settings = $updated->payment_settings;
        $balance = $settings->payment_method_options->customer_balance ?? null;
        if (
            array_diff($methods, $settings->payment_method_types ?? []) ||
            ($balance->funding_type ?? null) !== 'bank_transfer' ||
            ($balance->bank_transfer->type ?? null) !== 'eu_bank_transfer' ||
            ($balance->bank_transfer->eu_bank_transfer->country ?? null) !== $record->banktransfercountry
        ) {
            throw new \moodle_exception('invoicepaymentmethodsfailed', 'paygw_stripe');
        }

        $record->paymentmethodstatus = 'ready';
        $record->timemodified = time();
        $this->repository->save($record);
        return $updated;
    }

    /**
     * Read the default invoice PaymentIntent; attached third-party payments are not defaults.
     * The PaymentIntent itself is never edited.
     *
     * @param invoice $record
     * @return string[]
     */
    private function resolved_invoice_payment_methods(invoice $record): array {
        $payments = $this->stripe->invoicePayments->all([
            'invoice' => $record->invoiceid,
            'limit' => 100,
            'expand' => ['data.payment.payment_intent'],
        ]);

        foreach ($payments->autoPagingIterator() as $payment) {
            if (
                !$payment->is_default || $payment->invoice !== $record->invoiceid ||
                $payment->status !== 'open' || $payment->payment->type !== 'payment_intent'
            ) {
                continue;
            }

            $intent = $payment->payment->payment_intent;
            if (is_string($intent)) {
                $intent = $this->stripe->paymentIntents->retrieve($intent);
            }

            if (
                $intent instanceof \Stripe\PaymentIntent && $intent->customer === $record->customerid &&
                $intent->currency === $record->currency && !empty($intent->payment_method_types)
            ) {
                return $intent->payment_method_types;
            }
        }

        // Do not silently replace unavailable Stripe defaults with a hard-coded fallback.
        throw new \moodle_exception('invoicepaymentmethodsfailed', 'paygw_stripe');
    }

    /**
     * Ask Stripe to email the finalized invoice once, while holding the purchase lock.
     * A successful API response confirms submission, not delivery to the recipient's inbox.
     *
     * @param invoice $record
     * @param stripe_invoice $stripeinvoice
     */
    private function send_invoice_email(invoice $record, stripe_invoice $stripeinvoice): void {
        // Existing purchases are not emailed retroactively when the plugin is upgraded.
        if (in_array($record->emailstatus, ['sent', 'legacy'], true)) {
            return;
        }

        if (!in_array($stripeinvoice->status, ['open', 'paid'], true)) {
            throw new \moodle_exception('invoiceunavailable', 'paygw_stripe');
        }

        // A lost response must not cause a second email after Stripe expires the key.
        if ($record->timeemailstarted !== null && $record->timeemailstarted + 23 * HOURSECS < time()) {
            throw new \moodle_exception('invoiceemailrecoveryrequired', 'paygw_stripe');
        }

        if ($record->timeemailstarted === null) {
            $record->emailstatus = 'sending';
            $record->timeemailstarted = time();
            $record->timemodified = time();
            $this->repository->save($record);
        }

        try {
            // Keep auto_advance disabled; sending is explicit and uses Stripe's billing email.
            $sentinvoice = $this->stripe->invoices->sendInvoice(
                $record->invoiceid,
                [],
                $this->options($record, 'send')
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \moodle_exception('invoiceemailfailed', 'paygw_stripe');
        }

        if (!$this->is_bound($record, $sentinvoice)) {
            throw new \moodle_exception('invalidinvoicebinding', 'paygw_stripe');
        }

        $record->emailstatus = 'sent';
        $record->timeemailsent = time();
        $record->timemodified = time();
        $this->repository->save($record);
    }

    /**
     * Verify ID, customer, currency, collection method and all purchase references.
     *
     * @param invoice $record
     * @param stripe_invoice $stripeinvoice
     * @return bool
     */
    private function is_bound(invoice $record, stripe_invoice $stripeinvoice): bool {
        if (
            $stripeinvoice->id !== $record->invoiceid || $stripeinvoice->customer !== $record->customerid ||
            $stripeinvoice->currency !== $record->currency || $stripeinvoice->collection_method !== 'send_invoice'
        ) {
            return false;
        }

        foreach ($this->metadata($record) as $key => $value) {
            if ((string) ($stripeinvoice->metadata[$key] ?? '') !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert the stored Stripe amount for Moodle's payment API.
     *
     * @param int $amount
     * @param string $currency
     * @return float
     */
    private function major_amount(int $amount, string $currency): float {
        return in_array(strtoupper($currency), gateway::get_zero_decimal_currencies(), true) ? $amount : $amount / 100;
    }

    /**
     * Handle an already signature-verified event. All delivery writes are atomic.
     *
     * @param Event $event
     * @return bool Whether this event belongs to a stored Moodle invoice.
     */
    public function process_event(Event $event): bool {
        global $DB;
        if (!in_array($event->type, ['invoice.paid', 'invoice.voided'], true)) {
            return false;
        }

        $record = $this->repository->find_by_invoiceid($event->data->object->id);
        if (!$record) {
            return false;
        }

        $lock = $this->lock($record->id);
        try {
            $record = $this->repository->find_by_id($record->id);
            if ($record->delivered) {
                return true; // Accounting changes after delivery never revoke access.
            }

            $stripeinvoice = $this->stripe->invoices->retrieve($record->invoiceid);
            if (!$this->is_bound($record, $stripeinvoice)) {
                throw new \moodle_exception('invalidinvoicebinding', 'paygw_stripe');
            }

            if ($event->type === 'invoice.voided') {
                if ($stripeinvoice->status === 'void') {
                    $record->status = 'void';
                    $record->timemodified = time();
                    $this->repository->save($record);
                }
                return true;
            }

            if ($stripeinvoice->status !== 'paid' || $stripeinvoice->amount_remaining != 0) {
                return true; // A stale event cannot grant access to an unpaid/void invoice.
            }

            if (!$record->invoiceitemid || !$record->timeconfirmed) {
                throw new \moodle_exception('invalidinvoicebinding', 'paygw_stripe');
            }

            if ($record->amounttotal !== null && $record->amounttotal !== (int) $stripeinvoice->total) {
                throw new \moodle_exception('invalidinvoicebinding', 'paygw_stripe');
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $record->amounttotal = (int) $stripeinvoice->total;
                $record->paymentid = helper::save_payment(
                    $record->paymentaccountid,
                    $record->component,
                    $record->paymentarea,
                    $record->itemid,
                    $record->userid,
                    $this->major_amount($record->amounttotal, $record->currency),
                    strtoupper($record->currency),
                    'stripe'
                );
                $delivered = helper::deliver_order(
                    $record->component,
                    $record->paymentarea,
                    $record->itemid,
                    $record->paymentid,
                    $record->userid
                );
                if (!$delivered) {
                    throw new \moodle_exception('invoicedeliveryfailed', 'paygw_stripe');
                }
                $record->status = 'paid';
                $record->delivered = true;
                $record->timemodified = time();
                $this->repository->save($record);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            return true;
        } finally {
            $lock->release();
        }
    }
}
