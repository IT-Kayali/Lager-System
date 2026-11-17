<x-layouts.premium title="Bestellung vorbereiten" subtitle="Lageransicht mit Positionen, Versand, Status und Lieferschein.">
    @if (session('success')) <div class="premium-alert">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="premium-alert" data-csp-style="s-b2a82328">{{ session('error') }}</div> @endif

    @php
        $customer = $offer->customer;
        $street = $customer?->delivery_street ?: $customer?->billing_street;
        $houseNumber = $customer?->delivery_house_number ?: $customer?->billing_house_number;
        $postalCode = $customer?->delivery_postal_code ?: $customer?->billing_postal_code;
        $city = $customer?->delivery_city ?: $customer?->billing_city ?: $customer?->city;
        $country = $customer?->delivery_country ?: $customer?->billing_country;
        $statusClass = match ($offer->status) {
            \App\Models\Offer::STATUS_IN_PROGRESS => 'progress',
            \App\Models\Offer::STATUS_READY => 'ready',
            \App\Models\Offer::STATUS_COMPLETED => 'completed',
            default => 'neutral',
        };

        $unifiedStatusClass = match ($offer->status) {
            \App\Models\Offer::STATUS_IN_PROGRESS => 'status-unified-progress',
            \App\Models\Offer::STATUS_READY => 'status-unified-ready',
            \App\Models\Offer::STATUS_COMPLETED => 'status-unified-completed',
            default => '',
        };
    @endphp

    <div class="warehouse-offer-layout">
        <div>
            <section class="premium-card" data-csp-style="s-5a64b1be">
                <div class="premium-toolbar">
                    <div>
                        <h2 data-csp-style="s-988eff47">{{ $offer->offer_number }}</h2>
                        <div class="premium-muted" data-csp-style="s-63ba6056"><span>{{ $customer?->company_name ?: '—' }}</span><span>·</span><span class="warehouse-status-badge {{ $statusClass }} {{ $unifiedStatusClass }}">{{ $offer->statusLabel() }}</span></div>
                    </div>
                    <div data-csp-style="s-09adedd2"><a href="{{ route('warehouse.offers.delivery-note', $offer) }}" target="_blank" class="premium-btn gold"><i class="bi bi-truck"></i> Lieferschein PDF</a><a href="{{ route('warehouse.offers.index') }}" class="premium-btn"><i class="bi bi-arrow-left"></i> Zurück</a></div>
                </div>

                @if ($nextStatuses)
                    <form method="POST" action="{{ route('warehouse.offers.status', $offer) }}" data-csp-style="s-e9f7b175">@csrf @method('PUT')
                        <div class="premium-form-grid"><div class="premium-form-field"><label for="status">Lagerstatus</label><select id="status" name="status" class="premium-select" required>@foreach ($nextStatuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div><div class="premium-form-field" data-csp-style="s-1e496597"><button class="premium-btn gold" type="submit"><i class="bi bi-arrow-repeat"></i> Status speichern</button></div></div>
                    </form>
                @else
                    <div class="premium-alert" data-csp-style="s-e9f7b175">Diese Bestellung ist erledigt. Der Lagerbestand wurde bereits per FIFO gebucht; deshalb ist ein Rücksprung aus „Erledigt“ gesperrt.</div>
                @endif
            </section>

            <div data-csp-style="s-98995f06">
                <section class="premium-card"><h3 data-csp-style="s-dae52bca">Empfänger</h3><div data-csp-style="s-af97d8bb"><strong>{{ $customer?->company_name ?: '—' }}</strong><span>{{ $customer?->customer_number ?: '—' }}</span>@if ($street || $houseNumber)<span>{{ trim(($street ?? '') . ' ' . ($houseNumber ?? '')) }}</span>@endif @if ($postalCode || $city)<span>{{ trim(($postalCode ?? '') . ' ' . ($city ?? '')) }}</span>@endif @if ($country)<span>{{ $country }}</span>@endif</div></section>
                <section class="premium-card"><h3 data-csp-style="s-dae52bca">Versand</h3><div data-csp-style="s-af97d8bb"><div><strong>Versandart:</strong> {{ $offer->shipping_method ?: '—' }}</div>@if ($offer->shipping_method === 'Lieferung')<div><strong>Anzahl Kartons:</strong> {{ $offer->carton_count ?: '—' }}</div>@endif</div></section>
                @if ($offer->notes)<section class="premium-card"><h3 data-csp-style="s-dae52bca">Hinweis</h3><div data-csp-style="s-25aefbb2">{{ $offer->notes }}</div></section>@endif
            </div>

            <section class="premium-card">
                <div class="premium-toolbar"><div><h2 data-csp-style="s-3eedf07e">Positionen vorbereiten</h2><p class="premium-muted" data-csp-style="s-3ca59c3a">Nur Produkt, Menge und Einheit – keine Preis- oder Rechnungsdaten.</p></div></div>
                <div class="premium-table-wrap"><table class="premium-table"><thead><tr><th>Produkt</th><th>Menge</th><th>Einheit</th></tr></thead><tbody>@foreach ($offer->items as $item)<tr><td><strong>{{ $item->product_name }}</strong><div class="premium-muted">{{ $item->product_code }}</div></td><td>{{ \App\Support\GermanNumber::format($item->quantity) }}</td><td>{{ $item->product?->unitLabel('de') ?? ($item->unit ?: '—') }}</td></tr>@endforeach</tbody></table></div>
            </section>
        </div>

        <aside class="premium-card warehouse-notes-sidebar" id="warehouse-internal-notes">
            <div class="warehouse-notes-head"><div><h3>Interne Notizen</h3><div class="premium-muted">Für alle ERP-Mitarbeiter sichtbar</div></div><span class="premium-badge">{{ $offer->internalNotes->count() }}</span></div>
            <form method="POST" action="{{ route('offer-notes.store', $offer) }}" class="warehouse-note-form">@csrf
                <label for="warehouse-internal-note">Notiz hinzufügen</label>
                <textarea id="warehouse-internal-note" name="note" rows="5" maxlength="5000" required placeholder="Interne Information eingeben …">{{ old('note') }}</textarea>
                @error('note') <div data-csp-style="s-a9fa6bb5">{{ $message }}</div> @enderror
                <div class="premium-muted" data-csp-style="s-81fa117f"><i class="bi bi-person"></i> {{ auth()->user()?->name }} · Datum & Uhrzeit automatisch</div>
                <button class="premium-btn gold" data-csp-style="s-082b5184" type="submit"><i class="bi bi-plus-lg"></i> Notiz hinzufügen</button>
            </form>
            <div class="warehouse-notes-feed">
                @forelse ($offer->internalNotes as $note)
                    <article class="warehouse-note-item"><div class="warehouse-note-avatar">{{ mb_strtoupper(mb_substr($note->user?->name ?? '?', 0, 1)) }}</div><div><strong>{{ $note->user?->name ?? 'Gelöschter Benutzer' }}</strong><div class="warehouse-note-time">{{ $note->created_at->format('d.m.Y') }} · {{ $note->created_at->format('H:i') }} Uhr</div><div class="warehouse-note-text">{{ $note->note }}</div></div></article>
                @empty
                    <div class="premium-muted" data-csp-style="s-446b8b3c">Noch keine internen Notizen.</div>
                @endforelse
            </div>
        </aside>
    </div>


</x-layouts.premium>
