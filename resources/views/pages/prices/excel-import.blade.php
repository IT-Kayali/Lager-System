<x-layouts.premium title="Preis-Excel" subtitle="Kundengruppenpreise per Excel importieren – mit Vorschau vor jeder Änderung.">
    @if (session('success'))
        <div class="premium-alert">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="premium-alert">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="premium-alert">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <section class="premium-card erp-list-card">
        <h3>Preisdatei hochladen</h3>
        <p class="premium-muted">Lade zuerst auf der Seite <a href="{{ route('prices.index') }}">Preise</a> eine aktuelle Vorlage für eine Kundengruppe oder alle Gruppen herunter. Trage nur in der Spalte <strong>price</strong> die gewünschten Werte ein.</p>
        <p class="premium-muted">Leere Preiszellen werden ignoriert, 0 ist ein gültiger Preis. Bestehende Mengenregeln, nicht enthaltene Zeilen und andere Kundengruppen bleiben unverändert.</p>

        <form method="POST" action="{{ route('prices.excel.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="premium-toolbar erp-list-filter-form">
                <label for="price-import-group">Kundengruppe</label>
                <select name="customer_group_id" id="price-import-group" class="premium-select erp-list-select" required>
                    <option value="all" @selected(old('customer_group_id', $preview['scope'] ?? 'all') === 'all')>Alle Kundengruppen</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected((string) old('customer_group_id', $preview['scope'] ?? '') === (string) $group->id)>{{ $group->name }}</option>
                    @endforeach
                </select>
                <input type="file" name="excel_file" accept=".xlsx,.xls" class="premium-input" required>
                <button class="premium-btn gold" type="submit"><i class="bi bi-search"></i> Vorschau erstellen</button>
            </div>
        </form>
    </section>

    @isset($preview)
        <section class="premium-card erp-list-card">
            <h3>Importvorschau – {{ count($preview['rows']) }} geänderte Preise</h3>
            <p class="premium-muted">Es wurde noch nichts gespeichert. Markiere die Zeilen, die übernommen werden sollen. Die Vorschau gilt 30 Minuten.</p>

            @if (count($preview['rows']) === 0)
                <div class="premium-placeholder">Keine Änderungen gefunden. Bitte die Spalte „price“ ausfüllen und erneut hochladen.</div>
            @else
                <form method="POST" action="{{ route('prices.excel.import.confirm') }}">
                    @csrf
                    <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                    <div class="premium-table-wrap">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Übernehmen</th>
                                    <th>Excel-Zeile</th>
                                    <th>Produkt</th>
                                    <th>Kundengruppe</th>
                                    <th>Preisbereich</th>
                                    <th>Vorher</th>
                                    <th>Neu</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['rows'] as $row)
                                    <tr>
                                        <td><input type="checkbox" name="selected_rows[]" value="{{ $row['line'] }}" checked aria-label="Zeile {{ $row['line'] }} übernehmen"></td>
                                        <td>{{ $row['line'] }}</td>
                                        <td>{{ $row['product'] }}</td>
                                        <td>{{ $row['group'] }}</td>
                                        <td>{{ $row['label'] }} ({{ $row['mode'] }})</td>
                                        <td>{{ \App\Support\GermanNumber::format($row['old_price']) }} €</td>
                                        <td><strong>{{ \App\Support\GermanNumber::format($row['new_price']) }} €</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="premium-btn gold"><i class="bi bi-check-circle"></i> Ausgewählte Preise übernehmen</button>
                </form>
            @endif
        </section>
    @endisset
</x-layouts.premium>
