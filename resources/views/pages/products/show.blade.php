<x-layouts.premium title="Produktvorschau" subtitle="Alle wichtigen Informationen zum Produkt auf einen Blick.">
    @php
        $isSales = auth()->user()?->isSales();
        $productIndexRoute = $isSales ? 'sales.products.index' : 'products.index';
        $branchCreateRoute = $isSales ? 'sales.branch-withdrawals.create' : 'branch-withdrawals.create';
        $canCreateBranchWithdrawal = $isSales
            || (auth()->user()?->isManager() ?? false)
            || (auth()->user()?->isWarehouse() ?? false);
        $statusLabels = ['ok' => 'OK', 'low' => 'Niedrig', 'critical' => 'Kritisch'];
        $unitLabel = $product->unitLabel('de');
        $supplierName = $product->supplierRecord?->company_name ?: $product->supplier ?: '—';
    @endphp

    <section class="product-preview-hero">
        <div>
            <div class="product-preview-kicker">Produkt</div>
            <h1>{{ $product->name }}</h1>
            <div class="product-preview-meta">
                <span><i class="bi bi-upc-scan"></i> Code: {{ $product->serial_number ?: '—' }}</span>
                <span><i class="bi bi-rulers"></i> Einheit: {{ $unitLabel }}</span>
                <span><i class="bi bi-truck"></i> Lieferant: {{ $supplierName }}</span>
            </div>
        </div>

        <div class="product-preview-status">
            <span class="premium-badge {{ $product->stock_status }}">{{ $statusLabels[$product->stock_status] ?? $product->stock_status }}</span>
        </div>
    </section>

    <section class="product-preview-actions">
        @unless($isSales)
            <a class="premium-btn gold" href="{{ route('batches.create', ['produkt' => $product->name]) }}">
                <i class="bi bi-grid"></i>
                Bestand buchen
            </a>
        @endunless

        @if ($canCreateBranchWithdrawal)
            <a class="premium-btn" href="{{ route($branchCreateRoute, ['produkt' => $product->name]) }}">
                <i class="bi bi-shop"></i>
                Filialausgang
            </a>
        @endif

        @unless($isSales)
            <a class="premium-btn" href="{{ route('products.edit', $product) }}">
                <i class="bi bi-pencil"></i>
                Bearbeiten
            </a>
        @endunless

        <a class="premium-btn" href="{{ route($productIndexRoute) }}">
            <i class="bi bi-arrow-left"></i>
            Zurück
        </a>
    </section>

    <section class="preview-grid">
        <div class="premium-card">
            <h2 class="preview-title">Bestand</h2>
            <div class="stock-preview-grid">
                <div class="stock-preview-box"><span>Gesamt</span><strong>{{ \App\Support\GermanNumber::format($product->total_stock) }}</strong></div>
                <div class="stock-preview-box"><span>Reserviert</span><strong>{{ \App\Support\GermanNumber::format($product->reserved_stock) }}</strong></div>
                <div class="stock-preview-box highlight"><span>Verfügbar</span><strong>{{ \App\Support\GermanNumber::format($product->available_stock) }}</strong></div>
                <div class="stock-preview-box"><span>Mindestbestand</span><strong>{{ \App\Support\GermanNumber::format($product->minimum_stock) }}</strong></div>
            </div>
        </div>

        <div class="premium-card">
            <h2 class="preview-title">Kategorien</h2>
            <div class="category-preview-list">
                @forelse ($product->categories as $category)
                    <span class="category-preview-badge" data-csp-cat-color="{{ $category->color ?: '#d4af37' }}">
                        <span></span>{{ $category->name }}
                    </span>
                @empty
                    <div class="premium-muted">Keine Kategorien zugeordnet.</div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="premium-card">
        <h2 class="preview-title">Produktdaten</h2>
        <div class="details-grid">
            <div><span>Bezeichnung</span><strong>{{ $product->name }}</strong></div>
            <div><span>Fake Name</span><strong>{{ $product->manufacturer_designation ?: '—' }}</strong></div>
            <div><span>Code-Nummer</span><strong>{{ $product->serial_number ?: '—' }}</strong></div>
            <div><span>Einheit</span><strong>{{ $unitLabel }}</strong></div>
            <div><span>Lieferant</span><strong>{{ $supplierName }}</strong></div>
        </div>

        @if (! empty($product->description))
            <div class="product-description-box">
                <span>Beschreibung</span>
                <p>{{ $product->description }}</p>
            </div>
        @endif
    </section>

    <section class="premium-card">
        <div class="section-head">
            <div>
                <h2 class="preview-title">Chargen</h2>
                <p class="premium-muted" data-csp-style="s-3ca59c3a">Wareneingang, Ablaufdatum und aktueller Chargenbestand.</p>
            </div>

            @unless($isSales)
                <a class="premium-btn gold" href="{{ route('batches.create', ['produkt' => $product->name]) }}">
                    <i class="bi bi-plus-lg"></i>
                    Charge hinzufügen
                </a>
            @endunless
        </div>

        <div class="batch-preview-list">
            @forelse ($product->batches as $batch)
                <article class="batch-preview-card">
                    <div><strong>{{ $batch->batch_number }}</strong><span>{{ $batch->received_at ? $batch->received_at->format('d.m.Y') : '—' }}</span></div>
                    <div><span>Menge</span><strong>{{ \App\Support\GermanNumber::format($batch->quantity) }} {{ $unitLabel }}</strong></div>
                    <div><span>Ablaufdatum</span><strong>{{ $batch->expires_at ? $batch->expires_at->format('d.m.Y') : '—' }}</strong></div>

                    @unless($isSales)
                        <div class="batch-preview-actions">
                            <a class="premium-icon-btn" href="{{ route('batches.edit', $batch) }}" title="Charge bearbeiten"><i class="bi bi-pencil"></i></a>
                        </div>
                    @endunless
                </article>
            @empty
                <div class="product-empty">
                    <i class="bi bi-box-seam"></i>
                    <strong>Noch keine Chargen vorhanden.</strong>
                    <span>Für dieses Produkt sind noch keine Chargen vorhanden.</span>
                </div>
            @endforelse
        </div>
    </section>


</x-layouts.premium>