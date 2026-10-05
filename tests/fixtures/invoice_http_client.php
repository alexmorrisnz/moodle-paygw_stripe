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

namespace paygw_stripe\tests\fixtures;

/**
 * In-memory Stripe HTTP endpoint. The real SDK serialises requests and constructs responses.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_http_client implements \Stripe\HttpClient\ClientInterface {
    public array $requests = [];
    public array $customers = [];
    public array $invoices = [];
    public array $items = [];
    public array $products = [];
    public array $prices = [];
    public array $configs = [];
    public array $sessions = [];
    public array $webhooks = [];
    public array $keys = [];
    public array $sent = [];
    public array $invoicemethods = ['card', 'paypal', 'sepa_debit'];
    public array $paymentintents = [];
    public array $invoicepayments = [];
    public bool $expandintents = true;
    public string $lose = '';
    public string $failbefore = '';
    public bool $customererror = false;
    public bool $autopaid = false;
    public int $tax = 0;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null) {
        array_walk_recursive($params, static function (&$value): void {
            if ($value === 'true') {
                $value = true;
            } else if ($value === 'false') {
                $value = false;
            }
        });
        $path = parse_url($absUrl, PHP_URL_PATH);
        $key = '';
        $version = '';
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Idempotency-Key: ')) {
                $key = substr($header, 17);
            }
            if (str_starts_with($header, 'Stripe-Version: ')) {
                $version = substr($header, 16);
            }
        }
        $this->requests[] = compact('method', 'path', 'params', 'key', 'version');
        if ($this->failbefore === $path) {
            $this->failbefore = '';
            throw new \Stripe\Exception\ApiConnectionException('Simulated failure before remote write');
        }
        if ($key && isset($this->keys[$key])) {
            if ($this->keys[$key]['params'] !== $params) {
                throw new \RuntimeException('Idempotency parameters changed');
            }
            return [json_encode($this->keys[$key]['response']), 200, []];
        }
        $parts = explode('/', trim($path, '/'));
        $resource = $parts[1];
        $id = $parts[2] ?? null;
        $action = $parts[3] ?? null;
        if ($resource === 'billing_portal') {
            $resource = $id;
            $id = $action;
        }
        $response = null;
        if ($resource === 'customers') {
            if ($this->customererror) {
                throw new \Stripe\Exception\ApiConnectionException('Simulated outage');
            }
            if ($method === 'post' && !$id) {
                $id = 'cus_' . (count($this->customers) + 1);
                $response = $this->customers[$id] = ['id' => $id, 'object' => 'customer'] + $params;
            } else if (!isset($this->customers[$id])) {
                return [json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing',
                    'message' => 'No such customer']]), 404, []];
            } else if ($method === 'post') {
                $response = $this->customers[$id] = array_replace($this->customers[$id], $params);
            } else {
                $response = $this->customers[$id];
            }
        } else if ($resource === 'configurations') {
            if ($method === 'get') {
                $response = $this->collection(array_values($this->configs));
            } else {
                $id ??= 'bpc_' . (count($this->configs) + 1);
                $response = $this->configs[$id] = array_replace($this->configs[$id] ??
                    ['id' => $id, 'object' => 'billing_portal.configuration', 'is_default' => false], $params);
            }
        } else if ($resource === 'sessions') {
            $id = 'bps_' . (count($this->sessions) + 1);
            $response = $this->sessions[$id] = ['id' => $id, 'object' => 'billing_portal.session',
                'url' => 'https://billing.stripe.com/session/' . $id] + $params;
        } else if ($resource === 'webhook_endpoints') {
            $id ??= 'we_' . (count($this->webhooks) + 1);
            if ($method === 'get') {
                $response = $this->webhooks[$id];
            } else {
                $response = $this->webhooks[$id] = array_replace($this->webhooks[$id] ??
                    ['id' => $id, 'object' => 'webhook_endpoint', 'secret' => 'whsec_fake'], $params);
            }
        } else if ($resource === 'products') {
            $id ??= 'prod_' . (count($this->products) + 1);
            $response = $this->products[$id] = array_replace($this->products[$id] ??
                ['id' => $id, 'object' => 'product'], $params);
        } else if ($resource === 'prices') {
            if (isset($params['unit_amount'])) {
                $params['unit_amount'] = (int)$params['unit_amount'];
            }
            if ($method === 'get') {
                $response = $this->collection(array_values(array_filter(
                    $this->prices,
                    static fn($price) => $price['product'] === $params['product']
                )));
            } else {
                $id ??= 'price_' . (count($this->prices) + 1);
                $response = $this->prices[$id] = array_replace($this->prices[$id] ??
                    ['id' => $id, 'object' => 'price', 'active' => true, 'type' => 'one_time'], $params);
            }
        } else if ($resource === 'invoices') {
            if ($method === 'post' && !$id) {
                $id = 'in_' . (count($this->invoices) + 1);
                $response = $this->invoices[$id] = ['id' => $id, 'object' => 'invoice', 'status' => 'draft',
                    'total' => 0, 'amount_remaining' => 0, 'hosted_invoice_url' => null] + $params;
            } else if ($action === 'finalize') {
                $invoice = $this->invoices[$id];
                $total = array_sum(array_column(array_filter(
                    $this->items,
                    static fn($item) => $item['invoice'] === $id
                ), 'amount')) + $this->tax;
                $response = $this->invoices[$id] = array_replace($invoice, [
                    'status' => $this->autopaid ? 'paid' : 'open', 'total' => $total,
                    'amount_remaining' => $this->autopaid ? 0 : $total,
                    'hosted_invoice_url' => 'https://invoice.stripe.com/' . $id,
                    'customer_email' => $this->customers[$invoice['customer']]['email'],
                ]);
                if (!$this->autopaid) {
                    $intentid = 'pi_' . $id;
                    $this->paymentintents[$intentid] = ['id' => $intentid, 'object' => 'payment_intent',
                        'customer' => $invoice['customer'], 'currency' => $invoice['currency'],
                        'status' => 'requires_payment_method',
                        'payment_method_types' => $invoice['payment_settings']['payment_method_types'] ?? $this->invoicemethods];
                    $this->invoicepayments[$id] = ['id' => 'inpay_' . $id, 'object' => 'invoice_payment',
                        'invoice' => $id, 'is_default' => true, 'status' => 'open',
                        'payment' => ['type' => 'payment_intent', 'payment_intent' => $intentid]];
                }
            } else if ($method === 'post' && $id && !$action) {
                $response = $this->invoices[$id] = array_replace_recursive($this->invoices[$id], $params);
                $types = $params['payment_settings']['payment_method_types'];
                $this->invoices[$id]['payment_settings']['payment_method_types'] = $types;
                $this->paymentintents['pi_' . $id]['payment_method_types'] = $types;
                $response = $this->invoices[$id];
            } else if ($action === 'send' && $method === 'post') {
                $response = $this->invoices[$id];
                if (!in_array($response['status'], ['open', 'paid'], true)) {
                    throw new \RuntimeException('Cannot send an unfinalized invoice');
                }
                $this->sent[] = ['invoiceid' => $id, 'email' => $response['customer_email'], 'key' => $key];
            } else {
                $response = $this->invoices[$id];
            }
        } else if ($resource === 'invoice_payments' && $method === 'get') {
            $payments = array_values(array_filter(
                $this->invoicepayments,
                static fn($payment) => $payment['invoice'] === $params['invoice']
            ));
            foreach ($payments as &$payment) {
                if ($this->expandintents && in_array('data.payment.payment_intent', $params['expand'] ?? [], true)) {
                    $intentid = $payment['payment']['payment_intent'];
                    $payment['payment']['payment_intent'] = $this->paymentintents[$intentid];
                }
            }
            unset($payment);
            $response = $this->collection($payments);
        } else if ($resource === 'payment_intents' && $method === 'get') {
            $response = $this->paymentintents[$id];
        } else if ($resource === 'invoiceitems') {
            $id = 'ii_' . (count($this->items) + 1);
            $response = $this->items[$id] = ['id' => $id, 'object' => 'invoiceitem',
                'amount' => $this->prices[$params['pricing']['price']]['unit_amount']] + $params;
        }
        if ($response === null) {
            throw new \RuntimeException('Unexpected Stripe call: ' . $method . ' ' . $path);
        }
        if ($key) {
            $this->keys[$key] = ['params' => $params, 'response' => $response];
        }
        if ($this->lose === $path) {
            $this->lose = '';
            throw new \Stripe\Exception\ApiConnectionException('Simulated response loss after remote write');
        }
        return [json_encode($response), 200, []];
    }

    private function collection(array $data): array {
        return ['object' => 'list', 'data' => $data, 'has_more' => false, 'url' => '/v1/test'];
    }

    public function paid(string $id): void {
        $this->invoices[$id]['status'] = 'paid';
        $this->invoices[$id]['amount_remaining'] = 0;
    }
}
