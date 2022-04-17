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
 * Upgrade script for paygw_stripe.
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool result
 */
function xmldb_paygw_stripe_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2021082800) {

        // Define table paygw_stripe_products to be created.
        $table = new xmldb_table('paygw_stripe_products');

        // Adding fields to table paygw_stripe_products.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('paymentarea', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('productid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table paygw_stripe_products.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for paygw_stripe_products.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table paygw_stripe_customers to be created.
        $table = new xmldb_table('paygw_stripe_customers');

        // Adding fields to table paygw_stripe_customers.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('customerid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table paygw_stripe_customers.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);

        // Conditionally launch create table for paygw_stripe_customers.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Stripe savepoint reached.
        upgrade_plugin_savepoint(true, 2021082800, 'paygw', 'stripe');
    }

    if ($oldversion < 2022041700) {

        // Define table paygw_stripe_intents to be created.
        $table = new xmldb_table('paygw_stripe_intents');

        // Adding fields to table paygw_stripe_intents.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('paymentintent', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('customerid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('amounttotal', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('paymentstatus', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('productid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table paygw_stripe_intents.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding index to table paygw_stripe_intents.
        $table->add_index('paymentintent', XMLDB_INDEX_UNIQUE, ['paymentintent']);

        // Conditionally launch create table for paygw_stripe_intents.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Stripe savepoint reached.
        upgrade_plugin_savepoint(true, 2022041700, 'paygw', 'stripe');
    }

    return true;
}
