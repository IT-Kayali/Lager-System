<x-layouts.premium title="Kategorievorschau" subtitle="Eigenschaften der Kategorie und alle enthaltenen Produkte.">
    @php
        $statusLabel = $category->is_active ? 'Aktiv' : 'Inaktiv';
        $statusClass = $category->is_active ? 'ok' : 'critical';
        $statusLabels = ['ok' => 'OK', 'low' => 'Niedrig', 'critical' => 'Kritisch'];
    @endphp

    <section class="category-preview-hero" data-csp-cat-color="{{ $category->color ?: '#d4af37' }}">
        <div>
            <div class="category-preview-kicker">Kategorie</div>
            <h1><span></span>{{ $category->name }}</h1>
            <p>{{ $category->description ?: 'Keine Beschreibung hinterlegt.' }}</p>
        </div>
        <span class="premium-badge {{ $statusClass }}">{{ $statusLabel }}</span>
    </section>

    <section class="category-preview-actions">
        <a class="premium-btn gold" href="{{ route('product-categories.edit', $category) }}"><i class="bi bi-pencil"></i> Bearbeiten</a>
        <a class="premium-btn" href="{{ route('product-categories.index') }}"><i class="bi bi-arrow-left"></i> Zurück</a>
    </section>

    <section class="preview-grid">
        <div class="premium-card">
            <h2 class="preview-title">Eigenschaften</h2>
            <div class="details-grid">
                <div><span>Name</span><strong>{{ $category->name }}</strong></div>
                <div><span>Status</span><strong>{{ $statusLabel }}</strong></div>
                <div><span>Produkte</span><strong>{{ $category->products->count() }}</strong></div>
                <div><span>Farbe</span><strong class="color-value"><i data-csp-color="{{ $category->color ?: '#d4af37' }}"></i>{{ $category->color ?: '#d4af37' }}</strong></div>
                <div><span>Erstellt</span><strong>{{ $category->created_at ? $category->created_at->format('d.m.Y H:i') : '—' }}</strong></div>
                <div><span>Aktualisiert</span><strong>{{ $category->updated_at ? $category->updated_at->format('d.m.Y H:i') : '—' }}</strong></div>
            </div>
        </div>
        <div class="premium-card">
            <h2 class="preview-title">Beschreibung</h2>
            <div class="description-box">{{ $category->description ?: 'Keine Beschreibung hinterlegt.' }}</div>
        </div>
    </section>

    <section class="premium-card">
        <div class="section-head">
            <div>
                <h2 class="preview-title">Enthaltene Produkte</h2>
                <p class="premium-muted" data-csp-style="s-3ca59c3a">Alle Produkte, die dieser Kategorie zugeordnet sind.</p>
            </div>
            <div class="category-products-actions">
                <div class="category-product-search">
                    <i class="bi bi-search"></i>
                    <input id="category-product-search" class="premium-input" type="search" placeholder="Produktbezeichnung suchen..." autocomplete="off" data-category-product-search-runtime>
                    <button id="category-product-search-clear" type="button" class="category-product-search-clear" title="Suche löschen" aria-label="Suche löschen"><i class="bi bi-x-lg"></i></button>
                </div>
                <a class="premium-btn gold" href="{{ route('products.create', ['category_id' => $category->id]) }}"><i class="bi bi-plus-lg"></i> Produkt hinzufügen</a>
            </div>
        </div>

        <div class="category-products-table-shell">
            <table class="category-products-table" id="category-products-table">
                <thead><tr><th>Produktbezeichnung</th><th>Fake Name</th><th>Code-Nummer</th><th>Lieferant</th><th>Verfügbare Menge</th><th>Status</th><th>Aktion</th></tr></thead>
                <tbody>
                    @forelse ($category->products as $product)
                        @php
                            $unitLabel = $product->unitLabel('de');
                            $supplierName = $product->supplierRecord?->company_name ?: $product->supplier ?: '—';
                        @endphp
                        <tr data-category-product-row>
                            <td><a class="product-link" href="{{ route('products.show', $product) }}">{{ $product->name }}</a></td>
                            <td>{{ $product->manufacturer_designation ?: '—' }}</td>
                            <td>{{ $product->serial_number ?: '—' }}</td>
                            <td>{{ $supplierName }}</td>
                            <td><strong>{{ number_format((float) $product->available_stock, 2, ',', '.') }}</strong><span class="unit-small">{{ $unitLabel }}</span></td>
                            <td><span class="premium-badge {{ $product->stock_status }}">{{ $statusLabels[$product->stock_status] ?? $product->stock_status }}</span></td>
                            <td><a class="premium-icon-btn" href="{{ route('products.show', $product) }}" title="Vorschau"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="premium-muted">Diese Kategorie enthält noch keine Produkte.</div></td></tr>
                    @endforelse
                    @if ($category->products->isNotEmpty())
                        <tr id="category-product-search-empty" hidden><td colspan="7"><div class="premium-muted">Kein passendes Produkt gefunden.</div></td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>


</x-layouts.premium>