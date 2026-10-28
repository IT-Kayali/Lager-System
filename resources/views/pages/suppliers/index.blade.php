<x-layouts.premium title="Lieferanten" subtitle="Verwalte Lieferanten, Kontakte und Produktzuordnungen.">
    @if (session('success'))<div class="premium-alert">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="premium-alert" data-csp-style="s-0b92fcc8">{{ session('error') }}</div>@endif

    @php
        $hasSupplierFilters = ! empty($search)
            || ($searchField ?? 'all') !== 'all'
            || ($exact ?? false);
    @endphp

    <section class="erp-list-toolbar">
        <div class="erp-list-filter-card">
            <form method="GET" action="{{ route('suppliers.index') }}" class="erp-list-filter-form">
                <div class="erp-list-search">
                    <i class="bi bi-search"></i>
                    <input name="search" value="{{ $search ?? '' }}" class="premium-input" placeholder="Lieferant suchen...">
                </div>

                <select name="search_field" class="premium-select erp-list-select" aria-label="Suchfeld auswählen">
                    <option value="all" @selected(($searchField ?? 'all') === 'all')>Alle</option>
                    <option value="number" @selected(($searchField ?? 'all') === 'number')>Lieferantennummer</option>
                    <option value="name" @selected(($searchField ?? 'all') === 'name')>Lieferant</option>
                    <option value="contact" @selected(($searchField ?? 'all') === 'contact')>Ansprechpartner</option>
                    <option value="email" @selected(($searchField ?? 'all') === 'email')>E-Mail</option>
                    <option value="city" @selected(($searchField ?? 'all') === 'city')>Stadt</option>
                </select>

                <label class="erp-list-exact">
                    <input type="checkbox" name="exact" value="1" @checked($exact ?? false)>
                    <span>Exakter Wert</span>
                </label>

                <button class="premium-btn" type="submit"><i class="bi bi-search"></i> Suchen</button>

                @if ($hasSupplierFilters)
                    <a href="{{ route('suppliers.index') }}" class="premium-btn"><i class="bi bi-x-lg"></i> Zurücksetzen</a>
                @endif
            </form>
        </div>

        <div class="erp-list-actions">
            <a href="{{ route('suppliers.create') }}" class="premium-btn gold"><i class="bi bi-plus-lg"></i> Lieferant hinzufügen</a>
        </div>
    </section>

    <section class="erp-list-card">
        <div class="premium-table-wrap erp-list-table-shell">
            <table class="premium-table suppliers-table">
                <thead>
                    <tr>
                        <th>Nummer</th>
                        <th>Lieferant</th>
                        <th>Kontakt</th>
                        <th>Stadt</th>
                        <th>Produkte</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppliers as $supplier)
                        @php
                            $productCount = $supplier->products_count ?? (method_exists($supplier, 'products') ? $supplier->products()->count() : 0);
                        @endphp
                        <tr>
                            <td><a class="premium-code supplier-number-link" href="{{ route('suppliers.show', $supplier) }}" title="Vorschau">{{ $supplier->supplier_number }}</a></td>
                            <td>
                                <a class="supplier-name-link" href="{{ route('suppliers.show', $supplier) }}">{{ $supplier->company_name }}</a>
                                <div class="premium-muted supplier-contact-person">{{ $supplier->contact_person ?: 'Kein Ansprechpartner' }}</div>
                            </td>
                            <td>
                                <div class="supplier-contact-stack">
                                    @if ($supplier->email)
                                        <a href="mailto:{{ $supplier->email }}"><i class="bi bi-envelope"></i> {{ $supplier->email }}</a>
                                    @else
                                        <span class="premium-muted">Keine E-Mail</span>
                                    @endif
                                    @if ($supplier->whatsapp ?: $supplier->phone)
                                        <x-whatsapp-link :number="$supplier->whatsapp ?: $supplier->phone" :label="$supplier->whatsapp ?: $supplier->phone" :country-code="$supplier->phone_country_code" />
                                    @endif
                                </div>
                            </td>
                            <td>{{ $supplier->city ?: '—' }}</td>
                            <td><span class="supplier-products-pill"><i class="bi bi-box-seam"></i> {{ $productCount }}</span></td>
                            <td>
                                <div class="premium-actions suppliers-actions">
                                    <a class="premium-icon-btn" href="{{ route('suppliers.show', $supplier) }}" title="Vorschau"><i class="bi bi-eye"></i></a>
                                    <a class="premium-icon-btn" href="{{ route('suppliers.edit', $supplier) }}" title="Bearbeiten"><i class="bi bi-pencil"></i></a>
                                    <form method="POST" action="{{ route('suppliers.destroy', $supplier) }}" data-confirm="Lieferant wirklich löschen?">
                                        @csrf
                                        @method('DELETE')
                                        <button class="premium-icon-btn premium-danger" type="submit" title="Löschen"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="erp-list-empty">
                                    <i class="bi bi-truck"></i>
                                    <strong>Keine Lieferanten gefunden.</strong>
                                    <span>Passe die Filter an oder lege einen neuen Lieferanten an.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="erp-list-pagination">{{ $suppliers->links() }}</div>
    </section>


</x-layouts.premium>