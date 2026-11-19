import assert from 'node:assert/strict';
import test from 'node:test';

class FakeInput {
    constructor(name, value, type = 'text') {
        this.name = name;
        this.value = value;
        this.type = type;
        this.dataset = {};
        this.listeners = new Map();
    }

    getAttribute(attribute) {
        return attribute === 'name' ? this.name : null;
    }

    addEventListener(event, listener) {
        this.listeners.set(event, listener);
    }

    dispatch(event) {
        this.listeners.get(event)?.();
    }
}

const tier = new FakeInput('prices[42]', '12,34567');
const wholeTier = new FakeInput('prices[43]', '2,00000');
const verySmallTier = new FakeInput('prices[44]', '0,00001');
const manualPrice = new FakeInput('rules[0][price]', '3,12345');
const quantity = new FakeInput('quantity', '12,345');
const amount = new FakeInput('amount', '12,34');
const hidden = new FakeInput('prices[45]', '8,12345', 'hidden');
const inputs = [tier, wholeTier, verySmallTier, manualPrice, quantity, amount, hidden];
const documentListeners = new Map();
let selectedFields = '';

globalThis.window = { parseFloat: Number.parseFloat };
globalThis.HTMLInputElement = FakeInput;
globalThis.document = {
    activeElement: null,
    documentElement: {},
    querySelectorAll: (selector) => {
        selectedFields = selector;
        return inputs;
    },
    querySelector: () => null,
    addEventListener: (event, listener) => documentListeners.set(event, listener),
};
globalThis.MutationObserver = class {
    observe() {}
};

const { initGermanNumbers } = await import('../../resources/js/german-numbers.js');

test('keeps five decimal places on tier and manual prices after initialization', () => {
    assert.match(selectedFields, /name\^="prices\["/);
    assert.match(selectedFields, /name\^="rules\["/);
    assert.equal(tier.value, '12,34567');
    assert.equal(wholeTier.value, '2,00000');
    assert.equal(verySmallTier.value, '0,00001');
    assert.equal(manualPrice.value, '3,12345');
});

test('does not affect existing quantity and ordinary money formatting', () => {
    assert.equal(quantity.value, '12,345');
    assert.equal(amount.value, '12,34');
    assert.equal(window.formatGermanNumber('12,34567'), '12,35');
    assert.equal(hidden.value, '8,12345');
    assert.equal(hidden.type, 'hidden');
});

test('preserves five decimals after blur, change and repeated initialization', () => {
    tier.value = '3,00009';
    tier.dispatch('blur');
    assert.equal(tier.value, '3,00009');

    manualPrice.value = '0.00001';
    manualPrice.dispatch('change');
    assert.equal(manualPrice.value, '0,00001');

    const listeners = tier.listeners.size;
    initGermanNumbers();
    documentListeners.get('DOMContentLoaded')?.();
    documentListeners.get('livewire:navigated')?.();
    assert.equal(tier.value, '3,00009');
    assert.equal(tier.listeners.size, listeners);
});
