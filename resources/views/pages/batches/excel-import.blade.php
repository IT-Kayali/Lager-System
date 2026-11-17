<x-layouts.premium title="Chargen importieren" subtitle="Chargen-Excel prüfen und Lagerbestände erst nach Bestätigung aktualisieren.">
    @if (session('error'))
        <div class="premium-alert" data-csp-style="s-0b92fcc8">{{ session('error') }}</div>
    @endif

    <section class="premium-card">
        <form method="POST" action="{{ route('batches.excel.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="premium-form-grid">
                <div class="premium-form-field full">
                    <label for="excel_file">Chargen-Excel-Datei (.xlsx / .xls)</label>
                    <input id="excel_file" name="excel_file" type="file" class="premium-input" accept=".xlsx,.xls" required>
                    @error('excel_file') <div class="premium-error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div data-csp-style="s-0bc4d184">
                <button class="premium-btn gold" type="submit"><i class="bi bi-upload"></i> Vorschau anzeigen</button>
                <a href="{{ route('batches.excel.export') }}" class="premium-btn"><i class="bi bi-download"></i> Chargenliste exportieren</a>
                <a href="{{ route('batches.index') }}" class="premium-btn"><i class="bi bi-arrow-left"></i> Zurück</a>
            </div>
        </form>
    </section>

    <section class="premium-card" data-csp-style="s-e9f7b175">
        <h2 data-csp-style="s-3ab75854">Excel-Aufbau</h2>
        <p class="premium-muted">
            Das sichtbare Blatt heißt <strong>Chargen</strong>. Der Export enthält sämtliche Chargen, nicht nur die aktuelle Suchseite.
            Eine ausgeblendete Chargen-ID und interne Exportmetadaten erlauben es, vorhandene Batchnummern sicher umzubenennen.
            Fehlt die ID, erfolgt die Zuordnung anhand der eindeutigen Batchnummer. Neue Chargen benötigen ein vorhandenes, aktives Produkt.
        </p>
        <pre data-csp-style="s-25aefbb2">batch_number | product | quantity | expires_at | received_at</pre>
        <p class="premium-muted">
            Mengen können z. B. als <strong>12,500</strong> oder <strong>12.500</strong> eingetragen werden (maximal drei Nachkommastellen).
            Datumswerte: TT.MM.JJJJ oder JJJJ-MM-TT. Bei einer neuen Charge wird ein leeres Ablaufdatum automatisch berechnet;
            bei vorhandenen Chargen bleiben leere Datumsfelder unverändert.
            <strong>Entfernte Excel-Zeilen löschen keine Chargen.</strong>
            Mengenänderungen werden als Lagerbewegungen gespeichert. Produktdaten, Preise, Reservierungen und historische Vorgänge werden nicht importiert.
        </p>
    </section>

    @if (isset($preview, $previewToken))
        @php
            $created = collect($preview['rows'])->where('action', 'create')->count();
            $updated = collect($preview['rows'])->where('action', 'update')->count();
            $unchanged = collect($preview['rows'])->where('action', 'unchanged')->count();
        @endphp
        <section class="premium-card">
            <h2>Importvorschau</h2>
            <p class="premium-muted">
                {{ $created }} neu, {{ $updated }} geändert, {{ $unchanged }} unverändert.
                Prüfe die Mengen sorgfältig. Bisher wurde nichts gespeichert.
            </p>
            <form method="POST" action="{{ route('batches.excel.import.confirm') }}">
                @csrf
                <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                <div class="premium-table-wrap">
                    <table class="premium-table">
                        <thead>
                            <tr><th>Übernehmen</th><th>Zeile</th><th>Batchnummer</th><th>Produkt</th><th>Alte Menge</th><th>Neue Menge</th><th>Differenz</th><th>Aktion</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($preview['rows'] as $row)
                                <tr>
                                    <td><input type="checkbox" name="selected_rows[]" value="{{ $row['line'] }}" @checked($row['action'] !== 'unchanged') aria-label="Charge {{ $row['batch_number'] }} übernehmen"></td>
                                    <td>{{ $row['line'] }}</td>
                                    <td>{{ $row['batch_number'] }}</td>
                                    <td>{{ $row['product_name'] }}</td>
                                    <td>{{ number_format($row['old_quantity'], 3, ',', '.') }}</td>
                                    <td>{{ number_format($row['quantity'], 3, ',', '.') }}</td>
                                    <td>{{ number_format($row['quantity_delta'], 3, ',', '.') }}</td>
                                    <td>{{ match ($row['action']) { 'create' => 'Neu anlegen', 'update' => 'Aktualisieren', default => 'Unverändert' } }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8">Die Datei enthält keine Chargenzeilen.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="premium-form-field full">
                    <label for="stock_confirmation">Bei Mengenänderungen zur Bestätigung <strong>BESTAND</strong> eingeben</label>
                    <input id="stock_confirmation" name="stock_confirmation" class="premium-input" autocomplete="off" placeholder="BESTAND">
                    <small>Nur erforderlich, wenn ausgewählte Zeilen den tatsächlichen Lagerbestand ändern.</small>
                </div>

                <div data-csp-style="s-0bc4d184">
                    <button class="premium-btn gold" type="submit">Ausgewählte Änderungen übernehmen</button>
                    <a href="{{ route('batches.excel.import.form') }}" class="premium-btn">Abbrechen</a>
                </div>
            </form>
        </section>
    @endif
</x-layouts.premium>
