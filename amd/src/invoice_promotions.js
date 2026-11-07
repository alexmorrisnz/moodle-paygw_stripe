// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Purchase-bound invoice code entry in the core gateway selector.
 *
 * @module paygw_stripe/invoice_promotions
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {getString} from 'core/str';

const selector = '.core_payment_gateways_modal';
const purchases = new Map();
const keyFor = args => JSON.stringify([args.component, args.paymentarea, String(args.itemid)]);
let initialized = false;
let discovering = false;
let discoveryTimedOut = false;
let sequence = 0;

const request = (state, code = '', apply = false) => Ajax.call([{
    methodname: 'paygw_stripe_invoice_promotion',
    args: {...state.args, code, apply},
}])[0];

const message = (state, text, error = false) => {
    const status = state.editor.querySelector('[data-region="promotion-status"]');
    status.textContent = text;
    status.classList.toggle('text-danger', error);
    state.editor.querySelector('input')?.setAttribute('aria-invalid', String(error));
};

const applyCode = state => {
    // Apply and Continue share the same in-flight validation, not concurrent redemptions.
    if (state.pending) {
        return state.pending;
    }
    const input = state.editor.querySelector('input');
    const code = input?.value.trim() ?? '';
    const button = state.editor.querySelector('button');
    if (button) {
        button.disabled = true;
    }
    state.pending = (async() => {
        try {
            message(state, await getString('invoicepromotionchecking', 'paygw_stripe'));
            const result = await request(state, code, true);
            if (!result.enabled) {
                throw new Error(await getString('invoicepromotiondisabled', 'paygw_stripe'));
            }
            if ((input?.value.trim() ?? '') !== code) {
                throw new Error(await getString('invoicepromotionchanged', 'paygw_stripe'));
            }
            if (input) {
                input.value = result.code;
            }
            message(state, result.message);
            return result.token;
        } catch (error) {
            message(state, error.message || String(error), true);
            throw error;
        } finally {
            state.pending = null;
            if (button) {
                button.disabled = false;
            }
        }
    })();
    return state.pending;
};

const mount = async(state) => {
    const options = await request(state);
    if (!options.enabled || !state.root.isConnected) {
        return;
    }
    const {html, js} = await Templates.renderForPromise('paygw_stripe/invoice_promotion', {
        inputid: `stripe-invoice-code-${++sequence}`,
        resume: options.resume,
    });
    if (!state.root.isConnected) {
        return;
    }
    Templates.appendNodeContents(state.root, html, js);
    state.editor = state.root.querySelector('[data-region="stripe-invoice-promotion"]');
    const visibility = () => {
        if (!state.root.isConnected) {
            window.clearInterval(timer);
            state.root.removeEventListener('change', visibility);
            if (purchases.get(keyFor(state.args)) === state) {
                purchases.delete(keyFor(state.args));
            }
            return;
        }
        state.editor.hidden = state.root.querySelector('input[type="radio"]:checked')?.value !== 'stripe';
    };
    // Setting radio.checked programmatically emits neither a change event nor a DOM mutation.
    const timer = window.setInterval(visibility, 200);
    state.root.addEventListener('change', visibility);
    visibility();
    state.editor.querySelector('input')?.addEventListener('input', () => message(state, ''));
    const apply = () => applyCode(state).catch(() => {
        // Validation errors are already displayed in the editor's live status region.
    });
    state.editor.querySelector('button')?.addEventListener('click', apply);
    state.editor.querySelector('input')?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            apply();
        }
    });
};

/** Observe only until the next gateway modal is found, with a fresh purchase binding on every open. */
export const init = () => {
    if (initialized) {
        return;
    }
    initialized = true;
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-action="core_payment/triggerPayment"]');
        if (!trigger) {
            return;
        }
        if (discovering || Array.from(document.querySelectorAll(selector)).some(root => root.isConnected)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        // After a timeout, a late modal cannot be safely associated with another trigger.
        if (discoveryTimedOut) {
            return;
        }
        discovering = true;
        const args = {
            component: trigger.dataset.component,
            paymentarea: trigger.dataset.paymentarea,
            itemid: Number(trigger.dataset.itemid),
        };
        const existing = new Set(document.querySelectorAll(selector));
        const observer = new MutationObserver(() => {
            const root = Array.from(document.querySelectorAll(selector)).find(node => !existing.has(node));
            if (root) {
                observer.disconnect();
                window.clearTimeout(timeout);
                discovering = false;
                const state = {args, root, editor: null, pending: null};
                purchases.set(keyFor(args), state);
                state.ready = mount(state).catch(error => {
                    state.editor = null;
                    Notification.exception(error);
                });
            }
        });
        const timeout = window.setTimeout(() => {
            observer.disconnect();
            discovering = false;
            discoveryTimedOut = true;
        }, 10000);
        observer.observe(document.body, {childList: true, subtree: true});
    }, true);
};

/**
 * Validate on Continue; missing editors use the existing server-side code-entry page.
 *
 * @param {string} component Payment component.
 * @param {string} paymentarea Payment area.
 * @param {number} itemid Purchase item.
 * @returns {Promise<string>} Opaque selection token, or empty for the fallback page.
 */
export const preparePayment = async(component, paymentarea, itemid) => {
    const state = purchases.get(keyFor({component, paymentarea, itemid}));
    if (!state || !state.root.isConnected) {
        return '';
    }
    await state.ready;
    return state.editor?.isConnected ? applyCode(state) : '';
};
