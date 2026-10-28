<x-layouts.premium title="Kunden" subtitle="Kundenverwaltung mit individuell verwaltbaren Kundengruppen.">
    @if (session('success'))
        <div class="premium-alert">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="premium-alert" data-csp-style="s-0b92fcc8">
            {{ session('error') }}
        </div>
    @endif

    @php
        $crmCustomerCare = auth()->user()?->isCrm() ?? false;
        $hasCustomerFilters = ! empty($search)
            || ($searchField ?? 'all') !== 'all'
            || ($exact ?? false)
            || ! empty($selectedGroup);
    @endphp

    <section class="erp-list-toolbar">
        <div class="erp-list-filter-card">
            <form method="GET" action="{{ route('customers.index') }}" class="erp-list-filter-form">
                <div class="erp-list-search">
                    <i class="bi bi-search"></i>
                    <input name="search" value="{{ $search ?? '' }}" class="premium-input" placeholder="Kunde suchen...">
                </div>

                <select name="search_field" class="premium-select erp-list-select" aria-label="Suchfeld auswählen">
                    <option value="all" @selected(($searchField ?? 'all') === 'all')>Alle</option>
                    <option value="number" @selected(($searchField ?? 'all') === 'number')>Kundennummer</option>
                    <option value="name" @selected(($searchField ?? 'all') === 'name')>Kunde</option>
                    <option value="email" @selected(($searchField ?? 'all') === 'email')>E-Mail</option>
                    <option value="phone" @selected(($searchField ?? 'all') === 'phone')>Telefon</option>
                    <option value="city" @selected(($searchField ?? 'all') === 'city')>Stadt</option>
                    <option value="vat" @selected(($searchField ?? 'all') === 'vat')>USt-Nummer</option>
                </select>

                <select name="group" class="premium-select erp-list-select" aria-label="Kundengruppe filtern">
                    <option value="">Alle Gruppen</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->slug }}" @selected(($selectedGroup ?? '') === $group->slug)>{{ $group->name }}</option>
                    @endforeach
                </select>

                <label class="erp-list-exact">
                    <input type="checkbox" name="exact" value="1" @checked($exact ?? false)>
                    <span>Exakter Wert</span>
                </label>

                <button class="premium-btn" type="submit"><i class="bi bi-search"></i> Suchen</button>

                @if ($hasCustomerFilters)
                    <a href="{{ route('customers.index') }}" class="premium-btn"><i class="bi bi-x-lg"></i> Zurücksetzen</a>
                @endif
            </form>
        </div>

        <div class="erp-list-actions">
            <a href="{{ route('customers.create') }}" class="premium-btn gold"><i class="bi bi-plus-lg"></i> Kunde hinzufügen</a>
        </div>
    </section>

    <section class="premium-card erp-list-card">
        <div class="premium-table-wrap erp-list-table-shell">
            <table class="premium-table">
                <thead>
                    <tr>
                        <th>Kundennummer</th>
                        <th>Kunde</th>
                        <th>Gruppe</th>
                        <th>Kontakt</th>
                        <th>Stadt</th>
                        <th>USt-Nummer</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td><span class="premium-code">{{ $customer->customer_number }}</span></td>
                            <td>
                                @unless ($crmCustomerCare)
                                    <a class="customer-name-link" href="{{ route('customers.show', $customer) }}"><strong>{{ $customer->company_name }}</strong></a>
                                @else
                                    <strong>{{ $customer->company_name }}</strong>
                                @endunless
                                @if ($customer->notes)
                                    <div class="premium-muted">{{ \Illuminate\Support\Str::limit($customer->notes, 60) }}</div>
                                @endif
                            </td>
                            <td><x-customer-group-badge :group="$customer->group" /></td>
                            <td>
                                <div class="customer-contact-stack">
                                    @if ($customer->email)
                                        <a href="mailto:{{ $customer->email }}"><i class="bi bi-envelope"></i> {{ $customer->email }}</a>
                                    @else
                                        <span class="premium-muted">Keine E-Mail</span>
                                    @endif
                                    @if ($customer->phone)
                                        <x-whatsapp-link :number="$customer->phone" :label="$customer->phone" :country-code="$customer->phone_country_code" />
                                    @endif
                                </div>
                            </td>
                            <td>{{ $customer->billing_city ?: $customer->delivery_city ?: $customer->city ?: '—' }}</td>
                            <td>{{ $customer->vat_number ?: '—' }}</td>
                            <td>
                                <div class="premium-actions">
                                    @unless ($crmCustomerCare)
                                        <a class="premium-icon-btn" href="{{ route('customers.show', $customer) }}" title="Vorschau"><i class="bi bi-eye"></i></a>
                                    @endunless

                                    <a class="premium-icon-btn" href="{{ route('customers.edit', $customer) }}" title="Bearbeiten"><i class="bi bi-pencil"></i></a>

                                    @unless ($crmCustomerCare)
                                        <form method="POST" action="{{ route('customers.destroy', $customer) }}" data-confirm="Kunde wirklich löschen?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="premium-icon-btn premium-danger" type="submit" title="Löschen"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="erp-list-empty">
                                    <i class="bi bi-people"></i>
                                    <strong>Keine Kunden gefunden.</strong>
                                    <span>Passe die Filter an oder lege einen neuen Kunden an.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="erp-list-pagination">{{ $customers->links() }}</div>
    </section>


</x-layouts.premium>