const nativeParseFloat = window.parseFloat.bind(window);

function normalizeGermanNumber(value) {
    if (typeof value !== 'string') {
        return value;
    }

    let normalized = value
        .replace(/\u00a0/g, '')
        .replace(/\s+/g, '')
        .trim();

    if (!normalized) {
        return normalized;
    }

    const comma = normalized.lastIndexOf(',');
    const dot = normalized.lastIndexOf('.');

    if (comma !== -1 && dot !== -1) {
        if (comma > dot) {
            normalized = normalized.replace(/\./g, '').replace(',', '.');
        } else {
            normalized = normalized.replace(/,/g, '');
        }
    } else if (comma !== -1) {
        normalized = normalized.replace(',', '.');
    }

    return normalized;
}

function parseGermanNumber(value) {
    return nativeParseFloat(normalizeGermanNumber(String(value ?? '')));
}

function formatGermanNumber(value, maximumFractionDigits = 2, minimumFractionDigits = 2) {
    const parsed = parseGermanNumber(value);

    if (!Number.isFinite(parsed)) {
        return value;
    }

    return new Intl.NumberFormat('de-DE', {
        minimumFractionDigits,
        maximumFractionDigits,
        useGrouping: true,
    }).format(parsed);
}

window.parseGermanNumber = parseGermanNumber;
window.formatGermanNumber = (value) => formatGermanNumber(value, 2);

const decimalFieldSelector = [
    'input[name="quantity"]',
    'input[name="minimum_stock"]',
    'input[name="amount"]',
    'input[name="shipping_price_gross"]',
    'input[name="tax_rate"]',
    'input[name^="prices["]',
    'input[name^="rules["][name$="[price]"]',
    'input[name$="[quantity]"]',
    'input[data-name="quantity"]',
].join(',');

function isQuantityField(input) {
    const name = input.getAttribute('name') || '';

    return name === 'quantity'
        || name.endsWith('[quantity]')
        || input.dataset.name === 'quantity';
}

function isPriceField(input) {
    const name = input.getAttribute('name') || '';

    return /^prices\[[^\]]+\]$/.test(name)
        || /^rules\[\d+\]\[price\]$/.test(name);
}

function enhanceDecimalField(input) {
    if (
        !(input instanceof HTMLInputElement)
        || input.type === 'hidden'
        || input.dataset.germanNumberEnhanced === '1'
    ) {
        return;
    }

    input.dataset.germanNumberEnhanced = '1';
    input.dataset.originalType = input.type;
    input.type = 'text';
    input.inputMode = 'decimal';
    input.autocomplete = 'off';

    const format = () => {
        if (input.value.trim() !== '') {
            const isPrice = isPriceField(input);
            const maximumFractionDigits = isPrice ? 5 : (isQuantityField(input) ? 3 : 2);

            input.value = formatGermanNumber(
                input.value,
                maximumFractionDigits,
                isPrice ? 5 : 2,
            );
        }
    };

    input.addEventListener('blur', format);
    input.addEventListener('change', () => {
        if (document.activeElement !== input) {
            format();
        }
    });

    format();
}

function formatDecimalMatches(text) {
    return text.replace(/-?\d{1,3}(?:\.\d{3})*,\d+|-?\d+,\d+/g, (match) => {
        const fraction = match.split(',').pop() || '';

        if (fraction.length === 2) {
            return match;
        }

        return formatGermanNumber(match, 2);
    });
}

function normalizeStatisticsNumbers() {
    const shell = document.querySelector('.stats-shell');

    if (!shell) {
        return;
    }

    shell.querySelectorAll(
        '.stats-kpi-value, .stats-kpi-note, .stats-change, .stats-mini strong, .premium-table td'
    ).forEach((element) => {
        element.childNodes.forEach((node) => {
            if (node.nodeType === Node.TEXT_NODE && node.nodeValue?.includes(',')) {
                node.nodeValue = formatDecimalMatches(node.nodeValue);
            }
        });
    });
}

function configureChartLocale() {
    if (window.Chart?.defaults) {
        window.Chart.defaults.locale = 'de-DE';
    }
}

export function initGermanNumbers() {
    document.querySelectorAll(decimalFieldSelector).forEach(enhanceDecimalField);
    normalizeStatisticsNumbers();
    configureChartLocale();
}

initGermanNumbers();
document.addEventListener('DOMContentLoaded', initGermanNumbers);
document.addEventListener('livewire:navigated', initGermanNumbers);

new MutationObserver(initGermanNumbers).observe(document.documentElement, {
    childList: true,
    subtree: true,
});
