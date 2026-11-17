<x-layouts.premium title="Filialausgang {{ $withdrawal->withdrawal_number }}" subtitle="Filialausgang und Produktpositionen jederzeit einsehen.">
    @php
        $isSales = auth()->user()?->isSales();
        $isManager = auth()->user()?->isManager();
        $indexRoute = $isSales ? 'sales.branch-withdrawals.index' : 'branch-withdrawals.index';
        $editRoute = $isSales ? 'sales.branch-withdrawals.edit' : 'branch-withdrawals.edit';
        $canEdit = $isManager || ($isSales && $withdrawal->status === \App\Models\BranchWithdrawal::STATUS_OPEN);
        $statusLabel = \App\Models\BranchWithdrawal::statusLabels()[$withdrawal->status] ?? $withdrawal->status;
        $statusClass = match ($withdrawal->status) {
            \App\Models\BranchWithdrawal::STATUS_OPEN => 'open',
            \App\Models\BranchWithdrawal::STATUS_IN_PROGRESS => 'progress',
            \App\Models\BranchWithdrawal::STATUS_ISSUED => 'issued',
            \App\Models\BranchWithdrawal::STATUS_CANCELLED => 'cancelled',
            default => '',
        };
    @endphp

    <div class="branch-detail-actions">
        <a href="{{ route($indexRoute) }}" class="premium-btn"><i class="bi bi-arrow-left"></i> Zurück zur Liste</a>
        @if ($canEdit)
            <a href="{{ route($editRoute, $withdrawal) }}" class="premium-btn"><i class="bi bi-pencil"></i> Bearbeiten</a>
        @endif
        @unless ($isSales)
            <a href="{{ route('branch-withdrawals.delivery-note', $withdrawal) }}" class="premium-btn gold" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Filial-Lieferschein</a>
        @endunless
    </div>

    <section class="premium-card branch-detail-card">
        <div class="branch-detail-heading">
            <div>
                <h2>{{ $withdrawal->withdrawal_number }}</h2>
                <p class="premium-muted">Erstellt am {{ $withdrawal->created_at?->format('d.m.Y H:i') ?? '—' }} Uhr</p>
            </div>
            <span class="branch-status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
        </div>

        <dl class="branch-detail-meta">
            <div><dt>Filiale</dt><dd>{{ $withdrawal->branch_name ?: '—' }}</dd></div>
            <div><dt>Erstellt von</dt><dd>{{ $withdrawal->user?->name ?? 'System' }}</dd></div>
            <div><dt>Zuletzt bearbeitet</dt><dd>{{ $withdrawal->updated_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
            <div><dt>Ausgegeben von</dt><dd>{{ $withdrawal->processor?->name ?? '—' }}</dd></div>
            <div><dt>Ausgegeben am</dt><dd>{{ $withdrawal->processed_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
        </dl>
    </section>

    <section class="premium-card branch-detail-card">
        <h2>Produktpositionen</h2>
        <div class="premium-table-wrap">
            <table class="premium-table">
                <thead>
                    <tr>
                        <th>Pos.</th>
                        <th>Produkt</th>
                        <th>Menge</th>
                        <th>Einheit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($withdrawal->items as $item)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td><strong>{{ $item->product?->name ?? 'Produkt nicht mehr vorhanden' }}</strong></td>
                            <td>{{ \App\Support\GermanNumber::format($item->quantity) }}</td>
                            <td>{{ $item->product?->unitLabel('de') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Keine Produktpositionen vorhanden.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="premium-card branch-detail-card">
        <h2>Interne Filialnotiz</h2>
        @if (filled($withdrawal->note))
            <p class="branch-detail-note">{{ $withdrawal->note }}</p>
        @else
            <p class="premium-muted">Keine Notiz hinterlegt.</p>
        @endif
    </section>
</x-layouts.premium>
