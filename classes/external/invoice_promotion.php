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

namespace paygw_stripe\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use paygw_stripe\local\service\invoice_promotion_request;

/**
 * Invoice code editor using the same purchase validation as the direct-payment form.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_promotion extends external_api {
    /** @return external_function_parameters Parameters. */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'component' => new external_value(PARAM_COMPONENT, 'Payment component'),
            'paymentarea' => new external_value(PARAM_AREA, 'Payment area'),
            'itemid' => new external_value(PARAM_INT, 'Purchase item'),
            'code' => new external_value(PARAM_RAW_TRIMMED, 'Entered code', VALUE_DEFAULT, ''),
            'apply' => new external_value(PARAM_BOOL, 'Validate and store the selection', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Get options or validate the purchaser's selection.
     *
     * @param string $component Payment component.
     * @param string $paymentarea Payment area.
     * @param int $itemid Purchase item.
     * @param string $code Entered code.
     * @param bool $apply Whether to validate and store a selection.
     * @return array Editor options.
     */
    public static function execute(string $component, string $paymentarea, int $itemid, string $code = '', bool $apply = false): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('component', 'paymentarea', 'itemid', 'code', 'apply'));
        self::validate_context(\context_system::instance());
        require_login(null, false);
        if (
            !$params['apply'] && !in_array('stripe', \core_payment\helper::get_available_gateways(
                $params['component'],
                $params['paymentarea'],
                $params['itemid']
            ), true)
        ) {
            return ['enabled' => false, 'resume' => false, 'token' => '', 'code' => '', 'message' => ''];
        }
        return invoice_promotion_request::options(
            $params['component'],
            $params['paymentarea'],
            $params['itemid'],
            $params['code'],
            $params['apply']
        );
    }

    /** @return external_single_structure Editor response. */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Whether invoice codes are enabled'),
            'resume' => new external_value(PARAM_BOOL, 'Whether invoice creation has already started'),
            'token' => new external_value(PARAM_ALPHANUM, 'Opaque selection token'),
            'code' => new external_value(PARAM_TEXT, 'Canonical code'),
            'message' => new external_value(PARAM_TEXT, 'Price estimate or resume message'),
        ]);
    }
}
