# Stripe

Provides a payment gateway with Stripe in Moodle

## Setup

1. Install the plugin
2. Register for Stripe
3. Grab your Stripe API and Secret keys
4. Configure the Stripe payment account in Moodle with those keys and your payment method
5. Add 'Enrolment on payment' to the Moodle courses that you want
6. Configure the enrolment method with the currency you want to use

## Details

Stripe offers over 135 currencies, and many payment methods including cards, bank transfer, Apple Pay, SEPA, and more. The plugin supports all of the payment methods that your Stripe account is configured to use.

Stripe offers 106+ currencies however certain payment gateways only support a subset of those.  
E.g. Alipay only supports CNY and NZD currencies.

The plugin supports using promotion/coupon codes and automatic tax calculation.

## Support

Create an issue on the [GitHub repository](https://github.com/alexmorrisnz/moodle-paygw_stripe/issues), or email contact@navra.nz.

## Warm Thanks

Thanks to [E-learning Co., Ltd](https://www.e-learning.co.jp/) for sponsoring the work to add subscription support to this plugin.
