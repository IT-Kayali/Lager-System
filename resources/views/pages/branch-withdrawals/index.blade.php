<x-layouts.premium title="Filialausgang" subtitle="Filialaufträge mit mehreren Produkten, Mengen und Ausgabestatus verwalten.">
    @if (session('success'))
        <div class="premium-alert success">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="premium-alert" data-csp-style="s-b2a82328">
            {{ session('error') }}
        </div>
    @endif

    @php
        $isManager = auth()->user()?->isManager();
        $isSales = auth()->user()?->isSales();
        $isWarehouse = auth()->user()?->isWarehouse();
        $canCreate = $isManager || $isSales || $isWarehouse;
        $createRoute = $isSales ? 'sales.branch-withdrawals.create' : 'branch-withdrawals.create';
        $indexRoute = $isSales ? 'sales.branch-withdrawals.index' : 'branch-withdrawals.index';
        $showRoute = $isSales ? 'sales.branch-withdrawals.show' : 'branch-withdrawals.show';
        $allStatusOptions = \App\Models\BranchWithdrawal::statusLabels();
        $statusOptions = ($isWarehouse && ! $isManager)
            ? collect($allStatusOptions)->only([
                \App\Models\BranchWithdrawal::STATUS_IN_PROGRESS,
                \App\Models\BranchWithdrawal::STATUS_ISSUED,
                \App\Models\BranchWithdrawal::STATUS_CANCELLED,
            ])->all()
            : $allStatusOptions;
        $hasBranchFilters = ! empty($search)
            || ($searchField ?? 'all') !== 'all'
            || ($exact ?? false)
            || ! empty($selectedStatus)
            || ! empty($selectedBranch);
    @endphp

    <section class="erp-list-toolbar">
        <div class="erp-list-filter-card">
            <form method="GET" action="{{ route($indexRoute) }}" class="erp-list-filter-form">
                <div class="erp-list-search">
                    <i class="bi bi-search"></i>
                    <input name="search" value="{{ $search ?? '' }}" class="premium-input" placeholder="Filialausgang suchen...">
                </div>

                <select name="search_field" class="premium-select erp-list-select" aria-label="Suchfeld auswählen">
                    <option value="all" @selected(($searchField ?? 'all') === 'all')>Alle</option>
                    <option value="number" @selected(($searchField ?? 'all') === 'number')>Filialausgang</option>
                    <option value="product" @selected(($searchField ?? 'all') === 'product')>Produkt</option>
                    <option value="employee" @selected(($searchField ?? 'all') === 'employee')>Mitarbeiter</option>
                    <option value="note" @selected(($searchField ?? 'all') === 'note')>Notiz</option>
                </select>

                <select name="branch" class="premium-select erp-list-select" aria-label="Filiale filtern">
                    <option value="">Alle Filialen</option>
                    @foreach (\App\Models\BranchWithdrawal::branches() as $branchValue => $branchLabel)
                        <option value="{{ $branchValue }}" @selected(($selectedBranch ?? '') === $branchValue)>{{ $branchLabel }}</option>
                    @endforeach
                </select>

                <select name="status" class="premium-select erp-list-select" aria-label="Status filtern">
                    <option value="">Alle Status</option>
                    @foreach ($statusOptions as $statusValue => $statusText)
                        <option value="{{ $statusValue }}" @selected(($selectedStatus ?? '') === $statusValue)>{{ $statusText }}</option>
                    @endforeach
                </select>

                <label class="erp-list-exact">
                    <input type="checkbox" name="exact" value="1" @checked($exact ?? false)>
                    <span>Exakter Wert</span>
                </label>

                <button class="premium-btn" type="submit"><i class="bi bi-search"></i> Suchen</button>

                @if ($hasBranchFilters)
                    <a href="{{ route($indexRoute) }}" class="premium-btn"><i class="bi bi-x-lg"></i> Zurücksetzen</a>
                @endif
            </form>
        </div>

        @if ($canCreate)
            <div class="erp-list-actions">
                <a href="{{ route($createRoute) }}" class="premium-btn gold">
                    <i class="bi bi-plus-lg"></i>
                    Filialausgang erstellen
                </a>
            </div>
        @endif
    </section>

    <section class="erp-list-card">
        <div class="premium-table-wrap erp-list-table-shell">
            <table class="premium-table branch-table">
                <thead>
                    <tr>
                        <th>Filialausgang</th>
                        <th>Filiale</th>
                        <th>Status</th>
                        <th>Mitarbeiter</th>
                        <th>Notiz</th>
                        <th>Lieferschein</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($withdrawals as $withdrawal)
                        @php
                            $statusLabel = \App\Models\BranchWithdrawal::statusLabels()[$withdrawal->status] ?? $withdrawal->status;
                            $statusClass = match ($withdrawal->status) {
                                \App\Models\BranchWithdrawal::STATUS_OPEN => 'open',
                                \App\Models\BranchWithdrawal::STATUS_IN_PROGRESS => 'progress',
                                \App\Models\BranchWithdrawal::STATUS_ISSUED => 'issued',
                                \App\Models\BranchWithdrawal::STATUS_CANCELLED => 'cancelled',
                                default => '',
                            };

                            $unifiedStatusClass = match ($withdrawal->status) {
                                \App\Models\BranchWithdrawal::STATUS_OPEN => 'status-unified-open',
                                \App\Models\BranchWithdrawal::STATUS_IN_PROGRESS => 'status-unified-progress',
                                \App\Models\BranchWithdrawal::STATUS_ISSUED => 'status-unified-issued',
                                \App\Models\BranchWithdrawal::STATUS_CANCELLED => 'status-unified-cancelled',
                                default => '',
                            };
                        @endphp

                        <tr>
                            <td>
                                <div class="branch-number-cell">
                                    <strong><a href="{{ route($showRoute, $withdrawal) }}">{{ $withdrawal->withdrawal_number }}</a></strong>
                                    <span>{{ $withdrawal->created_at?->format('d.m.Y H:i') }}</span>
                                </div>
                            </td>

                            <td><span class="branch-name-pill"><i class="bi bi-shop-window"></i>{{ $withdrawal->branch_name }}</span></td>

                            <td>
                                @if ($isWarehouse && ! $isManager && in_array($withdrawal->status, [\App\Models\BranchWithdrawal::STATUS_IN_PROGRESS, \App\Models\BranchWithdrawal::STATUS_ISSUED], true))
                                    <form method="POST" action="{{ route('branch-withdrawals.update', $withdrawal) }}" class="branch-status-form">
                                        @csrf
                                        @method('PUT')
                                        <select name="status" class="premium-select branch-status-select" aria-label="Status für {{ $withdrawal->withdrawal_number }} ändern">
                                            @foreach (\App\Models\BranchWithdrawal::statusLabels() as $statusValue => $statusText)
                                                <option value="{{ $statusValue }}" @selected($withdrawal->status === $statusValue)>{{ $statusText }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="premium-icon-btn" title="Status speichern" aria-label="Status speichern"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                @else
                                    <span class="branch-status-badge {{ $statusClass }} {{ $unifiedStatusClass }}">{{ $statusLabel }}</span>
                                @endif
                            </td>

                            <td><strong class="branch-employee-name">{{ $withdrawal->user?->name ?? 'System' }}</strong></td>
                            <td><span class="branch-note-text" title="{{ $withdrawal->note }}">{{ filled($withdrawal->note) ? Str::limit($withdrawal->note, 70) : '—' }}</span></td>

                            <td>
                                @if (! $isSales)
                                    <a href="{{ route('branch-withdrawals.delivery-note', $withdrawal) }}" class="premium-icon-btn" target="_blank" rel="noopener" title="Filial-Lieferschein öffnen" aria-label="Filial-Lieferschein öffnen">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                @else
                                    <span class="branch-role-note">—</span>
                                @endif
                            </td>

                            <td>
                                <div class="premium-actions branch-actions">
                                    <a href="{{ route($showRoute, $withdrawal) }}" class="premium-icon-btn" title="Filialausgang ansehen" aria-label="Filialausgang ansehen"><i class="bi bi-eye"></i></a>
                                    @if ($isManager)
                                        <a href="{{ route('branch-withdrawals.edit', $withdrawal) }}" class="premium-icon-btn" title="Bearbeiten" aria-label="Filialausgang bearbeiten"><i class="bi bi-pencil"></i></a>
                                        <form method="POST" action="{{ route('branch-withdrawals.destroy', $withdrawal) }}" data-confirm="Filialausgang wirklich löschen? Bereits ausgegebene Mengen werden automatisch zurückgebucht.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="premium-icon-btn premium-danger" title="Löschen" aria-label="Filialausgang löschen"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @elseif($isWarehouse)
                                        <span class="branch-role-note">{{ $withdrawal->status === \App\Models\BranchWithdrawal::STATUS_CANCELLED ? 'Nur Ansicht' : 'Status vor/zurück' }}</span>
                                    @elseif($isSales && $withdrawal->status === \App\Models\BranchWithdrawal::STATUS_OPEN)
                                        <a href="{{ route('sales.branch-withdrawals.edit', $withdrawal) }}" class="premium-icon-btn" title="Filialausgang bearbeiten" aria-label="Filialausgang bearbeiten"><i class="bi bi-pencil"></i></a>
                                    @else
                                        <span class="branch-role-note">An Lager übergeben</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="erp-list-empty">
                                    <i class="bi bi-shop"></i>
                                    <strong>Keine Filialausgänge gefunden.</strong>
                                    <span>{{ $canCreate ? 'Passen die Filter nicht, kannst du einen neuen Filialausgang erstellen.' : 'Für diese Filter sind keine Filialausgänge vorhanden.' }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="erp-list-pagination">{{ $withdrawals->links() }}</div>
    </section>


</x-layouts.premium>
