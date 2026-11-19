<x-layouts.premium title="Preise" subtitle="Preise pro Produkt, Kundengruppe und Mengenbereich verwalten.">
    @if (session('success'))
        <div class="premium-alert">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="premium-alert" data-csp-style="s-278cc1fe">
            <strong>Bitte prüfe die Preisangaben.</strong>
            <ul data-csp-style="s-aad58e4b">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="premium-card erp-list-card">
        <h3>Excel: Preise nach Kundengruppe</h3>
        <p class="premium-muted">Preise für eine Kundengruppe oder alle Gruppen exportieren und importieren. Die Vorlagen berücksichtigen auch neu angelegte Kundengruppen und Preisstaffeln.</p>
        <form method="GET" class="premium-toolbar erp-list-filter-form">
            <label for="price-excel-group">Preisgruppe</label>
            <select id="price-excel-group" name="customer_group_id" class="premium-select erp-list-select">
                <option value="all">Alle Kundengruppen</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected($selectedGroup?->id === $group->id)>{{ $group->name }}</option>
                @endforeach
            </select>
            <button type="submit" formaction="{{ route('prices.excel.template') }}" class="premium-btn">
                <i class="bi bi-file-earmark-spreadsheet"></i> Importvorlage herunterladen
            </button>
            <button type="submit" formaction="{{ route('prices.excel.export') }}" class="premium-btn">
                <i class="bi bi-download"></i> Preise exportieren
            </button>
            <a class="premium-btn gold" href="{{ route('prices.excel.import.form') }}">
                <i class="bi bi-upload"></i> Preise importieren
            </a>
        </form>
    </section>

    <section class="premium-card erp-list-card">
        <div class="erp-list-filter-card" data-csp-style="s-75c7460b">
        <form method="GET" action="{{ route('prices.index') }}" class="premium-toolbar erp-list-filter-form">
            <select name="product_id" class="premium-select erp-list-select" data-auto-submit>
                @forelse ($products as $product)
                    <option value="{{ $product->id }}" @selected($selectedProduct?->id === $product->id)>
                        {{ $product->name }}
                    </option>
                @empty
                    <option value="">Noch keine Produkte vorhanden</option>
                @endforelse
            </select>

            <select name="customer_group_id" class="premium-select erp-list-select" data-auto-submit>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected($selectedGroup?->id === $group->id)>
                        {{ $group->name }}
                    </option>
                @endforeach
            </select>

            <button class="premium-btn" type="submit">
                <i class="bi bi-arrow-repeat"></i>
                Anzeigen
            </button>
        </form>
        </div>

        @if (! $selectedProduct)
            <div class="premium-placeholder">Bitte zuerst ein Produkt anlegen.</div>
        @elseif (! $selectedGroup)
            <div class="premium-placeholder">Bitte zuerst eine Kundengruppe anlegen.</div>
        @else
            <div class="pricing-mode-info">
                <div>
                    <span class="pricing-mode-kicker">Preislogik</span>
                    <strong>{{ $usesPriceTiers ? 'Kategorie-Preisstaffel' : 'Manuelle Produktregeln' }}</strong>
                    <small>
                        @if ($usesPriceTiers)
                            Die Kategorie verwendet die bestehenden Staffelbereiche. Die Preise bleiben für dieses Produkt und diese Kundengruppe individuell.
                        @else
                            Für dieses Produkt werden freie Von-/Bis-Mengen und Preise für die ausgewählte Kundengruppe verwendet.
                        @endif
                    </small>
                </div>
                <span class="premium-badge {{ $usesPriceTiers ? 'ok' : '' }}">{{ $selectedProduct->unitLabel('de') }}</span>
            </div>

            <form method="POST" action="{{ route('prices.update') }}" id="pricing-form">
                @csrf
                @method('PUT')

                <input type="hidden" name="product_id" value="{{ $selectedProduct->id }}">
                <input type="hidden" name="customer_group_id" value="{{ $selectedGroup->id }}">

                @if ($usesPriceTiers)
                    <div class="premium-table-wrap">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Produkt</th>
                                    <th>Kundengruppe</th>
                                    <th>Mengenbereich</th>
                                    <th>Preisstufe</th>
                                    <th>Preis pro Einheit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tiers as $tier)
                                    <tr>
                                        <td>
                                            <strong>{{ $selectedProduct->name }}</strong>
                                            <div class="premium-muted">{{ $selectedProduct->product_code }}</div>
                                        </td>
                                        <td><x-customer-group-badge :group="$selectedGroup" /></td>
                                        <td>
                                            {{ $tier->max_grams === null
                                                ? 'ab ' . \App\Support\GermanNumber::format($tier->min_grams) . ' ' . $selectedProduct->unitLabel('de')
                                                : \App\Support\GermanNumber::format($tier->min_grams) . '–' . \App\Support\GermanNumber::format($tier->max_grams) . ' ' . $selectedProduct->unitLabel('de') }}
                                        </td>
                                        <td><span class="premium-code">{{ $tier->tier_label }}</span></td>
                                        <td>
                                            <input
                                                name="prices[{{ $tier->id }}]"
                                                type="number"
                                                step="0.00001"
                                                min="0"
                                                class="premium-input"
                                                data-csp-style="s-74d516ba"
                                                value="{{ old('prices.' . $tier->id, $tier->price) }}"
                                            >
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    @php
                        $oldRules = old('rules');
                        $rulesForForm = $oldRules !== null
                            ? collect($oldRules)
                            : $manualRules->map(fn ($rule) => [
                                'min_quantity' => \App\Support\GermanNumber::input($rule->min_quantity),
                                'max_quantity' => $rule->max_quantity !== null ? \App\Support\GermanNumber::input($rule->max_quantity) : '',
                                'price' => $rule->price,
                                'label' => $rule->label,
                            ]);

                        if ($rulesForForm->isEmpty()) {
                            $rulesForForm = collect([[
                                'min_quantity' => '1',
                                'max_quantity' => '',
                                'price' => '',
                                'label' => '',
                            ]]);
                        }
                    @endphp

                    <div class="manual-rule-head" data-manual-price-rules-runtime>
                        <div>
                            <h3>Manuelle Mengenregeln</h3>
                            <p>Beispiel: 1–5 Stück = 3,00 € pro Stück, 6–10 Stück = 2,70 €. Leeres „Bis“ bedeutet unbegrenzt.</p>
                        </div>
                        <button type="button" class="premium-btn" id="add-price-rule">
                            <i class="bi bi-plus-lg"></i>
                            Regel hinzufügen
                        </button>
                    </div>

                    <div class="premium-table-wrap">
                        <table class="premium-table manual-price-table">
                            <thead>
                                <tr>
                                    <th>Von</th>
                                    <th>Bis</th>
                                    <th>Einheit</th>
                                    <th>Preis pro Einheit</th>
                                    <th>Bezeichnung optional</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody id="manual-price-rules">
                                @foreach ($rulesForForm as $index => $rule)
                                    <tr data-price-rule>
                                        <td><input name="rules[{{ $index }}][min_quantity]" type="number" step="0.001" min="0.001" class="premium-input" value="{{ $rule['min_quantity'] ?? '' }}" required></td>
                                        <td><input name="rules[{{ $index }}][max_quantity]" type="number" step="0.001" min="0.001" class="premium-input" value="{{ $rule['max_quantity'] ?? '' }}" placeholder="unbegrenzt"></td>
                                        <td><strong>{{ $selectedProduct->unitLabel('de') }}</strong></td>
                                        <td><input name="rules[{{ $index }}][price]" type="number" step="0.00001" min="0" class="premium-input" value="{{ $rule['price'] ?? '' }}" required></td>
                                        <td><input name="rules[{{ $index }}][label]" class="premium-input" maxlength="100" value="{{ $rule['label'] ?? '' }}" placeholder="z. B. Kartonpreis"></td>
                                        <td><button type="button" class="premium-icon-btn premium-danger remove-price-rule" title="Regel entfernen"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <template id="manual-price-rule-template">
                        <tr data-price-rule>
                            <td><input data-name="min_quantity" type="number" step="0.001" min="0.001" class="premium-input" required></td>
                            <td><input data-name="max_quantity" type="number" step="0.001" min="0.001" class="premium-input" placeholder="unbegrenzt"></td>
                            <td><strong>{{ $selectedProduct->unitLabel('de') }}</strong></td>
                            <td><input data-name="price" type="number" step="0.00001" min="0" class="premium-input" required></td>
                            <td><input data-name="label" class="premium-input" maxlength="100" placeholder="z. B. Kartonpreis"></td>
                            <td><button type="button" class="premium-icon-btn premium-danger remove-price-rule" title="Regel entfernen"><i class="bi bi-trash"></i></button></td>
                        </tr>
                    </template>
                @endif

                @if (auth()->user()?->hasRole([\App\Models\User::ROLE_MANAGER, \App\Models\User::ROLE_SALES]))
                    <div data-csp-style="s-f8051712">
                        <button class="premium-btn gold" type="submit">
                            <i class="bi bi-save"></i>
                            {{ $usesPriceTiers ? 'Alle Preise speichern' : 'Preisregeln speichern' }}
                        </button>
                    </div>
                @else
                    <div class="premium-placeholder" data-csp-style="s-e9f7b175">Du kannst Preise sehen, aber nicht ändern.</div>
                @endif
            </form>
        @endif
    </section>

    @if ($selectedProduct && $selectedGroup && ! $usesPriceTiers)
        <script src="{{ asset('js/manual-price-rules-runtime.js') }}" defer></script>
    @endif


</x-layouts.premium>