<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\CustomerGroup;
use App\Models\ManualPriceRule;
use App\Models\Product;
use App\Models\ProductPriceTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class PriceExcelImportPlan
{
    public const HEADERS = [
        'product', 'customer_group', 'pricing_mode', 'tier_key',
        'quantity_from', 'quantity_to', 'unit', 'price', 'label',
        '_product_id', '_group_id', '_price_id',
    ];

    public function prepare(Spreadsheet $spreadsheet, string $scope): array
    {
        $groupId = $this->groupId($scope);
        $sheet = $spreadsheet->getSheetByName('Preise');

        if ($sheet === null) {
            $this->reject('Das Tabellenblatt „Preise“ fehlt. Bitte die aktuelle Preisvorlage benutzen.');
        }

        if ($sheet->getHighestDataRow() > 50001) {
            $this->reject('Es sind maximal 50.000 Preiszeilen pro Import erlaubt.');
        }

        foreach (self::HEADERS as $index => $header) {
            $cell = $sheet->getCell([$index + 1, 1]);
            if (trim((string) $cell->getValue()) !== $header) {
                $this->reject("Spalte " . ($index + 1) . " muss „{$header}“ heißen. Bitte eine aktuelle Vorlage verwenden.");
            }
        }

        $rows = [];
        $seen = [];

        for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
            $values = [];
            foreach (self::HEADERS as $index => $header) {
                $cell = $sheet->getCell([$index + 1, $line]);
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $this->reject("Zeile {$line}: Formeln sind aus Sicherheitsgründen nicht erlaubt.");
                }
                $values[$header] = $cell->getValue();
            }

            // Leere Preise sind ausdrücklich kein Lösch- oder Nullsetz-Befehl.
            if ($values['price'] === null || trim((string) $values['price']) === '') {
                continue;
            }

            $id = filter_var($values['_price_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $productId = filter_var($values['_product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $rowGroupId = filter_var($values['_group_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $mode = trim((string) $values['pricing_mode']);

            if (! $id || ! $productId || ! $rowGroupId || ! in_array($mode, ['staffel', 'manuell'], true)) {
                $this->reject("Zeile {$line}: Ungültige Zuordnung. Die ausgeblendeten IDs und die Preisart dürfen nicht verändert werden.");
            }

            if ($groupId !== null && $rowGroupId !== $groupId) {
                $this->reject("Zeile {$line}: Diese Preiszeile gehört nicht zur ausgewählten Kundengruppe.");
            }

            $uniqueKey = "{$mode}:{$id}";
            if (isset($seen[$uniqueKey])) {
                $this->reject("Zeile {$line}: Preisregel bereits in Zeile {$seen[$uniqueKey]} enthalten.");
            }
            $seen[$uniqueKey] = $line;

            $priceValue = GermanNumber::parse($values['price']);
            $priceText = trim((string) $priceValue);
            if (! preg_match('/^(?:0|[1-9]\d{0,8})(?:\.\d{1,5})?$/D', $priceText)) {
                $this->reject("Zeile {$line}: Der Preis muss zwischen 0 und 999999999 liegen und höchstens fünf Nachkommastellen haben.");
            }
            $newPrice = number_format((float) $priceText, 5, '.', '');

            $record = ($mode === 'staffel' ? ProductPriceTier::query() : ManualPriceRule::query())
                ->with(['product.categories', 'customerGroup'])
                ->find($id);

            if ($record === null) {
                $this->reject("Zeile {$line}: Die zugehörige Preisregel existiert nicht mehr.");
            }

            $this->verifyIdentity($record, $values, $mode, $productId, $rowGroupId, $line);
            $oldPrice = number_format((float) $record->price, 5, '.', '');

            if ($oldPrice === $newPrice) {
                continue;
            }

            $rows[] = [
                'line' => $line,
                'id' => $id,
                'product_id' => $productId,
                'group_id' => $rowGroupId,
                'mode' => $mode,
                'product' => $record->product->name,
                'group' => $record->customerGroup->name,
                'label' => $mode === 'staffel' ? $record->tier_label : ($record->label ?: 'Mengenregel'),
                'tier_key' => $mode === 'staffel' ? $record->tier_key : '',
                'min' => $this->rangeValue($mode === 'staffel' ? $record->min_grams : $record->min_quantity),
                'max' => $this->rangeValue($mode === 'staffel' ? $record->max_grams : $record->max_quantity),
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
            ];
        }

        return ['scope' => $scope, 'rows' => $rows];
    }

    public function apply(array $plan, array $selectedLines): int
    {
        $allowedLines = array_column($plan['rows'], 'line');
        if (array_diff($selectedLines, $allowedLines) !== []) {
            $this->reject('Die ausgewählten Zeilen gehören nicht zu dieser Importvorschau.');
        }

        $selected = array_flip($selectedLines);
        $changes = array_values(array_filter($plan['rows'], fn (array $row) => isset($selected[$row['line']])));

        DB::transaction(function () use ($changes, $plan): void {
            $scopeId = $this->groupId($plan['scope']);

            foreach ($changes as $row) {
                $record = ($row['mode'] === 'staffel' ? ProductPriceTier::query() : ManualPriceRule::query())
                    ->lockForUpdate()
                    ->find($row['id']);

                if ($record === null
                    || (int) $record->product_id !== $row['product_id']
                    || (int) $record->customer_group_id !== $row['group_id']
                    || ($scopeId !== null && $scopeId !== $row['group_id'])
                    || number_format((float) $record->price, 5, '.', '') !== $row['old_price']
                    || $this->rangeValue($row['mode'] === 'staffel' ? $record->min_grams : $record->min_quantity) !== $row['min']
                    || $this->rangeValue($row['mode'] === 'staffel' ? $record->max_grams : $record->max_quantity) !== $row['max']
                    || ($row['mode'] === 'staffel' && $record->tier_key !== $row['tier_key'])
                ) {
                    $this->reject("Zeile {$row['line']}: Preis oder Mengenregel wurde inzwischen verändert. Bitte eine neue Excel-Vorschau erstellen.");
                }

                $product = Product::query()->active()->with('categories')->find($row['product_id']);
                $group = CustomerGroup::find($row['group_id']);
                if ($product === null || $group === null
                    || $product->usesPriceTiers() !== ($row['mode'] === 'staffel')) {
                    $this->reject("Zeile {$row['line']}: Produkt, Preisart oder Kundengruppe hat sich geändert.");
                }

                $record->update(['price' => $row['new_price']]);
                ActivityLog::record('price.excel_updated', $record, [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'customer_group' => $group->name,
                    'mode' => $row['mode'],
                    'old_price' => $row['old_price'],
                    'new_price' => $row['new_price'],
                ]);
            }
        });

        return count($changes);
    }

    public function groupId(string $scope): ?int
    {
        if ($scope === 'all') {
            return null;
        }

        $id = filter_var($scope, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || ! CustomerGroup::query()->whereKey($id)->exists()) {
            $this->reject('Die ausgewählte Kundengruppe ist nicht mehr vorhanden.');
        }

        return $id;
    }

    private function verifyIdentity($record, array $values, string $mode, int $productId, int $groupId, int $line): void
    {
        $product = $record->product;
        $group = $record->customerGroup;

        if ($product === null || $group === null || $product->archived_at !== null
            || $product->usesPriceTiers() !== ($mode === 'staffel')
            || (int) $record->product_id !== $productId
            || (int) $record->customer_group_id !== $groupId
            || trim((string) $values['product']) !== $product->name
            || trim((string) $values['customer_group']) !== $group->name
            || trim((string) $values['unit']) !== $product->unitLabel('de')
            || trim((string) $values['tier_key']) !== ($mode === 'staffel' ? $record->tier_key : '')
            || $this->rangeValue($values['quantity_from']) !== $this->rangeValue($mode === 'staffel' ? $record->min_grams : $record->min_quantity)
            || $this->rangeValue($values['quantity_to']) !== $this->rangeValue($mode === 'staffel' ? $record->max_grams : $record->max_quantity)) {
            $this->reject("Zeile {$line}: Produkt, Gruppe oder Mengenbereich passt nicht mehr zur Datenbank. Bitte neue Vorlage exportieren.");
        }
    }

    private function rangeValue(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = GermanNumber::parse($value);
        if (! is_numeric($normalized)) {
            return 'INVALID:' . (string) $value;
        }

        return number_format((float) $normalized, 3, '.', '');
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['excel_file' => $message]);
    }
}
