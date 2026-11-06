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

namespace paygw_stripe\form;

use paygw_stripe\local\service\invoice_promotion_request;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Code entry for both gateway selection and direct invoice payment links.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_promotion_form extends \moodleform {
    /** @var array|null Validated selection from the current POST. */
    private ?array $selection = null;

    /** Define the optional code editor and explicit continuation. */
    public function definition(): void {
        $form = $this->_form;
        foreach (['component' => PARAM_COMPONENT, 'paymentarea' => PARAM_AREA, 'itemid' => PARAM_INT] as $name => $type) {
            $form->addElement('hidden', $name, $this->_customdata[$name]);
            $form->setType($name, $type);
        }
        $resume = !empty($this->_customdata['resume']);
        $form->addElement(
            'static',
            'explanation',
            '',
            get_string($resume ? 'invoicepromotionresume' : 'invoicepromotionintro', 'paygw_stripe')
        );
        $buttons = [];
        if (!$resume) {
            $form->addElement(
                'text',
                'promotioncode',
                get_string('invoicepromotioncode', 'paygw_stripe'),
                ['maxlength' => 200, 'autocomplete' => 'off']
            );
            $form->setType('promotioncode', PARAM_RAW_TRIMMED);
            $form->addHelpButton('promotioncode', 'invoicepromotioncode', 'paygw_stripe');
            $buttons[] = $form->createElement('submit', 'applycode', get_string('invoicepromotionapply', 'paygw_stripe'));
        }
        $buttons[] = $form->createElement(
            'submit',
            'continuepayment',
            get_string($resume ? 'invoicepromotioncontinueexisting' : 'invoicepromotioncontinue', 'paygw_stripe')
        );
        $buttons[] = $form->createElement('cancel');
        $form->addGroup($buttons, 'buttons', '', ' ', false);
        $form->closeHeaderBefore('buttons');
    }

    /**
     * Validate using server-derived configuration, amount and customer.
     * @param array $data Submitted values.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        try {
            $this->selection = invoice_promotion_request::options(
                $this->_customdata['component'],
                $this->_customdata['paymentarea'],
                $this->_customdata['itemid'],
                $data['promotioncode'] ?? '',
                true
            );
            if (!$this->selection['enabled']) {
                throw new \moodle_exception('invoicepromotiondisabled', 'paygw_stripe');
            }
        } catch (\moodle_exception $e) {
            $errors['promotioncode'] = $e->getMessage();
        }
        return $errors;
    }

    /** @return array|null Validated selection, if submitted successfully. */
    public function get_selection(): ?array {
        return $this->selection;
    }
}
