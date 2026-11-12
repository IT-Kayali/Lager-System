<x-layouts.premium title="Produkte importieren" subtitle="Importiere Produkte und Preisstaffeln aus einer Excel-Datei.">
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
            </div>

            <div data-csp-style="s-0bc4d184">
                <button class="premium-btn gold" type="submit">
                    <i class="bi bi-upload"></i>
                    Excel importieren
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
            Die Datei soll zwei Tabellenblätter enthalten:
            <strong>Produkte</strong> und <strong>Preisstaffeln</strong>.
            Am einfachsten exportierst du zuerst eine Vorlage und bearbeitest diese Datei.
        </p>

        <h3>Blatt Produkte</h3>
        <pre data-csp-style="s-25aefbb2">name | manufacturer_designation | serial_number | unit | supplier | minimum_stock | description</pre>

        <h3>Blatt Preisstaffeln</h3>
        <pre data-csp-style="s-25aefbb2">product_code | customer_group | tier_key | tier_label | min_grams | max_grams | price</pre>

        <p class="premium-muted">
            Erlaubte Werte für <strong>unit</strong>: gram, liter, piece.
            Produkte werden ohne product_code anhand des eindeutigen Namens erkannt. Bisherige Dateien mit product_code bleiben beim Import unterstützt.
            Beim Umbenennen bestehender Produkte muss die bisherige Zuordnung erhalten bleiben (z. B. durch Import mit product_code).
            Im Blatt Preisstaffeln bleibt product_code für die eindeutige Preiszuordnung erforderlich.
            Produkte und Preise werden aktualisiert oder angelegt. Es wird nichts automatisch gelöscht.
        </p>
    </section>
</x-layouts.premium>