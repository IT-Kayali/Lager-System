(() => {
    'use strict';

    function init() {
        const form = document.querySelector('[data-product-bulk-runtime]');
        if (!form || form.dataset.bound === '1') return;

        const all = document.querySelector('[data-product-bulk-all]');
        const items = Array.from(document.querySelectorAll('[data-product-bulk-item]'))
            .filter((item) => item.form === form);
        const select = form.elements.namedItem('bulk_action');
        const submit = form.querySelector('[data-product-bulk-submit]');
        const count = form.querySelector('[data-product-bulk-count]');
        if (!all || !select || !submit || !count) return;
        form.dataset.bound = '1';

        function update() {
            const selected = items.filter((item) => item.checked).length;
            all.checked = items.length > 0 && selected === items.length;
            all.indeterminate = selected > 0 && selected < items.length;
            count.textContent = selected + (selected === 1 ? ' Produkt ausgewählt' : ' Produkte ausgewählt');
            submit.disabled = selected === 0 || select.value !== 'delete';
            form.dataset.confirm = selected + (selected === 1
                ? ' ausgewähltes Produkt wirklich löschen?'
                : ' ausgewählte Produkte wirklich löschen?');
        }

        all.addEventListener('change', () => {
            items.forEach((item) => { item.checked = all.checked; });
            update();
        });
        items.forEach((item) => item.addEventListener('change', update));
        select.addEventListener('change', update);
        form.addEventListener('submit', (event) => {
            update();
            if (submit.disabled) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
        window.addEventListener('pageshow', update);
        update();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
