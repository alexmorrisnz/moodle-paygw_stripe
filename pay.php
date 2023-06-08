<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Redirects to the stripe checkout for payment
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_stripe\stripe_helper;

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/.extlib/stripe-php/init.php');

require_login();

$component = required_param('component', PARAM_ALPHANUMEXT);
$paymentarea = required_param('paymentarea', PARAM_ALPHANUMEXT);
$itemid = required_param('itemid', PARAM_INT);
$description = urldecode(required_param('description', PARAM_TEXT));
$sessionid = optional_param('session_id', null, PARAM_TEXT);

$config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'stripe');
$payable = helper::get_payable($component, $paymentarea, $itemid);
$surcharge = helper::get_gateway_surcharge('stripe');

$cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), $surcharge);

$stripehelper = new stripe_helper($config->apikey, $config->secretkey);
if (!isset($config->type) || $config->type == 'onetime') {
    $sessionid = $stripehelper->generate_payment($config, $payable, $description, $cost, $component,
        $paymentarea, $itemid);
} else {
    $sessionid = $stripehelper->generate_subscription($config, $payable, $description, $cost, $component,
        $paymentarea, $itemid, $sessionid);
    if ($sessionid == null) {
        redirect(new moodle_url('/'), get_string('subscriptionerror', 'paygw_stripe'));
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Stripe Checkout Redirect</title>
    <script src="https://js.stripe.com/v3/"></script>
    <script>
        const stripe = Stripe("<?php echo $config->apikey ?>");
        stripe.redirectToCheckout({sessionId: "<?php echo $sessionid; ?>"});
    </script>
</head>
<body>
</body>
</html>
