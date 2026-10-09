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

defined('MOODLE_INTERNAL') || die();

use PHPUnit\Framework\MockObject\MockObject;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Supplies PHPUnit mocks of Stripe SDK services without global HTTP state.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class stripe_testcase extends \advanced_testcase {
    /** @var StripeClient Mock SDK client. */
    protected StripeClient $client;
    /** @var array Registered SDK service mocks. */
    private array $services = [];

    /**
     * Create a client that exposes only explicitly configured service mocks.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->services = [];
        $this->client = $this->createMock(StripeClient::class);
        $this->client->method('__get')->willReturnCallback(function (string $name) {
            if (!isset($this->services[$name])) {
                throw new \LogicException('Unconfigured Stripe service: ' . $name);
            }
            return $this->services[$name];
        });
    }

    /**
     * Register a PHPUnit mock for an SDK service.
     *
     * @param string $name SDK client property name
     * @param string $class SDK service class
     * @return MockObject Service mock
     */
    protected function mock_stripe_service(string $name, string $class): MockObject {
        $service = $this->createMock($class);
        $this->services[$name] = $service;
        return $service;
    }
}
