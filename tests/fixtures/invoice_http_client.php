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
 * Stateful invoice-flow HTTP fixture. The real SDK constructs requests and responses.
 * Seed resource arrays with API response data and inspect requests to assert SDK payloads.
 * Failure controls simulate API errors, outages, and response loss around remote writes.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_http_client implements \Stripe\HttpClient\ClientInterface {
    /** @var array Recorded HTTP requests and their parameters. */
    public array $requests = [];
    /** @var array Customer resources indexed by ID. */
    public array $customers = [];
    /** @var array Invoice resources indexed by ID. */
    public array $invoices = [];
    /** @var array Invoice item resources indexed by ID. */
    public array $items = [];
    /** @var array Product resources indexed by ID. */
    public array $products = [];
    /** @var array Price resources indexed by ID. */
    public array $prices = [];
    /** @var array Billing portal configurations indexed by ID. */
    public array $configs = [];
    /** @var array Billing portal sessions indexed by ID. */
    public array $sessions = [];
    /** @var array Webhook endpoints indexed by ID. */
    public array $webhooks = [];
    /** @var array Cached request parameters and responses indexed by idempotency key. */
    public array $keys = [];
    /** @var array Recorded invoice email deliveries. */
    public array $sent = [];
    /** @var array Default invoice payment method types. */
    public array $invoicemethods = ['card', 'paypal', 'sepa_debit'];
    /** @var array Payment intents indexed by ID. */
    public array $paymentintents = [];
    /** @var array Invoice payments indexed by invoice ID. */
    public array $invoicepayments = [];
    /** @var bool Whether to expand payment intents when requested. */
    public bool $expandintents = true;
    /** @var string Path whose next response is lost after the remote write. */
    public string $lose = '';
    /** @var string Path whose next request fails before the remote write. */
    public string $failbefore = '';
    /** @var bool Whether customer requests simulate an outage. */
    public bool $customererror = false;
    /** @var bool Whether invoices are automatically paid on finalization. */
    public bool $autopaid = false;
    /** @var int Tax amount added to finalized invoices in minor currency units. */
    public int $tax = 0;

    /**
     * Handle an SDK request against the in-memory API.
     *
     * @param string $method HTTP method
     * @param string $absurl Absolute request URL
     * @param array $headers HTTP headers
     * @param array $params Request parameters
     * @param bool $hasfile Whether the request contains a file
     * @param string $apimode Stripe API mode
     * @param int|null $maxnetworkretries Maximum network retries
     * @return array Response body, HTTP status and headers
     */
    public function request($method, $absurl, $headers, $params, $hasfile, $apimode = 'v1', $maxnetworkretries = null) {
        array_walk_recursive($params, static function (&$value): void {
            if ($value === 'true') {
                $value = true;
            } else if ($value === 'false') {
                $value = false;
            }
        });
        $path = parse_url($absurl, PHP_URL_PATH);
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
        $requestparams = $params;
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
                return $this->missing_resource('customer');
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
            if ($method === 'get' || $method === 'delete') {
                if (!isset($this->webhooks[$id])) {
                    return $this->missing_resource('webhook endpoint');
                }
                $response = $this->webhooks[$id];
                if ($method === 'delete') {
                    unset($this->webhooks[$id]);
                    $response = ['id' => $id, 'object' => 'webhook_endpoint', 'deleted' => true];
                }
            } else {
                $response = $this->webhooks[$id] = array_replace($this->webhooks[$id] ??
                    ['id' => $id, 'object' => 'webhook_endpoint', 'secret' => 'whsec_fake'], $params);
            }
        } else if ($resource === 'products') {
            if ($method === 'get') {
                if (!isset($this->products[$id])) {
                    return $this->missing_resource('product');
                }
                $response = $this->products[$id];
            } else {
                $id ??= 'prod_' . (count($this->products) + 1);
                $response = $this->products[$id] = array_replace($this->products[$id] ??
                    ['id' => $id, 'object' => 'product'], $params);
            }
        } else if ($resource === 'prices') {
            if (isset($params['unit_amount'])) {
                $params['unit_amount'] = (int)$params['unit_amount'];
            }
            if ($method === 'get' && $id) {
                if (!isset($this->prices[$id])) {
                    return $this->missing_resource('price');
                }
                $response = $this->prices[$id];
            } else if ($method === 'get') {
                $response = $this->collection(array_values(array_filter(
                    $this->prices,
                    static fn($price) => !isset($params['product']) || $price['product'] === $params['product']
                )));
            } else {
                $id ??= 'price_' . (count($this->prices) + 1);
                $response = $this->prices[$id] = array_replace($this->prices[$id] ??
                    ['id' => $id, 'object' => 'price', 'active' => true,
                        'type' => isset($params['recurring']) ? 'recurring' : 'one_time',
                        'tax_behavior' => 'unspecified'], $params);
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
            $this->keys[$key] = ['params' => $requestparams, 'response' => $response];
        }
        if ($this->lose === $path) {
            $this->lose = '';
            throw new \Stripe\Exception\ApiConnectionException('Simulated response loss after remote write');
        }
        return [json_encode($response), 200, []];
    }

    /**
     * Build a Stripe list response.
     *
     * @param array $data Resource data
     * @return array List response
     */
    private function collection(array $data): array {
        return ['object' => 'list', 'data' => $data, 'has_more' => false, 'url' => '/v1/test'];
    }

    /**
     * Build a resource-missing API error response.
     *
     * @param string $resource Resource type
     * @return array Response body, HTTP status and headers
     */
    private function missing_resource(string $resource): array {
        return [json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing',
            'message' => 'No such ' . $resource]]), 404, []];
    }

    /**
     * Mark an invoice as fully paid.
     *
     * @param string $id Invoice ID
     */
    public function paid(string $id): void {
        $this->invoices[$id]['status'] = 'paid';
        $this->invoices[$id]['amount_remaining'] = 0;
    }
}
