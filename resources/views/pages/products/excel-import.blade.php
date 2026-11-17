<x-layouts.premium title="Produkte importieren" subtitle="Produktdaten prüfen und nach Bestätigung aktualisieren oder synchronisieren.">
    @if (session('success'))
        <div class="premium-alert">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="premium-alert" data-csp-style="s-0b92fcc8">
            {{ session('error') }}
        </div>
    @endif

    @if (session('import_errors'))
        <div class="premium-alert" data-csp-style="s-0b92fcc8">
            <strong>Import-Fehler:</strong>
            <ul data-csp-style="s-1b00d22c">
                @foreach (session('import_errors') as $importError)
                    <li>{{ $importError }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="premium-card">
        <form method="POST" action="{{ route('products.excel.import') }}" enctype="multipart/form-data">
            @csrf

            <div class="premium-form-grid">
                <div class="premium-form-field full">
                    <label for="excel_file">Excel-Datei (.xlsx)</label>
                    <input
                        id="excel_file"
                        name="excel_file"
                        type="file"
                        class="premium-input"
                        accept=".xlsx,.xls"
                        required
                    >
                    @error('excel_file') <div class="premium-error">{{ $message }}</div> @enderror
                </div>
                <div class="premium-form-field full">
                    <label for="import_mode">Importart</label>
                    <select id="import_mode" name="import_mode" class="premium-select" required>
                        <option value="update" @selected(old('import_mode', 'update') === 'update')>Aktualisieren – fehlende Produkte behalten</option>
                        <option value="sync" @selected(old('import_mode') === 'sync')>Synchronisieren – entfernte Zeilen nach Vorschau archivieren</option>
                    </select>
                    <small>Synchronisieren ist nur mit einem vollständigen Export aus dem neuen Produktbereich möglich.</small>
                </div>
            </div>

            <div data-csp-style="s-0bc4d184">
                <button class="premium-btn gold" type="submit">
                    <i class="bi bi-upload"></i>
                    Importvorschau anzeigen
                </button>

                <a href="{{ route('products.index') }}" class="premium-btn">
                    <i class="bi bi-arrow-left"></i>
                    Zurück
                </a>

                <a href="{{ route('products.excel.export') }}" class="premium-btn">
                    <i class="bi bi-download"></i>
                    Vorlage exportieren
                </a>
            </div>
        </form>
    </section>

    <section class="premium-card" data-csp-style="s-e9f7b175">
        <h2 data-csp-style="s-3ab75854">Excel-Aufbau</h2>

        <p class="premium-muted">
            Die Datei enthält das sichtbare Tabellenblatt <strong>Produkte</strong>.
            Für das Synchronisieren zuerst den vollständigen Produktbestand exportieren und diese Datei bearbeiten.
            Beim normalen Aktualisieren werden keine Produkte archiviert.
        </p>

        <h3>Blatt Produkte</h3>
        <pre data-csp-style="s-25aefbb2">name | manufacturer_designation | serial_number | unit | supplier | minimum_stock | description</pre>

        <p class="premium-muted">
            Erlaubte Werte für <strong>unit</strong>: gram, liter, piece.
            Eine ausgeblendete interne ID ermöglicht das Umbenennen vorhandener Produkte.
            Ohne ID erfolgt die Zuordnung anhand des eindeutigen Produktnamens.
            Leere Zellen überschreiben bestehende Werte nicht; Mindestbestand 0 wird übernommen.
            Ältere Dateien mit product_code werden beim Aktualisieren weiterhin unterstützt.
            Ein zusätzliches Blatt Preisstaffeln wird ignoriert. Preise und Lagerbewegungen werden nicht importiert oder gelöscht.
        </p>
    </section>

    @if (isset($preview, $previewToken))
        @php
            $createCount = collect($preview['rows'])->where('action', 'create')->count();
            $updateCount = collect($preview['rows'])->where('action', 'update')->count();
            $restoreCount = collect($preview['rows'])->where('action', 'restore')->count();
            $archiveCount = count($preview['archives']);
        @endphp

        <section class="premium-card">
            <h2>Importvorschau</h2>
            <p class="premium-muted">
                {{ $createCount }} neu, {{ $updateCount }} vorhanden, {{ $restoreCount }} wiederherzustellen,
                {{ $archiveCount }} zum Archivieren vorgesehen.
                Du kannst jede Zeile abwählen. Es wurde noch nichts gespeichert.
            </p>

            @if ($preview['ignored_prices'])
                <div class="premium-alert">Das alte Blatt „Preisstaffeln“ wird ignoriert. Preise bleiben unverändert.</div>
            @endif

            <form method="POST" action="{{ route('products.excel.import.confirm') }}">
                @csrf
                <input type="hidden" name="preview_token" value="{{ $previewToken }}">

                <div class="products-table-shell">
                    <table class="products-clean-table">
                        <thead><tr><th>Übernehmen</th><th>Excel-Zeile</th><th>Produktname</th><th>Aktion</th></tr></thead>
                        <tbody>
                            @forelse ($preview['rows'] as $row)
                                <tr>
                                    <td><input type="checkbox" name="selected_rows[]" value="{{ $row['line'] }}" checked aria-label="{{ $row['name'] }} übernehmen"></td>
                                    <td>{{ $row['line'] }}</td>
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ match ($row['action']) { 'create' => 'Neu anlegen', 'restore' => 'Wiederherstellen', default => 'Aktualisieren' } }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4">Keine Produktzeilen ausgewertet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($preview['mode'] === 'sync')
                    <h3>In Excel entfernte Produkte (Archivierung)</h3>
                    <p class="premium-muted">Nur Produkte, die im ursprünglichen Export enthalten waren und jetzt fehlen, werden vorgeschlagen. Nach dem Export neu erstellte Produkte bleiben erhalten.</p>

                    @if ($preview['archives'] !== [])
                        <div class="products-table-shell">
                            <table class="products-clean-table">
                                <thead><tr><th>Archivieren</th><th>Produktname</th></tr></thead>
                                <tbody>
                                    @foreach ($preview['archives'] as $row)
                                        <tr>
                                            <td><input type="checkbox" name="archive_ids[]" value="{{ $row['id'] }}" checked aria-label="{{ $row['name'] }} archivieren"></td>
                                            <td>{{ $row['name'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="premium-form-field">
                            <label for="archive_confirmation">Zur Bestätigung der Archivierung ARCHIVIEREN eingeben</label>
                            <input class="premium-input" id="archive_confirmation" name="archive_confirmation" autocomplete="off" placeholder="ARCHIVIEREN">
                        </div>
                    @else
                        <p class="premium-muted">Keine zu archivierenden Produkte.</p>
                    @endif
                @endif

                <div data-csp-style="s-0bc4d184">
                    <button class="premium-btn gold" type="submit"><i class="bi bi-check-lg"></i> Auswahl bestätigen und importieren</button>
                    <a href="{{ route('products.excel.import.form') }}" class="premium-btn">Abbrechen</a>
                </div>
            </form>
        </section>
    @endif
</x-layouts.premium>
