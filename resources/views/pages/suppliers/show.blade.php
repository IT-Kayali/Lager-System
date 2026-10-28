<x-layouts.premium title="Lieferantenvorschau" subtitle="Stammdaten des Lieferanten und zugehörige Produkte.">
    @php
        $products = $products ?? collect();

        $statusLabels = [
            'ok' => 'OK',
            'low' => 'Niedrig',
            'critical' => 'Kritisch',
        ];
    @endphp

    <section class="supplier-preview-hero">
        <div>
            <div class="supplier-preview-kicker">Lieferant</div>
            <h1>{{ $supplier->company_name }}</h1>

            <div class="supplier-preview-meta">
                <span><i class="bi bi-upc-scan"></i> {{ $supplier->supplier_number }}</span>

                @if ($supplier->contact_person)
                    <span><i class="bi bi-person"></i> {{ $supplier->contact_person }}</span>
                @endif

                @if ($supplier->email)
                    <span><i class="bi bi-envelope"></i> {{ $supplier->email }}</span>
                @endif

                @if ($supplier->phone)
                    <span><i class="bi bi-telephone"></i> {{ $supplier->phone }}</span>
                @endif

                @if ($supplier->whatsapp ?: $supplier->phone)
                    <span>
                        <x-whatsapp-link :number="$supplier->whatsapp ?: $supplier->phone" label="WhatsApp" :country-code="$supplier->phone_country_code" />
                    </span>
                @endif
            </div>
        </div>

        <span class="premium-badge ok">
            {{ $products->count() }} Produkte
        </span>
    </section>

    <section class="supplier-preview-actions">
        <a class="premium-btn gold" href="{{ route('suppliers.edit', $supplier) }}">
            <i class="bi bi-pencil"></i>
            Bearbeiten
        </a>

        <a class="premium-btn" href="{{ route('suppliers.index') }}">
            <i class="bi bi-arrow-left"></i>
            Zurück
        </a>
    </section>

    <section class="preview-grid">
        <div class="premium-card">
            <h2 class="preview-title">Stammdaten</h2>

            <div class="details-grid">
                <div>
                    <span>Lieferantennummer</span>
                    <strong>{{ $supplier->supplier_number }}</strong>
                </div>

                <div>
                    <span>Firma</span>
                    <strong>{{ $supplier->company_name }}</strong>
                </div>

                <div>
                    <span>Ansprechpartner</span>
                    <strong>{{ $supplier->contact_person ?: '—' }}</strong>
                </div>

                <div>
                    <span>E-Mail</span>
                    <strong>{{ $supplier->email ?: '—' }}</strong>
                </div>

                <div>
                    <span>Telefon</span>
                    <strong>{{ $supplier->phone ?: '—' }}</strong>
                </div>

                <div>
                    <span>WhatsApp</span>
                    <strong>
                        @if ($supplier->whatsapp ?: $supplier->phone)
                            <x-whatsapp-link :number="$supplier->whatsapp ?: $supplier->phone" label="WhatsApp öffnen" :country-code="$supplier->phone_country_code" />
                        @else
                            —
                        @endif
                    </strong>
                </div>

                <div>
                    <span>Produkte</span>
                    <strong>{{ $products->count() }}</strong>
                </div>
            </div>
        </div>

        <div class="premium-card">
            <h2 class="preview-title">Adresse</h2>

            <div class="address-box">
                @if (method_exists($supplier, 'fullAddress') && $supplier->fullAddress())
                    {!! nl2br(e($supplier->fullAddress())) !!}
                @else
                    —
                @endif
            </div>
        </div>
    </section>

    <section class="premium-card">
        <div class="section-head">
            <div>
                <h2 class="preview-title">Zugehörige Produkte</h2>
                <p class="premium-muted" data-csp-style="s-3ca59c3a">
                    Alle Produkte, die diesem Lieferanten zugeordnet sind.
                </p>
            </div>

            <a class="premium-btn gold" href="{{ route('products.create') }}">
                <i class="bi bi-plus-lg"></i>
                Produkt hinzufügen
            </a>
        </div>

        <div class="premium-table-wrap">
            <table class="premium-table supplier-products-table">
                <thead>
                    <tr>
                        <th>Produktbezeichnung</th>
                        <th>Fake Name</th>
                        <th>Code-Nummer</th>
                        <th>Verfügbare Menge</th>
                        <th>Status</th>
                        <th>Aktion</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($products as $product)
                        @php
                            $unitLabel = $product->unitLabel('de');
                        @endphp

                        <tr>
                            <td>
                                <a class="product-link" href="{{ route('products.show', ['product' => $product]) }}">
                                    {{ $product->name }}
                                </a>
                            </td>

                            <td>{{ $product->manufacturer_designation ?: '—' }}</td>
                            <td>{{ $product->serial_number ?: '—' }}</td>

                            <td>
                                <strong>{{ number_format((float) $product->available_stock, 2, ',', '.') }}</strong>
                                <span class="unit-small">{{ $unitLabel }}</span>
                            </td>

                            <td>
                                <span class="premium-badge {{ $product->stock_status }}">
                                    {{ $statusLabels[$product->stock_status] ?? $product->stock_status }}
                                </span>
                            </td>

                            <td>
                                <a class="premium-icon-btn" href="{{ route('products.show', ['product' => $product]) }}" title="Produkt öffnen">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="premium-muted">Diesem Lieferanten sind noch keine Produkte zugeordnet.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>


</x-layouts.premium>