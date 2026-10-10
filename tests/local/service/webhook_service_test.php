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

defined('MOODLE_INTERNAL') || die();

use paygw_stripe\tests\fixtures\stripe_testcase;
use paygw_stripe\stripe_helper;
use Stripe\Service\WebhookEndpointService;
use Stripe\WebhookEndpoint;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');

/**
 * Tests for webhook_service.
 *
 * @covers \paygw_stripe\local\service\webhook_service
 */
final class webhook_service_test extends stripe_testcase {
    /**
     * Tests webhook create, fetch and delete flow.
     */
    public function test_webhook_lifecycle_methods(): void {
        global $CFG;

        $endpoint = WebhookEndpoint::constructFrom(['id' => 'we_test', 'secret' => 'whsec_fake']);
        $webhooks = $this->mock_stripe_service('webhookEndpoints', WebhookEndpointService::class);
        $webhooks->expects($this->once())->method('create')->with([
            'url' => $CFG->wwwroot . '/payment/gateway/stripe/webhook.php',
            'enabled_events' => [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
                'customer.subscription.deleted',
                'customer.subscription.updated',
                'invoice.paid',
                'invoice.voided',
            ],
            'api_version' => stripe_helper::$apiversion,
        ])->willReturn($endpoint);
        $webhooks->expects($this->exactly(2))->method('retrieve')->with('we_test')->willReturn($endpoint);
        $webhooks->expects($this->once())->method('delete')->with('we_test')
            ->willReturn(WebhookEndpoint::constructFrom(['id' => 'we_test', 'deleted' => true]));
        $service = new webhook_service($this->client);

        $this->assertTrue($service->create_webhook(444));
        $this->assertFalse($service->create_webhook(444));

        $webhook = $service->get_webhook(444);
        $this->assertNotNull($webhook);
        $this->assertSame('whsec_fake', $webhook->secret);

        $this->assertTrue($service->delete_webhook(444));
        $this->assertFalse($service->delete_webhook(444));
        $this->assertNull($service->get_webhook(444));
    }
}
