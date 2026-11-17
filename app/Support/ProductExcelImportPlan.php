<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Plant den Produktimport ohne Schreibzugriff. Der Abgleich wird erst nach
 * ausdrücklicher Freigabe und erneuter Prüfung des Datenbankstandes angewandt.
 */
final class ProductExcelImportPlan
{
    public function prepare(Spreadsheet $spreadsheet, string $mode): array
    {
        $sheet = $spreadsheet->getSheetByName('Produkte');

        if ($sheet === null) {
            $this->fail('Das Tabellenblatt Produkte fehlt.');
        }

        [$headers, $inputRows] = $this->readProducts($sheet);

        if (! in_array('name', $headers, true)) {
            $this->fail('Im Blatt Produkte fehlt die Spalte name.');
        }

        if (count($inputRows) > 5000) {
            $this->fail('Maximal 5.000 Produktzeilen pro Import sind zulässig.');
        }

        $baseline = $this->readBaseline($spreadsheet);

        if ($mode === 'sync' && ($baseline === null || $baseline === [])) {
            $this->fail('Synchronisieren ist nur mit einem vollständigen, unveränderten Produkt-Export aus dem neuen System möglich. Bitte zuerst neu exportieren.');
        }

        if ($mode === 'sync' && $inputRows === []) {
            $this->fail('Eine leere Excel-Liste kann nicht synchronisiert werden.');
        }

        $products = Product::query()->get()->keyBy('id');
        $names = $products->groupBy(fn (Product $product) => $this->nameKey($product->name));
        $codes = $products->keyBy('product_code');
        $suppliers = Supplier::query()->get(['id', 'company_name'])
            ->groupBy(fn (Supplier $supplier) => $this->nameKey($supplier->company_name));
        $seenNames = [];
        $seenTargets = [];
        $rows = [];
        $errors = [];

        foreach ($inputRows as $entry) {
            $line = $entry['line'];
            $source = $entry['data'];
            $name = trim((string) ($source['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = "Zeile {$line}: Ein Produktname mit maximal 255 Zeichen ist erforderlich.";
                continue;
            }

            $nameKey = $this->nameKey($name);

            if (isset($seenNames[$nameKey])) {
                $errors[] = "Zeile {$line}: Der Produktname steht mehrfach in der Excel-Datei.";
                continue;
            }
            $seenNames[$nameKey] = true;

            $idValue = trim((string) ($source['_internal_id'] ?? ''));
            $code = trim((string) ($source['product_code'] ?? ''));
            $product = null;

            if ($idValue !== '') {
                if (! ctype_digit($idValue) || (int) $idValue < 1 || ! $products->has((int) $idValue)) {
                    $errors[] = "Zeile {$line}: Die interne Produkt-ID ist ungültig oder nicht mehr vorhanden.";
                    continue;
                }

                $product = $products->get((int) $idValue);
            } elseif ($code !== '') {
                $product = $codes->get($code);
                if ($product === null) {
                    $errors[] = "Zeile {$line}: Der alte Produktcode ist nicht bekannt.";
                    continue;
                }
            } else {
                $matches = $names->get($nameKey, collect());

                if ($matches->count() > 1) {
                    $errors[] = "Zeile {$line}: Der Produktname ist in der Datenbank nicht eindeutig.";
                    continue;
                }

                $product = $matches->first();
            }

            if ($product !== null && isset($seenTargets[$product->id])) {
                $errors[] = "Zeile {$line}: Dieses Produkt ist bereits in einer anderen Excel-Zeile enthalten.";
                continue;
            }

            if ($product !== null) {
                $seenTargets[$product->id] = true;
                if (isset($baseline[$product->id]) && $baseline[$product->id] !== $this->timestamp($product)) {
                    $errors[] = "Zeile {$line}: Das Produkt wurde seit dem Excel-Export im System geändert. Bitte erneut exportieren.";
                    continue;
                }
            }

            $conflicts = $names->get($nameKey, collect())
                ->filter(fn (Product $candidate) => $candidate->id !== $product?->id);

            if ($conflicts->isNotEmpty()) {
                $errors[] = "Zeile {$line}: Der neue Name gehört bereits zu einem anderen Produkt.";
                continue;
            }

            $data = ['name' => $name];

            foreach (['manufacturer_designation', 'serial_number', 'description'] as $field) {
                $value = trim((string) ($source[$field] ?? ''));

                if ($value === '') {
                    continue; // Leere Zellen überschreiben vorhandene Werte nicht.
                }

                $maximum = $field === 'description' ? 5000 : 255;
                if (mb_strlen($value) > $maximum) {
                    $errors[] = "Zeile {$line}: {$field} ist zu lang.";
                } else {
                    $data[$field] = $value;
                }
            }

            $unit = trim((string) ($source['unit'] ?? ''));
            if ($unit !== '' && ! in_array($unit, ['gram', 'liter', 'piece'], true)) {
                $errors[] = "Zeile {$line}: unit muss gram, liter oder piece sein.";
                continue;
            }
            if ($unit !== '' || $product === null) {
                $data['unit'] = $unit ?: 'gram';
            }

            $stock = trim((string) ($source['minimum_stock'] ?? ''));
            if ($stock !== '') {
                $normalized = GermanNumber::parse($stock);
                if (! is_numeric($normalized) || (float) $normalized < 0 || (float) $normalized > 999999999) {
                    $errors[] = "Zeile {$line}: Mindestbestand ist keine gültige nichtnegative Zahl.";
                    continue;
                }
                $data['minimum_stock'] = round((float) $normalized, 3);
            } elseif ($product === null) {
                $data['minimum_stock'] = 0;
            }

            $supplierName = trim((string) ($source['supplier'] ?? ''));
            if ($supplierName !== '') {
                if (mb_strlen($supplierName) > 255) {
                    $errors[] = "Zeile {$line}: Lieferantenname ist zu lang.";
                    continue;
                }

                $matches = $suppliers->get($this->nameKey($supplierName), collect());
                if ($matches->count() > 1) {
                    $errors[] = "Zeile {$line}: Der Lieferant ist nicht eindeutig.";
                    continue;
                }

                $data['supplier'] = $supplierName;
                $data['supplier_id'] = $matches->first()?->id;
            }

            $rows[] = [
                'line' => $line,
                'id' => $product?->id,
                'name' => $name,
                'action' => $product === null ? 'create' : ($product->archived_at === null ? 'update' : 'restore'),
                'data' => $data,
                'updated_at' => $product === null ? null : $this->timestamp($product),
            ];
        }

        $archives = [];

        if ($mode === 'sync') {
            foreach ($baseline as $id => $exportedAt) {
                $product = $products->get($id);
                if ($product === null || $product->archived_at !== null || isset($seenTargets[$id])) {
                    continue;
                }

                if ($exportedAt !== $this->timestamp($product)) {
                    $errors[] = "Produkt {$id}: Seit dem Export geändert; Archivierung ist nicht erlaubt.";
                    continue;
                }

                $archives[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'updated_at' => $this->timestamp($product),
                ];
            }
        }

        if ($errors !== []) {
            $this->fail(implode(' ', array_slice($errors, 0, 15)));
        }

        return [
            'mode' => $mode,
            'rows' => $rows,
            'archives' => $archives,
            'ignored_prices' => $spreadsheet->getSheetByName('Preisstaffeln') !== null,
        ];
    }

    public function apply(array $plan, array $selectedLines, array $archiveIds): array
    {
        $selected = array_values(array_filter(
            $plan['rows'],
            fn (array $row) => in_array($row['line'], $selectedLines, true)
        ));
        $archives = array_values(array_filter(
            $plan['archives'],
            fn (array $row) => in_array($row['id'], $archiveIds, true)
        ));

        return DB::transaction(function () use ($selected, $archives): array {
            $ids = array_values(array_unique(array_merge(
                array_filter(array_column($selected, 'id')),
                array_column($archives, 'id')
            )));
            $existing = Product::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            foreach ($selected as $row) {
                if ($row['id'] === null) {
                    continue;
                }
                $product = $existing->get($row['id']);
                if ($product === null || $this->timestamp($product) !== $row['updated_at']) {
                    $this->fail('Ein Produkt wurde nach der Vorschau geändert. Bitte den Import neu prüfen.');
                }
            }

            foreach ($archives as $row) {
                $product = $existing->get($row['id']);
                if ($product === null || $product->archived_at !== null || $this->timestamp($product) !== $row['updated_at']) {
                    $this->fail('Ein zu archivierendes Produkt wurde geändert. Bitte die Vorschau neu erzeugen.');
                }
            }

            $created = 0;
            $updated = 0;
            $restored = 0;

            foreach ($selected as $row) {
                $other = Product::query()
                    ->whereRaw('LOWER(name) = ?', [$this->nameKey($row['data']['name'])])
                    ->when($row['id'] !== null, fn ($query) => $query->where('id', '!=', $row['id']))
                    ->exists();

                if ($other) {
                    $this->fail('Ein Produktname ist inzwischen schon vergeben. Bitte die Vorschau neu erzeugen.');
                }

                if ($row['id'] === null) {
                    Product::query()->create($row['data']);
                    $created++;
                    continue;
                }

                $product = $existing->get($row['id']);
                $wasArchived = $product->archived_at !== null;
                $product->fill($row['data']);
                if ($wasArchived) {
                    $product->archived_at = null;
                }
                $product->save();
                $wasArchived ? $restored++ : $updated++;
            }

            foreach ($archives as $row) {
                $existing->get($row['id'])->update(['archived_at' => now()]);
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'restored' => $restored,
                'archived' => count($archives),
            ];
        });
    }

    private function readProducts(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $contents = $sheet->toArray(null, true, true, true);
        $first = array_shift($contents) ?? [];
        $headers = [];
        foreach ($first as $column => $label) {
            $normalized = strtolower(trim((string) $label));
            if ($normalized !== '') {
                if (in_array($normalized, $headers, true)) {
                    $this->fail('Doppelte Spaltenüberschrift: ' . $normalized);
                }
                $headers[$column] = $normalized;
            }
        }

        $rows = [];
        $line = 1;
        foreach ($contents as $values) {
            $line++;
            $mapped = [];
            foreach ($headers as $column => $label) {
                $mapped[$label] = $values[$column] ?? null;
            }

            if (collect($mapped)->contains(fn ($value) => trim((string) $value) !== '')) {
                $rows[] = ['line' => $line, 'data' => $mapped];
            }
        }
        return [array_values($headers), $rows];
    }

    private function readBaseline(Spreadsheet $spreadsheet): ?array
    {
        $sheet = $spreadsheet->getSheetByName('__product_sync');
        if ($sheet === null || $sheet->getCell('A1')->getValue() !== 'lager-product-export-v1') {
            return null;
        }

        $baseline = [];
        for ($line = 3; $line <= $sheet->getHighestDataRow(); $line++) {
            $value = trim((string) $sheet->getCell('A' . $line)->getValue());
            $stamp = trim((string) $sheet->getCell('B' . $line)->getValue());
            if (! ctype_digit($value) || (int) $value < 1 || $stamp === '' || isset($baseline[(int) $value])) {
                $this->fail('Die Export-Metadaten sind beschädigt oder unvollständig.');
            }
            $baseline[(int) $value] = $stamp;
        }
        return $baseline;
    }

    private function nameKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function timestamp(Product $product): ?string
    {
        return $product->updated_at?->toIso8601String();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['excel_file' => $message]);
    }
}
