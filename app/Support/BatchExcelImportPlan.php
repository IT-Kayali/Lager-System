<?php

namespace App\Support;

use App\Models\ApplicationSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BatchExcelImportPlan
{
    /** Gleiche Reihenfolge wie auf dem ausgeblendeten Export-Metadatenblatt. */
    public static function snapshot(ProductBatch $batch): array
    {
        return [
            'id' => (int) $batch->id,
            'product_id' => (int) $batch->product_id,
            'batch_number' => (string) $batch->batch_number,
            'quantity' => number_format((float) $batch->quantity, 3, '.', ''),
            'received_at' => $batch->received_at?->format('Y-m-d') ?? '',
            'expires_at' => $batch->expires_at?->format('Y-m-d') ?? '',
            'updated_at' => $batch->updated_at?->toIso8601String() ?? '',
        ];
    }

    public function prepare(Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getSheetByName('Chargen');
        if (! $sheet) {
            $this->reject('Das Excel-Tabellenblatt „Chargen“ fehlt.');
        }

        $headers = [];
        foreach ($sheet->getRowIterator(1, 1)->current()->getCellIterator() as $cell) {
            $name = strtolower(trim((string) $cell->getValue()));
            if ($name === '') {
                continue;
            }
            if (isset($headers[$name])) {
                $this->reject("Die Excel-Spalte {$name} ist doppelt vorhanden.");
            }
            $headers[$name] = $cell->getColumn();
        }

        foreach (['batch_number', 'product', 'quantity', 'expires_at', 'received_at'] as $required) {
            if (! isset($headers[$required])) {
                $this->reject("Die erforderliche Excel-Spalte {$required} fehlt.");
            }
        }

        if ($sheet->getHighestDataRow() > 10001) {
            $this->reject('Maximal 10.000 Chargenzeilen pro Import sind erlaubt.');
        }

        $original = $this->exportSnapshots($spreadsheet);
        $rows = [];
        $usedBatchIds = [];
        $usedNumbers = [];

        for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
            $values = [];
            foreach (['batch_number', 'product', 'quantity', 'expires_at', 'received_at', '_batch_id', '_product_id'] as $name) {
                $values[$name] = isset($headers[$name])
                    ? $this->value($sheet, $headers[$name] . $line, $line)
                    : null;
            }

            if (count(array_filter($values, fn ($value) => $value !== null && trim((string) $value) !== '')) === 0) {
                continue;
            }

            $number = trim((string) ($values['batch_number'] ?? ''));
            $productName = trim((string) ($values['product'] ?? ''));
            if ($number === '' || mb_strlen($number) > 255) {
                $this->reject("Zeile {$line}: Eine Batchnummer mit maximal 255 Zeichen ist erforderlich.");
            }
            if ($productName === '') {
                $this->reject("Zeile {$line}: Ein Produktname ist erforderlich.");
            }
            if (isset($usedNumbers[mb_strtolower($number)])) {
                $this->reject("Zeile {$line}: Die Batchnummer {$number} ist in der Datei mehrfach vorhanden.");
            }
            $usedNumbers[mb_strtolower($number)] = true;

            $batchId = $this->positiveId($values['_batch_id'], $line, '_batch_id');
            $productId = $this->positiveId($values['_product_id'], $line, '_product_id');

            if ($batchId !== null && isset($usedBatchIds[$batchId])) {
                $this->reject("Zeile {$line}: Diese Charge (ID {$batchId}) ist in der Datei doppelt vorhanden.");
            }
            if ($batchId !== null) {
                $usedBatchIds[$batchId] = true;
            }

            $batch = $batchId !== null
                ? ProductBatch::query()->find($batchId)
                : ProductBatch::query()->where('batch_number', $number)->first();

            if ($batchId !== null && ! $batch) {
                $this->reject("Zeile {$line}: Charge-ID {$batchId} existiert nicht mehr.");
            }
            if ($batchId !== null) {
                if (! isset($original[$batchId])) {
                    $this->reject("Zeile {$line}: Die unveränderten Exportmetadaten für Charge {$batchId} fehlen.");
                }
                if (self::snapshot($batch) !== $original[$batchId]) {
                    $this->reject("Zeile {$line}: Charge {$batchId} wurde seit dem Export verändert. Bitte neu exportieren.");
                }
            }

            $otherBatch = ProductBatch::query()->where('batch_number', $number)->first();
            if ($otherBatch && $batch && $otherBatch->id !== $batch->id) {
                $this->reject("Zeile {$line}: Die Batchnummer {$number} gehört bereits zu einer anderen Charge.");
            }

            if ($productId !== null) {
                $product = Product::query()->find($productId);
                if (! $product || $product->name !== $productName) {
                    $this->reject("Zeile {$line}: Produktname und ausgeblendete Produkt-ID stimmen nicht überein.");
                }
            } else {
                $matches = Product::query()->where('name', $productName)->get();
                if ($matches->count() !== 1 || $matches->first()->name !== $productName) {
                    $this->reject("Zeile {$line}: Produkt „{$productName}“ muss eindeutig vorhanden sein.");
                }
                $product = $matches->first();
            }

            if ($batch && (int) $batch->product_id !== (int) $product->id) {
                $this->reject("Zeile {$line}: Eine vorhandene Charge darf keinem anderen Produkt zugeordnet werden.");
            }
            if (! $batch && $product->archived_at !== null) {
                $this->reject("Zeile {$line}: Für ein archiviertes Produkt darf keine neue Charge angelegt werden.");
            }

            $quantity = $this->quantity($values['quantity'], $line);
            $received = $this->date($values['received_at'], $line, 'received_at')
                ?? $batch?->received_at?->format('Y-m-d');
            if ($received === null) {
                $this->reject("Zeile {$line}: Wareneingangsdatum (received_at) fehlt.");
            }
            $expires = $this->date($values['expires_at'], $line, 'expires_at')
                ?? $batch?->expires_at?->format('Y-m-d')
                ?? Carbon::parse($received)->addMonthsNoOverflow(ApplicationSetting::defaultBatchExpiryMonths())->toDateString();

            $old = $batch ? self::snapshot($batch) : null;
            $changes = ! $old || $old['batch_number'] !== $number
                || (float) $old['quantity'] !== $quantity
                || $old['received_at'] !== $received
                || $old['expires_at'] !== $expires;

            $rows[] = [
                'line' => $line,
                'batch_id' => $batch?->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'batch_number' => $number,
                'quantity' => $quantity,
                'old_quantity' => $batch ? (float) $batch->quantity : 0.0,
                'quantity_delta' => round($quantity - ($batch ? (float) $batch->quantity : 0), 3),
                'received_at' => $received,
                'expires_at' => $expires,
                'action' => ! $batch ? 'create' : ($changes ? 'update' : 'unchanged'),
                'original' => $old,
            ];
        }

        return ['rows' => $rows];
    }

    public function apply(array $plan, array $selectedLines, ?int $userId): array
    {
        return DB::transaction(function () use ($plan, $selectedLines, $userId) {
            $selected = array_values(array_filter($plan['rows'],
                fn (array $row) => in_array($row['line'], $selectedLines, true)));

            // Zuerst ALLE betroffenen Chargen sperren und prüfen, dann erst etwas buchen.
            $locked = [];
            foreach ($selected as $row) {
                $product = Product::query()->find($row['product_id']);
                if (! $product || $product->name !== $row['product_name']
                    || ($row['batch_id'] === null && $product->archived_at !== null)) {
                    $this->reject("Zeile {$row['line']}: Das Produkt hat sich seit der Vorschau verändert.");
                }

                if ($row['batch_id'] !== null) {
                    $batch = ProductBatch::query()->lockForUpdate()->find($row['batch_id']);
                    if (! $batch || self::snapshot($batch) !== $row['original']) {
                        $this->reject("Zeile {$row['line']}: Die Charge wurde inzwischen verändert. Bitte neu importieren.");
                    }
                    $locked[$row['batch_id']] = $batch;
                } elseif (ProductBatch::query()->where('batch_number', $row['batch_number'])->exists()) {
                    $this->reject("Zeile {$row['line']}: Die Batchnummer existiert inzwischen. Bitte neu importieren.");
                }
            }

            // Ein Excel-Bestand darf keine bereits reservierten Einheiten wegkorrigieren.
            // Mehrere ausgewählte Chargen desselben Produkts werden gemeinsam bewertet.
            $netDeltas = [];
            foreach ($selected as $row) {
                $netDeltas[$row['product_id']] = ($netDeltas[$row['product_id']] ?? 0.0)
                    + $row['quantity_delta'];
            }
            foreach ($netDeltas as $productId => $delta) {
                if ($delta >= 0) {
                    continue;
                }
                $product = Product::query()->findOrFail($productId);
                if ($product->total_stock + $delta + 0.0001 < $product->reserved_stock) {
                    $this->reject("Produkt {$product->name}: Die Bestandskorrektur würde bereits reservierte Ware unterschreiten.");
                }
            }

            $created = 0;
            $updated = 0;
            $movements = 0;

            foreach ($selected as $row) {
                if ($row['action'] === 'unchanged') {
                    continue;
                }

                $attributes = [
                    'batch_number' => $row['batch_number'],
                    'quantity' => $row['quantity'],
                    'received_at' => $row['received_at'],
                    'expires_at' => $row['expires_at'],
                ];

                if ($row['batch_id'] === null) {
                    $batch = ProductBatch::query()->create(['product_id' => $row['product_id']] + $attributes);
                    $created++;
                } else {
                    $batch = $locked[$row['batch_id']];
                    $batch->update($attributes);
                    $updated++;
                }

                if ($row['quantity_delta'] !== 0.0) {
                    StockMovement::query()->create([
                        'product_id' => $batch->product_id,
                        'product_batch_id' => $batch->id,
                        'user_id' => $userId,
                        'type' => $row['batch_id'] === null ? 'in' : 'adjustment',
                        'quantity' => $row['quantity_delta'],
                        'note' => 'Excel-Chargenimport (Zeile ' . $row['line'] . ')',
                    ]);
                    $movements++;
                }
            }

            return compact('created', 'updated', 'movements');
        });
    }

    private function exportSnapshots(Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getSheetByName('__batch_sync');
        if (! $sheet) {
            return [];
        }
        if ((string) $sheet->getCell('A1')->getValue() !== 'lager-batch-export-v1') {
            $this->reject('Die internen Exportmetadaten sind ungültig.');
        }

        $snapshots = [];
        for ($line = 3; $line <= $sheet->getHighestDataRow(); $line++) {
            $id = $this->positiveId($this->value($sheet, 'A' . $line, $line), $line, 'batch_id');
            if ($id === null || isset($snapshots[$id])) {
                $this->reject('Die internen Exportmetadaten enthalten ungültige oder doppelte Chargen-IDs.');
            }

            $snapshots[$id] = [
                'id' => $id,
                'product_id' => (int) $sheet->getCell('B' . $line)->getValue(),
                'batch_number' => (string) $sheet->getCell('C' . $line)->getValue(),
                'quantity' => (string) $sheet->getCell('D' . $line)->getValue(),
                'received_at' => (string) $sheet->getCell('E' . $line)->getValue(),
                'expires_at' => (string) $sheet->getCell('F' . $line)->getValue(),
                'updated_at' => (string) $sheet->getCell('G' . $line)->getValue(),
            ];
        }

        return $snapshots;
    }

    private function value(Worksheet $sheet, string $address, int $line): int|float|string|null
    {
        $cell = $sheet->getCell($address);
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            $this->reject("Zeile {$line}: Excel-Formeln sind beim Import nicht erlaubt.");
        }

        $value = $cell->getValue();
        return is_int($value) || is_float($value) || is_string($value) ? $value : null;
    }

    private function positiveId(int|float|string|null $value, int $line, string $field): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (! preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            $this->reject("Zeile {$line}: {$field} muss eine gültige positive ID sein.");
        }

        return (int) $value;
    }

    private function quantity(int|float|string|null $value, int $line): float
    {
        if ($value === null || trim((string) $value) === '') {
            $this->reject("Zeile {$line}: Menge (quantity) fehlt.");
        }

        $number = str_replace(["\u{00a0}", ' '], '', trim((string) $value));
        if (str_contains($number, ',') && str_contains($number, '.')) {
            $number = strrpos($number, ',') > strrpos($number, '.')
                ? str_replace(',', '.', str_replace('.', '', $number))
                : str_replace(',', '', $number);
        } else {
            $number = str_replace(',', '.', $number);
        }

        if (! preg_match('/^[0-9]+(?:\.[0-9]{1,3})?$/D', $number)
            || (float) $number > 999999999) {
            $this->reject("Zeile {$line}: Ungültige Menge (0 bis 999999999, maximal drei Nachkommastellen).");
        }

        return round((float) $number, 3);
    }

    private function date(int|float|string|null $value, int $line, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value) && ! is_string($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                $this->reject("Zeile {$line}: Ungültiges Datum in {$field}.");
            }
        }

        $text = trim((string) $value);
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $text);
            if ($date && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        $this->reject("Zeile {$line}: Ungültiges Datum in {$field} (TT.MM.JJJJ oder JJJJ-MM-TT).");
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['excel_file' => $message]);
    }
}
