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

use paygw_stripe\local\repository\customer_repository;
use paygw_stripe\local\model\customer as paygw_customer;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Customer service.
 *
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customer_service {
    /**
     * @var StripeClient The Stripe API client.
     */
    private $stripe;

    /**
     * @var customer_repository The customer repository.
     */
    private $customerrepository;
    /**
     * @var locale_service Locale resolver.
     */
    private $localeservice;

    /**
     * Customer service constructor.
     *
     * @param StripeClient $stripe
     * @param locale_service|null $localeservice
     */
    public function __construct(StripeClient $stripe, ?locale_service $localeservice = null) {
        $this->stripe = $stripe;

        $this->customerrepository = new customer_repository();
        $this->localeservice = $localeservice ?? new locale_service();
    }

    /**
     * Get the stripe Customer object from the corresponding Moodle user id.
     *
     * @param int $userid
     * @return Customer|null
     * @throws \dml_exception
     */
    public function get_customer(int $userid): ?Customer {
        if (!$record = $this->customerrepository->find_by_userid($userid)) {
            return null;
        }
        try {
            return $this->stripe->customers->retrieve($record->customerid);
        } catch (ApiErrorException $e) {
            // Customer exists in Moodle but not in stripe, possibly the keys were switched.
            // Delete customer for creation later.
            $this->customerrepository->delete_by_userid($userid);
            return null;
        }
    }

    /**
     * Create a Stripe customer object and save the ID and user ID into the database.
     *
     * @param \stdClass $user
     * @return Customer
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function create_customer($user): Customer {
        $stripelocale = $this->localeservice->get_stripe_locale_for_user($user);

        $customer = $this->stripe->customers->create([
            'email' => $user->email,
            'name' => fullname($user),
            'description' => get_string('customerdescription', 'paygw_stripe', $user->id),
            'preferred_locales' => [$stripelocale],
        ]);
        $record = new paygw_customer(
            null,
            (int)$user->id,
            $customer->id,
        );
        $this->customerrepository->save($record);

        return $customer;
    }

    /**
     * Update the customer details based on the given user.
     *
     * @param Customer $customer
     * @param \stdClass $user
     */
    public function update_customer_details(Customer $customer, $user) {
        $stripelocale = $this->localeservice->get_stripe_locale_for_user($user);

        return $this->stripe->customers->update($customer->id, [
            'email' => $user->email,
            'name' => fullname($user),
            'preferred_locales' => [$stripelocale],
        ]);
    }
}
