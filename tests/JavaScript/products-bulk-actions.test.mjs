import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../public/js/products-bulk-actions.js', import.meta.url), 'utf8');

function control(properties = {}) {
    const listeners = new Map();
    return {
        ...properties,
        addEventListener(name, handler) { listeners.set(name, handler); },
        fire(name, event = {}) { listeners.get(name)?.(event); },
    };
}

function setup(size = 3) {
    const all = control({ checked: false, indeterminate: false });
    const select = control({ value: '' });
    const submit = control({ disabled: true });
    const count = control({ textContent: '' });
    const form = control({
        dataset: {},
        elements: { namedItem: () => select },
        querySelector: (selector) => ({
            '[data-product-bulk-submit]': submit,
            '[data-product-bulk-count]': count,
        })[selector],
    });
    const items = Array.from({ length: size }, () => control({ checked: false, form }));
    const outside = control({ checked: false, form: {} });
    const document = {
        readyState: 'complete',
        querySelector: (selector) => ({
            '[data-product-bulk-runtime]': form,
            '[data-product-bulk-all]': all,
        })[selector],
        querySelectorAll: () => [...items, outside],
    };
    const window = control();
    runInNewContext(source, { document, window });
    return { form, all, select, submit, count, items, outside, window };
}

test('requires selected rows and an action, and maintains the indeterminate master checkbox', () => {
    const { all, select, submit, count, items, form } = setup();
    assert.equal(submit.disabled, true);
    items[0].checked = true;
    items[0].fire('change');
    assert.equal(all.indeterminate, true);
    assert.equal(all.checked, false);
    assert.equal(count.textContent, '1 Produkt ausgewählt');
    assert.equal(submit.disabled, true);
    select.value = 'delete';
    select.fire('change');
    assert.equal(submit.disabled, false);
    assert.equal(form.dataset.confirm, '1 ausgewähltes Produkt wirklich löschen?');
});

test('select all affects only controls associated with the current bulk form', () => {
    const { all, select, submit, count, items, outside, form } = setup();
    select.value = 'delete';
    all.checked = true;
    all.fire('change');
    assert.ok(items.every((item) => item.checked));
    assert.equal(outside.checked, false);
    assert.equal(count.textContent, '3 Produkte ausgewählt');
    assert.equal(all.indeterminate, false);
    assert.equal(submit.disabled, false);
    assert.equal(form.dataset.confirm, '3 ausgewählte Produkte wirklich löschen?');
    all.checked = false;
    all.fire('change');
    assert.ok(items.every((item) => !item.checked));
    assert.equal(submit.disabled, true);
});

test('blocks an empty submission and recalculates selection when restoring the page', () => {
    const { form, window, select, items, submit } = setup();
    let prevented = false;
    let stopped = false;
    form.fire('submit', {
        preventDefault() { prevented = true; },
        stopImmediatePropagation() { stopped = true; },
    });
    assert.equal(prevented, true);
    assert.equal(stopped, true);
    select.value = 'delete';
    items[0].checked = true;
    window.fire('pageshow');
    assert.equal(submit.disabled, false);
});
