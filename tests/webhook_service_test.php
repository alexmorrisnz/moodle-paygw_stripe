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
 * Tests for webhook service logic.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

use advanced_testcase;
use Stripe\StripeClient;
use Stripe\WebhookEndpoint;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Tests for webhook_service.
 */
final class webhook_service_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests webhook create, fetch and delete flow.
     */
    public function test_webhook_lifecycle_methods(): void {
        $stripeclient = new webhook_test_fake_client();
        $service = new webhook_service($stripeclient);

        $this->assertTrue($service->create_webhook(444));
        $this->assertFalse($service->create_webhook(444));

        $webhook = $service->get_webhook(444);
        $this->assertNotNull($webhook);
        $this->assertSame('whsec_test_1', $webhook->secret);

        $this->assertTrue($service->delete_webhook(444));
        $this->assertFalse($service->delete_webhook(444));
        $this->assertNull($service->get_webhook(444));
    }
}

/**
 * Minimal fake Stripe client for unit testing webhook_service without network calls.
 */
final class webhook_test_fake_client extends StripeClient {
    /** @var webhook_test_fake_webhook_endpoints_service */
    public $webhookEndpoints;

    public function __construct() {
        $this->webhookEndpoints = new webhook_test_fake_webhook_endpoints_service();
    }
}

/**
 * Fake webhook endpoints service.
 */
final class webhook_test_fake_webhook_endpoints_service {
    /** @var array */
    private $webhooks = [];
    /** @var array */
    public $deletedids = [];
    /** @var int */
    private $counter = 0;

    /**
     * @param array $data
     * @return WebhookEndpoint
     */
    public function create(array $data): WebhookEndpoint {
        $this->counter++;
        $id = 'we_test_' . $this->counter;
        $secret = 'whsec_test_' . $this->counter;
        $webhook = WebhookEndpoint::constructFrom([
            'id' => $id,
            'url' => $data['url'],
            'secret' => $secret,
        ]);
        $this->webhooks[$id] = $webhook;
        return $webhook;
    }

    /**
     * @param string $id
     * @return WebhookEndpoint|null
     */
    public function retrieve(string $id): ?WebhookEndpoint {
        return $this->webhooks[$id] ?? null;
    }

    /**
     * @param string $id
     * @return object
     */
    public function delete(string $id): object {
        $this->deletedids[] = $id;
        unset($this->webhooks[$id]);
        return (object)['id' => $id, 'deleted' => true];
    }
}
