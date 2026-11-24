<x-layouts.premium title="Produkte" subtitle="Produktübersicht mit den wichtigsten Daten.">
    @php
        $isSales = auth()->user()?->isSales();
        $productIndexRoute = $isSales ? 'sales.products.index' : 'products.index';
        $productListQuery = $productListQuery ?? [];
        $productShowRoute = $isSales ? 'sales.products.show' : 'products.show';
        $branchCreateRoute = $isSales ? 'sales.branch-withdrawals.create' : 'branch-withdrawals.create';
        $canCreateBranchWithdrawal = $isSales
            || (auth()->user()?->isManager() ?? false)
            || (auth()->user()?->isWarehouse() ?? false);
    @endphp

    <section class="products-page-actions erp-list-toolbar">
        <div class="products-page-search-card erp-list-filter-card">
            <form method="GET" action="{{ route($productIndexRoute) }}" class="products-search-modern erp-list-filter-form">
                <div class="products-search-field erp-list-search">
                    <i class="bi bi-search"></i>
                    <input name="search" value="{{ $search ?? '' }}" class="premium-input" placeholder="Produkt suchen...">
                </div>

                <div class="products-search-filter">
                    <select name="search_field" class="premium-input products-search-select erp-list-select" aria-label="Suchfeld auswählen">
                        <option value="all" @selected(($searchField ?? 'all') === 'all')>Alle</option>
                        <option value="name" @selected(($searchField ?? 'all') === 'name')>Produktbezeichnung</option>
                        <option value="manufacturer" @selected(($searchField ?? 'all') === 'manufacturer')>Fake Name</option>
                        <option value="code" @selected(($searchField ?? 'all') === 'code')>Code-Nummer</option>
                        <option value="supplier" @selected(($searchField ?? 'all') === 'supplier')>Lieferant</option>
                    </select>
                </div>

                <label class="products-exact-search erp-list-exact">
                    <input type="checkbox" name="exact" value="1" @checked($exact ?? false)>
                    <span>Exakter Wert</span>
                </label>

                <button class="premium-btn" type="submit">
                    <i class="bi bi-search"></i>
                    Suchen
                </button>

                @if (! empty($search) || ($searchField ?? 'all') !== 'all' || ($exact ?? false))
                    <a href="{{ route($productIndexRoute) }}" class="premium-btn products-reset-btn">
                        <i class="bi bi-x-lg"></i>
                        Zurücksetzen
                    </a>
                @endif
            </form>
        </div>

        @unless($isSales)
            <div class="products-page-action-buttons erp-list-actions">
                <a href="{{ route('products.excel.export') }}" class="premium-btn">
                    <i class="bi bi-download"></i>
                    Excel exportieren
                </a>

                <a href="{{ route('products.excel.import.form') }}" class="premium-btn">
                    <i class="bi bi-upload"></i>
                    Excel importieren
                </a>

                <a href="{{ route('products.create') }}" class="premium-btn gold">
                    <i class="bi bi-plus-lg"></i>
                    Produkt hinzufügen
                </a>
            </div>
        @endunless
    </section>

    <section class="products-modern-card erp-list-card">
        @unless($isSales)
            <form id="products-bulk-form" method="POST" action="{{ route('products.bulk-action', $productListQuery) }}" class="products-bulk-toolbar" data-product-bulk-runtime data-confirm="Ausgewählte Produkte wirklich löschen?">
                @csrf
                <select name="bulk_action" class="premium-input products-bulk-select" aria-label="Aktionen für ausgewählte Produkte" required>
                    <option value="">Aktionen</option>
                    <option value="delete">Ausgewählte löschen</option>
                </select>
                <button type="submit" class="premium-btn" data-product-bulk-submit disabled>Anwenden</button>
                <span class="products-bulk-count" data-product-bulk-count aria-live="polite">0 Produkte ausgewählt</span>
            </form>
        @endunless
        <div class="products-table-shell modern-products-table-shell erp-list-table-shell">
            <table class="products-clean-table modern-products-table">
                <thead>
                    <tr>
                        @unless($isSales)
                            <th class="products-bulk-cell"><input type="checkbox" data-product-bulk-all aria-label="Alle Produkte dieser Seite auswählen" @disabled($products->isEmpty())></th>
                        @endunless
                        <th>Produktbezeichnung</th>
                        <th>Fake Name</th>
                        <th>Code-Nummer</th>
                        <th>Lieferant</th>
                        <th>Kategorie</th>
                        <th>Verfügbare Menge</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($products as $product)
                        @php
                            $statusLabels = ['ok' => 'OK', 'low' => 'Niedrig', 'critical' => 'Kritisch'];
                            $unitLabel = $product->unitLabel('de');
                            $supplierName = $product->supplierRecord?->company_name ?: $product->supplier ?: '—';
                            $codeNumber = $product->serial_number ?: ($product->product_code ?: '—');
                            $stockStatus = $product->stock_status;
                        @endphp

                        <tr>
                            @unless($isSales)
                                <td class="products-bulk-cell"><input type="checkbox" name="product_ids[]" value="{{ $product->id }}" form="products-bulk-form" data-product-bulk-item aria-label="Produkt {{ $product->name }} auswählen"></td>
                            @endunless
                            <td>
                                <a class="product-name product-name-link" href="{{ route($productShowRoute, $product) }}">
                                    {{ $product->name }}
                                </a>
                            </td>
                            <td>{{ $product->manufacturer_designation ?: '—' }}</td>
                            <td><span class="products-code">{{ $codeNumber }}</span></td>
                            <td>{{ $supplierName }}</td>
                            <td>{{ $product->categories->pluck('name')->implode(', ') ?: 'Ohne Kategorie' }}</td>
                            <td>
                                <span class="products-stock-value {{ $stockStatus }}">{{ number_format((float) $product->available_stock, 2, ',', '.') }}</span>
                                <span class="unit-small">{{ $unitLabel }}</span>
                            </td>
                            <td>
                                <span class="products-status-pill {{ $stockStatus }}">{{ $statusLabels[$stockStatus] ?? $stockStatus }}</span>
                            </td>
                            <td>
                                <div class="premium-actions products-actions">
                                    <a class="premium-icon-btn" href="{{ route($productShowRoute, $product) }}" title="Vorschau">
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    @if ($canCreateBranchWithdrawal)
                                        <a class="premium-icon-btn" href="{{ route($branchCreateRoute, ['produkt' => $product->name]) }}" title="Filialausgang erstellen">
                                            <i class="bi bi-shop"></i>
                                        </a>
                                    @endif

                                    @unless($isSales)
                                        <a class="premium-icon-btn" href="{{ route('batches.create', ['produkt' => $product->name]) }}" title="Bestand buchen">
                                            <i class="bi bi-layers"></i>
                                        </a>

                                        <a class="premium-icon-btn" href="{{ route('products.edit', $product) }}" title="Bearbeiten">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>

                                        <form method="POST" action="{{ route('products.destroy', ['product' => $product] + $productListQuery) }}" data-confirm="Produkt wirklich löschen?">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="expected_product_id" value="{{ $product->id }}">
                                            <button class="premium-icon-btn premium-danger" type="submit" title="Löschen">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $isSales ? 8 : 9 }}">
                                <div class="products-empty-state">
                                    <i class="bi bi-box-seam"></i>
                                    <strong>Noch keine Produkte vorhanden.</strong>
                                    <span>Aktuell sind keine Produkte vorhanden.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="products-pagination erp-list-pagination">{{ $products->links() }}</div>
    </section>


    @unless($isSales)
        <script src="{{ asset('js/products-bulk-actions.js') }}?v=1" defer></script>
    @endunless
</x-layouts.premium>