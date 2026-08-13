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

use core_payment\local\entities\payable;
use paygw_stripe\gateway;
use paygw_stripe\local\repository\product_repository;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use paygw_stripe\local\model\product as paygw_product;

/**
 * Product pricing service.
 *
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class product_pricing_service {
    /**
     * @var StripeClient The Stripe API client.
     */
    private $stripe;

    private $productrepository;

    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;

        $this->productrepository = new product_repository();
    }

    /**
     * Find a product in the database and the corresponding Stripe Product item.
     *
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return Product|null
     * @throws \dml_exception
     */
    public function get_product(string $component, string $paymentarea, string $itemid): ?Product {
        if (
            $record = $this->productrepository->get_product_by_parts($component, $paymentarea, $itemid)
        ) {
            try {
                return $this->stripe->products->retrieve($record->productid);
            } catch (ApiErrorException $e) {
                // Product exists in Moodle but not in stripe, possibly the keys were switched.
                // Delete product for creation later.
                $this->productrepository->delete_product_by_parts($component, $paymentarea, $itemid);
                return null;
            }
        }
        return null;
    }

    /**
     * Create a product in Stripe and save the ID into the Moodle database.
     *
     * @param string $description
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return Product
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_product(string $description, string $component, string $paymentarea, string $itemid): Product {
        $product = $this->stripe->products->create([
            'name' => $description,
        ]);

        $record = new paygw_product(
            null,
            $component,
            $paymentarea,
            (int)$itemid,
            $product->id
        );
        $this->productrepository->save($record);

        return $product;
    }

    /**
     * Get the first price listed on a product.
     *
     * @param Product $product
     * @param bool $subscription
     * @return Price|null
     */
    public function get_price(Product $product, bool $subscription = false): ?Price {
        try {
            $prices = $this->stripe->prices->all(['product' => $product->id]);
            foreach ($prices as $price) {
                if ($price instanceof Price) {
                    if ($price->active) {
                        if ($subscription && $price->type == 'recurring') {
                            return $price;
                        } else if (!$subscription) {
                            return $price;
                        }
                    }
                }
            }
            return null;
        } catch (ApiErrorException $e) {
            return null;
        }
    }

    /**
     * Create a price against an associated product.
     *
     * @param string $currency Currency
     * @param string $productid Product ID
     * @param float $unitamount Price
     * @param bool $automatictax Toggles insertion of a tax behavior
     * @param string|null $defaultbehavior The default tax behavior for the price, if enabled
     * @param array|null $recurring
     * @return Price
     * @throws ApiErrorException
     */
    public function create_price(
        string $currency,
        string $productid,
        float $unitamount,
        bool $automatictax,
        ?string $defaultbehavior = null,
        ?array $recurring = null
    ) {
        $pricedata = [
            'currency' => $currency,
            'product' => $productid,
            'unit_amount' => $unitamount,
        ];
        if ($automatictax == 1) {
            $pricedata['tax_behavior'] = $defaultbehavior ?? 'inclusive';
        }
        if (is_array($recurring)) {
            $pricedata['recurring'] = $recurring;
        }
        return $this->stripe->prices->create($pricedata);
    }

    /**
     * Creates Stripe product and price objects together.
     * Stores object IDs in Moodle to prevent creating duplicates.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @param array|null $subscription
     * @return array
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_product_and_price(
        object $config,
        payable $payable,
        string $description,
        float $cost,
        string $component,
        string $paymentarea,
        string $itemid,
        ?array $subscription = null
    ) {
        $unitamount = $this->get_unit_amount($cost, $payable->get_currency());
        $currency = strtolower($payable->get_currency());

        if (!$product = $this->get_product($component, $paymentarea, $itemid)) {
            $product = $this->create_product($description, $component, $paymentarea, $itemid);
        }
        if (!$price = $this->get_price($product, is_array($subscription))) {
            $price = $this->create_price(
                $currency,
                $product->id,
                $unitamount,
                $config->enableautomatictax == 1,
                $config->defaulttaxbehavior,
                $subscription
            );
        } else {
            // Check if the price details mismatch in any way.
            if (
                $price->unit_amount != $unitamount || $price->currency != $currency ||
                (is_array($subscription) && $price->type != 'recurring') ||
                (is_array($subscription) && $price->type == 'recurring' &&
                    ($price->recurring->toArray()['interval'] != $subscription['interval'] ||
                        $price->recurring->toArray()['interval_count'] != $subscription['interval_count'])) ||
                ($price->type == 'recurring' && !is_array($subscription))
            ) {
                // We cannot update the price or currency, so we must create a new price.
                $this->stripe->prices->update($price->id, ['active' => false]);
                $price = $this->create_price(
                    $currency,
                    $product->id,
                    $unitamount,
                    $config->enableautomatictax == 1,
                    $config->defaulttaxbehavior,
                    $subscription
                );
            }
            // Set tax behavior if not set already.
            if ($config->enableautomatictax == 1 && (!isset($price->tax_behavior) || $price->tax_behavior === 'unspecified')) {
                $price->updateAttributes(['tax_behavior' => $config->tax_behavior ?? 'inclusive']);
                $price = $this->stripe->prices->update($price->id, ['tax_behavior' => $config->tax_behavior ?? 'inclusive']);
            }
        }
        if ($product->name != $description) {
            $product->name = $description;
            $product = $this->stripe->products->update($product->id, ['name' => $description]);
        }

        return [$product, $price];
    }

    /**
     * Convert the cost into the unit amount accounting for zero-decimal currencies.
     *
     * @param float $cost
     * @param string $currency
     * @return float
     */
    public function get_unit_amount(float $cost, string $currency): float {
        if (in_array(strtoupper($currency), gateway::get_zero_decimal_currencies())) {
            return $cost;
        }
        return $cost * 100;
    }
}
