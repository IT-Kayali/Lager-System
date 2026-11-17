<x-layouts.premium title="Chargen & FIFO" subtitle="Wareneingänge, Batchnummern und FIFO-Lagerlogik.">
    @if (session('success'))
        <div class="premium-alert">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="premium-alert" data-csp-style="s-0b92fcc8">
            {{ session('error') }}
        </div>
    @endif

    <section class="batches-page-actions erp-list-toolbar">
        <div class="batches-search-card erp-list-filter-card">
            <form method="GET" action="{{ route('batches.index') }}" class="batches-search-modern erp-list-filter-form">
                <div class="batches-search-field erp-list-search">
                    <i class="bi bi-search"></i>
                    <input name="search" value="{{ $search ?? '' }}" class="premium-input" placeholder="Charge, Produkt, Code oder Fake Name suchen...">
                </div>

                <div class="batches-search-filter">
                    <select name="search_field" class="premium-input batches-search-select erp-list-select" aria-label="Suchfeld auswählen">
                        <option value="all" @selected(($searchField ?? 'all') === 'all')>Alle</option>
                        <option value="batch" @selected(($searchField ?? 'all') === 'batch')>Batchnummer</option>
                        <option value="product" @selected(($searchField ?? 'all') === 'product')>Produkt</option>
                        <option value="code" @selected(($searchField ?? 'all') === 'code')>Code-Nummer</option>
                        <option value="manufacturer" @selected(($searchField ?? 'all') === 'manufacturer')>Fake Name</option>
                    </select>
                </div>

                <label class="batches-exact-search erp-list-exact">
                    <input type="checkbox" name="exact" value="1" @checked($exact ?? false)>
                    <span>Exakter Wert</span>
                </label>

                <button class="premium-btn" type="submit"><i class="bi bi-search"></i> Suchen</button>

                @if (! empty($search) || ($searchField ?? 'all') !== 'all' || ($exact ?? false))
                    <a href="{{ route('batches.index') }}" class="premium-btn"><i class="bi bi-x-lg"></i> Zurücksetzen</a>
                @endif
            </form>
        </div>
        <a href="{{ route('batches.excel.export') }}" class="premium-btn erp-list-actions"><i class="bi bi-download"></i> Excel exportieren</a>
        <a href="{{ route('batches.excel.import.form') }}" class="premium-btn erp-list-actions"><i class="bi bi-upload"></i> Excel importieren</a>
        <a href="{{ route('batches.create') }}" class="premium-btn gold batches-create-btn erp-list-actions"><i class="bi bi-plus-lg"></i> Charge hinzufügen</a>
    </section>

    <section class="batches-modern-card erp-list-card">
        <div class="premium-table-wrap batches-table-shell erp-list-table-shell">
            <table class="premium-table batches-table">
                <thead><tr><th>Batchnummer</th><th>Produkt</th><th>Menge</th><th>Ablaufdatum</th><th>Wareneingang</th><th>FIFO-Status</th><th>Aktionen</th></tr></thead>
                <tbody>
                    @forelse ($batches as $batch)
                        @php
                            $expiresAt = $batch->expires_at;
                            $isExpired = $expiresAt && $expiresAt->isPast();
                            $isExpiringSoon = $expiresAt && ! $isExpired && $expiresAt->lte(now()->addDays(30));
                            $quantity = (float) $batch->quantity;
                            $unitLabels = ['gram' => 'Gramm', 'liter' => 'Liter', 'piece' => 'Stück'];
                            $unitLabel = $unitLabels[$batch->product?->unit] ?? ($batch->product?->unit ?? '');
                        @endphp
                        <tr>
                            <td><span class="batch-number-pill"><i class="bi bi-upc-scan"></i>{{ $batch->batch_number }}</span></td>
                            <td><div class="batch-product-cell"><strong>{{ $batch->product?->name ?: '—' }}</strong><span>{{ $batch->product?->product_code ?: 'Kein Produktcode' }}</span></div></td>
                            <td><span class="batch-quantity {{ $quantity <= 0 ? 'empty' : '' }}">{{ number_format($quantity, 2, ',', '.') }}</span><span class="batch-unit">{{ $unitLabel }}</span></td>
                            <td>
                                @if ($expiresAt)
                                    <span class="batch-expiry {{ $isExpired ? 'expired' : ($isExpiringSoon ? 'soon' : 'regular') }}"><i class="bi {{ $isExpired ? 'bi-x-circle' : ($isExpiringSoon ? 'bi-exclamation-circle' : 'bi-calendar-check') }}"></i>{{ $expiresAt->format('d.m.Y') }}</span>
                                @else
                                    <span class="batch-muted">—</span>
                                @endif
                            </td>
                            <td><span class="batch-date">{{ $batch->received_at?->format('d.m.Y') ?: '—' }}</span></td>
                            <td><span class="fifo-status-pill"><i class="bi bi-layers"></i>Ältere zuerst</span></td>
                            <td><div class="premium-actions batches-actions"><a class="premium-icon-btn" href="{{ route('batches.edit', $batch) }}" title="Bearbeiten"><i class="bi bi-pencil-square"></i></a><form method="POST" action="{{ route('batches.destroy', $batch) }}" data-confirm="Charge wirklich löschen?">@csrf @method('DELETE')<button class="premium-icon-btn premium-danger" type="submit" title="Löschen"><i class="bi bi-trash"></i></button></form></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="batches-empty-state"><i class="bi bi-layers"></i><strong>Noch keine Chargen vorhanden.</strong><span>Lege eine neue Charge an, um FIFO-Bestand aufzubauen.</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="batches-pagination erp-list-pagination">{{ $batches->links() }}</div>
    </section>


</x-layouts.premium>
