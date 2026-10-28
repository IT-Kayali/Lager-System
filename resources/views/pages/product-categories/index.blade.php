<x-layouts.premium title="Produktkategorien" subtitle="Verwalte Produktgruppen, Prioritäten, Farben und Zuordnungen.">
    @if (session('success'))
        <div class="premium-alert">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="premium-alert" data-csp-style="s-0b92fcc8">
            {{ session('error') }}
        </div>
    @endif

    @php
        $hasCategoryFilters = ! empty($search)
            || ($searchField ?? 'all') !== 'all'
            || ($exact ?? false)
            || ! empty($selectedStatus);
    @endphp

    <section class="erp-list-toolbar">
        <div class="erp-list-filter-card">
            <form method="GET" action="{{ route('product-categories.index') }}" class="erp-list-filter-form">
                <div class="erp-list-search">
                    <i class="bi bi-search"></i>
                    <input name="search" value="{{ $search ?? '' }}" class="premium-input" placeholder="Kategorie suchen...">
                </div>

                <select name="search_field" class="premium-select erp-list-select" aria-label="Suchfeld auswählen">
                    <option value="all" @selected(($searchField ?? 'all') === 'all')>Alle</option>
                    <option value="name" @selected(($searchField ?? 'all') === 'name')>Kategorie</option>
                    <option value="description" @selected(($searchField ?? 'all') === 'description')>Beschreibung</option>
                </select>

                <select name="status" class="premium-select erp-list-select" aria-label="Status filtern">
                    <option value="">Alle Status</option>
                    <option value="active" @selected(($selectedStatus ?? '') === 'active')>Aktiv</option>
                    <option value="inactive" @selected(($selectedStatus ?? '') === 'inactive')>Inaktiv</option>
                </select>

                <label class="erp-list-exact">
                    <input type="checkbox" name="exact" value="1" @checked($exact ?? false)>
                    <span>Exakter Wert</span>
                </label>

                <button class="premium-btn" type="submit"><i class="bi bi-search"></i> Suchen</button>

                @if ($hasCategoryFilters)
                    <a href="{{ route('product-categories.index') }}" class="premium-btn"><i class="bi bi-x-lg"></i> Zurücksetzen</a>
                @endif
            </form>
        </div>

        <div class="erp-list-actions">
            <a href="{{ route('product-categories.create') }}" class="premium-btn gold"><i class="bi bi-plus-lg"></i> Neue Kategorie</a>
        </div>
    </section>

    <section class="erp-list-card">
        <div class="premium-table-wrap erp-list-table-shell">
            <table class="premium-table category-clean-table modern-category-table">
                <thead>
                    <tr>
                        <th>Kategorie</th>
                        <th>Priorität</th>
                        <th>Beschreibung</th>
                        <th>Produkte</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        @php
                            $productCount = $category->products_count ?? $category->products()->count();
                        @endphp

                        <tr>
                            <td>
                                <a class="category-name-link" href="{{ route('product-categories.show', $category) }}">
                                    <span class="category-color-dot" data-csp-color="{{ $category->color ?: '#d4af37' }}"></span>
                                    <span class="category-name-copy">
                                        <strong>{{ $category->name }}</strong>
                                        <small>{{ $category->color ?: '#d4af37' }}</small>
                                    </span>
                                </a>
                            </td>
                            <td><span class="category-priority-pill" title="Dokument-Reihenfolge">{{ $category->priority ?? '—' }}</span></td>
                            <td><span class="category-description">{{ $category->description ?: '—' }}</span></td>
                            <td><span class="category-product-count">{{ $productCount }}</span></td>
                            <td>
                                @if ($category->is_active)
                                    <span class="category-status-pill active"><i class="bi bi-check2-circle"></i> Aktiv</span>
                                @else
                                    <span class="category-status-pill inactive"><i class="bi bi-pause-circle"></i> Inaktiv</span>
                                @endif
                            </td>
                            <td>
                                <div class="premium-actions category-actions">
                                    <a class="premium-icon-btn" href="{{ route('product-categories.show', $category) }}" title="Vorschau"><i class="bi bi-eye"></i></a>
                                    <a class="premium-icon-btn" href="{{ route('product-categories.edit', $category) }}" title="Bearbeiten"><i class="bi bi-pencil-square"></i></a>
                                    <form method="POST" action="{{ route('product-categories.destroy', $category) }}" data-confirm="Kategorie wirklich löschen?">
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
                                    <i class="bi bi-tags"></i>
                                    <strong>Keine Kategorien gefunden.</strong>
                                    <span>Passe die Filter an oder lege eine neue Kategorie an.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="erp-list-pagination">{{ $categories->links() }}</div>
    </section>


</x-layouts.premium>