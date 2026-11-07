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

// Run with: node public/payment/gateway/stripe/tests/javascript/invoice_promotions_test.cjs
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const flush = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return {promise, resolve, reject};
};

class Element {
    constructor() {
        this.value = '';
        this.isConnected = true;
        this.listeners = {};
        this.attributes = {};
        this.classList = {toggle() {}};
    }
    addEventListener(name, listener) {
        this.listeners[name] = listener;
    }
    removeEventListener(name, listener) {
        if (this.listeners[name] === listener) {
            delete this.listeners[name];
        }
    }
    setAttribute(name, value) {
        this.attributes[name] = value;
    }
}

function fixture(handler) {
    const roots = [];
    const observers = [];
    const calls = [];
    const errors = [];
    const events = {};
    const timers = new Map();
    const timeouts = new Map();
    let timerId = 0;
    const module = {};
    const context = {
        window: {
            setTimeout: callback => {
                timeouts.set(++timerId, callback);
                return timerId;
            },
            clearTimeout: id => timeouts.delete(id),
            setInterval: callback => {
                timers.set(++timerId, callback);
                return timerId;
            },
            clearInterval: id => timers.delete(id),
        },
        document: {
            body: {},
            querySelectorAll: () => roots,
            addEventListener: (name, listener) => {
                events[name] = listener;
            },
        },
        MutationObserver: class {
            constructor(callback) {
                this.callback = callback;
                observers.push(this);
            }
            observe() {
                this.active = true;
            }
            disconnect() {
                this.active = false;
            }
        },
        define: (name, dependencies, factory) => factory(module, {
            call: requests => requests.map(({args}) => {
                calls.push(args);
                return Promise.resolve().then(() => handler(args));
            }),
        }, {
            renderForPromise: async(name, data) => ({html: data, js: ''}),
            appendNodeContents: (root, data) => {
                root.editor = new Element();
                root.input = data.resume ? null : new Element();
                root.button = data.resume ? null : new Element();
                root.status = new Element();
                root.editor.querySelector = selector => selector === 'input' ? root.input :
                    selector === 'button' ? root.button : root.status;
            },
        }, {exception: error => errors.push(error)}, {getString: async key => key}),
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../amd/build/invoice_promotions.min.js'), 'utf8'), context);
    module.init();
    return {
        module, calls, errors, observers, timers,
        tick: () => Array.from(timers.values()).forEach(callback => callback()),
        expireDiscovery: () => {
            const callbacks = Array.from(timeouts.values());
            timeouts.clear();
            callbacks.forEach(callback => callback());
        },
        click(itemid = 1) {
            const dataset = {component: 'enrol_fee', paymentarea: 'fee', itemid: String(itemid)};
            const event = {
                target: {closest: () => ({dataset})},
                prevented: false,
                stopped: false,
                preventDefault() {
                    this.prevented = true;
                },
                stopImmediatePropagation() {
                    this.stopped = true;
                },
            };
            events.click(event);
            return event;
        },
        async insertModal(selected = 'stripe') {
            const root = new Element();
            root.selected = selected;
            root.querySelector = selector => selector.includes('radio') ?
                (root.selected ? {value: root.selected} : null) : root.editor;
            roots.push(root);
            observers.filter(observer => observer.active).forEach(observer => observer.callback());
            await flush();
            return root;
        },
        async open(itemid = 1, selected = 'stripe') {
            const event = this.click(itemid);
            assert.equal(event.stopped, false);
            return this.insertModal(selected);
        },
    };
}

const options = {enabled: true, resume: false};
const selection = {enabled: true, code: 'SAVE', token: 'opaque', message: 'Discount applied'};

test('blocks overlapping purchases before discovery and while the first popup is open', async() => {
    const f = fixture(args => args.apply ? {...selection, token: String(args.itemid)} : options);
    assert.equal(f.click(24).stopped, false);
    const overlapping = f.click(25);
    assert.equal(overlapping.prevented, true);
    assert.equal(overlapping.stopped, true);
    const first = await f.insertModal();
    assert.equal(f.calls[0].itemid, 24);
    assert.equal(f.click(25).stopped, true);
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 24), '24');
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 25), '');
    first.isConnected = false;
    first.editor.isConnected = false;
    const second = await f.open(25);
    assert.equal(second.input.value, '');
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 25), '25');
});

test('discovery timeout permits fallback without binding late modals to later purchases', async() => {
    const f = fixture(() => options);
    f.click(24);
    f.expireDiscovery();
    assert.equal(f.click(25).stopped, false);
    await f.insertModal();
    assert.equal(f.calls.length, 0);
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 24), '');
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 25), '');
});

test('shows only for Stripe and disconnects modal discovery', async() => {
    const f = fixture(() => options);
    const root = await f.open(1, 'paypal');
    assert.equal(root.editor.hidden, true);
    root.selected = 'stripe';
    root.listeners.change();
    assert.equal(root.editor.hidden, false);
    assert.equal(f.observers.some(observer => observer.active), false);
});

test('waits for gateway radios without continuing document-wide discovery', async() => {
    const f = fixture(() => options);
    const root = await f.open(1, null);
    assert.equal(root.editor.hidden, true);
    assert.equal(f.observers[0].active, false);
    root.selected = 'stripe';
    f.tick();
    assert.equal(root.editor.hidden, false);
    assert.equal(f.observers.some(observer => observer.active), false);
});

test('tracks programmatic gateway selection after radios already exist', async() => {
    const f = fixture(() => options);
    const root = await f.open(24, 'paypal');
    assert.equal(root.editor.hidden, true);
    root.selected = 'stripe';
    f.tick();
    assert.equal(root.editor.hidden, false);
    root.selected = 'paypal';
    f.tick();
    assert.equal(root.editor.hidden, true);
});

test('cleans up closed popups without removing the reopened purchase state', async() => {
    const f = fixture(args => args.apply ? selection : options);
    const first = await f.open(24, null);
    first.isConnected = false;
    const reopened = await f.open(24, 'stripe');
    f.tick();
    assert.equal(f.timers.size, 1);
    assert.equal(first.listeners.change, undefined);
    assert.equal(reopened.editor.hidden, false);
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 24), 'opaque');
    reopened.isConnected = false;
    f.tick();
    assert.equal(f.timers.size, 0);
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 24), '');
});

test('Apply and Continue share a check and show the returned estimate', async() => {
    const pending = deferred();
    const f = fixture(args => args.apply ? pending.promise : options);
    const root = await f.open();
    root.input.value = 'SAVE';
    root.button.listeners.click();
    const payment = f.module.preparePayment('enrol_fee', 'fee', 1);
    await flush();
    assert.equal(f.calls.filter(args => args.apply).length, 1);
    pending.resolve(selection);
    assert.equal(await payment, 'opaque');
    assert.equal(root.status.textContent, 'Discount applied');
    assert.equal(root.button.disabled, false);
});

test('changed input blocks continuation and can be retried', async() => {
    const pending = deferred();
    const f = fixture(args => args.apply ? pending.promise : options);
    const root = await f.open();
    root.input.value = 'SAVE';
    const payment = f.module.preparePayment('enrol_fee', 'fee', 1);
    await flush();
    root.input.value = 'OTHER';
    pending.resolve(selection);
    await assert.rejects(payment, /invoicepromotionchanged/);
    assert.equal(root.input.attributes['aria-invalid'], 'true');
    root.input.value = 'SAVE';
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 1), 'opaque');
});

test('invalid codes surface inline and do not return a continuation token', async() => {
    const f = fixture(args => {
        if (args.apply) {
            throw new Error('Invalid code');
        }
        return options;
    });
    const root = await f.open();
    root.input.value = 'INVALID';
    await assert.rejects(f.module.preparePayment('enrol_fee', 'fee', 1), /Invalid code/);
    assert.equal(root.status.textContent, 'Invalid code');
    assert.equal(root.button.disabled, false);
});

test('reopened popups and different purchases never share input', async() => {
    const f = fixture(args => args.apply ? {...selection, token: String(args.itemid)} : options);
    const first = await f.open(1);
    first.input.value = 'SAVE';
    first.isConnected = false;
    first.editor.isConnected = false;
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 1), '');
    const second = await f.open(2);
    assert.equal(second.input.value, '');
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 2), '2');
    second.isConnected = false;
    second.editor.isConnected = false;
    const reopened = await f.open(1);
    assert.equal(reopened.input.value, '');
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 1), '1');
});

test('existing invoices continue without duplicating the static resume explanation', async() => {
    const f = fixture(args => args.apply ?
        {...selection, resume: true, code: '', message: 'Continue with the existing invoice'} :
        {enabled: true, resume: true});
    const root = await f.open();
    assert.equal(root.input, null);
    assert.equal(await f.module.preparePayment('enrol_fee', 'fee', 1), 'opaque');
    assert.equal(root.status.textContent, '');
    assert.equal(f.calls.at(-1).code, '');
});

test('existing invoice continuation errors remain visible', async() => {
    const f = fixture(args => {
        if (args.apply) {
            throw new Error('Service unavailable');
        }
        return {enabled: true, resume: true};
    });
    const root = await f.open();
    await assert.rejects(f.module.preparePayment('enrol_fee', 'fee', 1), /Service unavailable/);
    assert.equal(root.status.textContent, 'Service unavailable');
});

test('disabled feature and failed options use fallback, with failures reported', async() => {
    const disabled = fixture(() => ({enabled: false}));
    const root = await disabled.open();
    assert.equal(root.editor, undefined);
    assert.equal(await disabled.module.preparePayment('enrol_fee', 'fee', 1), '');
    const failed = fixture(() => {
        throw new Error('Service unavailable');
    });
    await failed.open();
    assert.equal(failed.errors.length, 1);
    assert.equal(await failed.module.preparePayment('enrol_fee', 'fee', 1), '');
});
