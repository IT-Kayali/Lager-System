@csrf

<input
    type="hidden"
    name="carton_count"
    id="carton_count_real"
    value="{{ old('carton_count', $offer->carton_count ?? '') }}"
>
@error('carton_count')
    <div class="premium-error" data-carton-count-error hidden>{{ $message }}</div>
@enderror

<div class="premium-form-grid">
    <div class="premium-form-field">
        <label for="customer_id">Kunde *</label>
        <select id="customer_id" name="customer_id" class="premium-select" required>
            <option value="">Kunde auswählen</option>
            @foreach ($customers as $customer)
                <option
                    value="{{ $customer->id }}"
                    data-customer-note="{{ $customer->notes }}"
                    @selected((string) old('customer_id', $offer->customer_id) === (string) $customer->id)
                >
                    {{ $customer->customer_number }} — {{ $customer->company_name }} — {{ $customer->group?->name }}
                </option>
            @endforeach
        </select>
        @error('customer_id') <div class="premium-error">{{ $message }}</div> @enderror

        <div id="offer-customer-note" class="offer-customer-note" hidden>
            <div class="offer-customer-note-title">
                <i class="bi bi-exclamation-circle"></i>
                Kundenhinweis
            </div>
            <div class="offer-customer-note-text"></div>
        </div>
    </div>

    <div class="premium-form-field">
        <label for="template_type">PDF-Vorlage *</label>
        @php
            $selectedTemplateType = old('template_type', $offer->template_type ?? '');
        @endphp
        <select id="template_type" name="template_type" class="premium-select" required>
            <option value="">PDF-Vorlage auswählen</option>
            @foreach ($templates as $value => $label)
                <option value="{{ $value }}" @selected((string) $selectedTemplateType === (string) $value)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        @error('template_type') <div class="premium-error">{{ $message }}</div> @enderror
    </div>

    <div class="premium-form-field full">
        <label for="notes">Notizen optional</label>
        <textarea id="notes" name="notes" rows="3" class="premium-textarea">{{ old('notes', $offer->notes) }}</textarea>
        @error('notes') <div class="premium-error">{{ $message }}</div> @enderror
    </div>
</div>

<div class="offer-items-header">
    <h2 data-csp-style="s-39ebdb8d">Produktpositionen</h2>
    <p class="premium-muted" data-csp-style="s-3ab75854">
        Sobald du ein Produkt und eine Menge einträgst, erscheint automatisch die nächste Position.
    </p>
</div>

@php
    $oldItems = old('items');
    $itemsForForm = $oldItems ? collect($oldItems) : collect($formItems);
    if ($itemsForForm->isEmpty()) {
        $itemsForForm = collect([['product_id' => '', 'quantity' => '', 'line_total' => '']]);
    }

    $unitLabels = [
        'gram' => 'g',
        'liter' => 'L',
        'piece' => 'Stk.',
    ];
@endphp

<div id="offer-items" class="offer-items-list">
    @foreach ($itemsForForm as $index => $item)
        <div class="premium-card offer-item-row" data-csp-style="s-7853b09f">
            <div class="premium-form-grid" data-csp-style="s-0ff0a633">
                <div class="premium-form-field">
                    <label>Produkt</label>
                    <select name="items[{{ $index }}][product_id]" class="premium-select">
                        <option value="">Produkt auswählen</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) ($item['product_id'] ?? '') === (string) $product->id)>
                                {{ $product->name }}
                                | {{ number_format($product->offer_editor_available_stock, 2, ',', '.') }} {{ $unitLabels[$product->unit] ?? $product->unit }} verfügbar
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="premium-form-field">
                    <label>Menge/Gewicht</label>
                    <input
                        name="items[{{ $index }}][quantity]"
                        type="number"
                        step="0.01"
                        min="0.01"
                        max="5000"
                        class="premium-input"
                        value="{{ $item['quantity'] ?? '' }}"
                        placeholder="z. B. 50"
                    >
                </div>

                <div class="premium-form-field offer-line-total-field">
                    <div class="offer-line-total-label-row">
                        <label>Gesamtpreis</label>
                        <span class="offer-auto-price-badge">
                            Auto: <strong class="offer-auto-price-value">—</strong>
                        </span>
                    </div>
                    <div class="offer-line-total-control-row">
                        <div class="offer-line-total-input-wrap">
                            <input
                                name="items[{{ $index }}][line_total]"
                                type="number"
                                step="0.01"
                                min="0"
                                class="premium-input offer-line-total-input"
                                value="{{ $item['line_total'] ?? '' }}"
                                placeholder="0,00"
                            >
                            <span class="offer-line-total-currency">€</span>
                        </div>
                        <button type="button" class="premium-icon-btn premium-danger remove-offer-item offer-line-delete-btn" title="Position entfernen">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <div class="premium-muted offer-line-total-hint">Automatisch berechnet · manuell änderbar</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="offer-price-summary">
    <span class="premium-muted">Summe (netto)</span>
    <strong id="offer-products-total">0,00 €</strong>
</div>

<div class="offer-form-actions">
    <button class="premium-btn gold" type="submit">
        <i class="bi bi-check2-circle"></i>
        {{ $submitLabel }}
    </button>

    <a href="{{ route('offers.index') }}" class="premium-btn">
        <i class="bi bi-arrow-left"></i>
        Zurück
    </a>
</div>

<template id="offer-item-template">
    <div class="premium-card offer-item-row" data-csp-style="s-7853b09f">
        <div class="premium-form-grid" data-csp-style="s-0ff0a633">
            <div class="premium-form-field">
                <label>Produkt</label>
                <select data-name="product_id" class="premium-select">
                    <option value="">Produkt auswählen</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">
                            {{ $product->name }}
                            | {{ number_format($product->offer_editor_available_stock, 2, ',', '.') }} {{ $unitLabels[$product->unit] ?? $product->unit }} verfügbar
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="premium-form-field">
                <label>Menge/Gewicht</label>
                <input data-name="quantity" type="number" step="0.01" min="0.01" max="5000" class="premium-input" placeholder="z. B. 50">
            </div>

            <div class="premium-form-field offer-line-total-field">
                <div class="offer-line-total-label-row">
                    <label>Gesamtpreis</label>
                    <span class="offer-auto-price-badge">
                        Auto: <strong class="offer-auto-price-value">—</strong>
                    </span>
                </div>
                <div class="offer-line-total-control-row">
                    <div class="offer-line-total-input-wrap">
                        <input data-name="line_total" type="number" step="0.01" min="0" class="premium-input offer-line-total-input" placeholder="0,00">
                        <span class="offer-line-total-currency">€</span>
                    </div>
                    <button type="button" class="premium-icon-btn premium-danger remove-offer-item offer-line-delete-btn" title="Position entfernen">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="premium-muted offer-line-total-hint">Automatisch berechnet · manuell änderbar</div>
            </div>
        </div>
    </div>
</template>




<!-- OFFER_EDITOR_UI_REFRESH_START -->

<!-- OFFER_EDITOR_UI_REFRESH_END -->



<!-- OFFER_SHIPPING_LAYOUT_FIX_START -->

<!-- OFFER_SHIPPING_LAYOUT_FIX_END -->