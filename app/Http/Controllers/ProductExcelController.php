<?php

namespace App\Http\Controllers;

use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Support\GermanNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductExcelController extends Controller
{
    public function export(): BinaryFileResponse
    {
        $products = Product::query()
            ->with(['priceTiers.customerGroup'])
            ->orderBy('name')
            ->get();

        foreach ($products as $product) {
            ProductPriceTier::ensureForProduct($product);
        }

        $products->load(['priceTiers.customerGroup']);

        $spreadsheet = new Spreadsheet();

        $productsSheet = $spreadsheet->getActiveSheet();
        $productsSheet->setTitle('Produkte');
        $productsSheet->fromArray([
            [
                'product_code',
                'name',
                'manufacturer_designation',
                'serial_number',
                'unit',
                'supplier',
                'minimum_stock',
                'description',
            ],
        ]);

        $row = 2;

        foreach ($products as $product) {
            $productsSheet->fromArray([
                [
                    $product->product_code,
                    $product->name,
                    $product->manufacturer_designation,
                    $product->serial_number,
                    $product->unit,
                    $product->supplier,
                    (float) $product->minimum_stock,
                    $product->description,
                ],
            ], null, 'A' . $row);

            $row++;
        }

        if ($row > 2) {
            $productsSheet
                ->getStyle('G2:G' . ($row - 1))
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        foreach (range('A', 'H') as $column) {
            $productsSheet->getColumnDimension($column)->setAutoSize(true);
        }

        $priceSheet = $spreadsheet->createSheet();
        $priceSheet->setTitle('Preisstaffeln');
        $priceSheet->fromArray([
            [
                'product_code',
                'customer_group',
                'tier_key',
                'tier_label',
                'min_grams',
                'max_grams',
                'price',
            ],
        ]);

        $row = 2;

        foreach ($products as $product) {
            foreach ($product->priceTiers->sortBy(['customer_group_id', 'min_grams']) as $tier) {
                $priceSheet->fromArray([
                    [
                        $product->product_code,
                        $tier->customerGroup?->slug ?: $tier->customerGroup?->name,
                        $tier->tier_key,
                        $tier->tier_label,
                        $tier->min_grams,
                        $tier->max_grams,
                        (float) $tier->price,
                    ],
                ], null, 'A' . $row);

                $row++;
            }
        }

        if ($row > 2) {
            $priceSheet
                ->getStyle('G2:G' . ($row - 1))
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        foreach (range('A', 'G') as $column) {
            $priceSheet->getColumnDimension($column)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $path = storage_path('app/produkte-preise-' . now()->format('Y-m-d-His') . '.xlsx');

        (new Xlsx($spreadsheet))->save($path);

        return response()
            ->download($path, 'produkte-preise.xlsx')
            ->deleteFileAfterSend(true);
    }

    public function importForm(): View
    {
        return view('pages.products.excel-import');
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        $spreadsheet = IOFactory::load($data['excel_file']->getRealPath());

        $productRows = $this->sheetRows($spreadsheet, 'Produkte');
        $priceRows = $this->sheetRows($spreadsheet, 'Preisstaffeln');

        $errors = [];
        $createdProducts = 0;
        $updatedProducts = 0;
        $updatedPrices = 0;

        DB::beginTransaction();

        try {
            foreach ($productRows as $index => $row) {
                $line = $index + 2;

                $productCode = $this->cleanString($row['product_code'] ?? null);
                $name = $this->cleanString($row['name'] ?? null);
                $unit = $this->cleanString($row['unit'] ?? null) ?: 'gram';

                if ($productCode === '') {
                    $errors[] = "Produkte Zeile {$line}: product_code fehlt.";
                    continue;
                }

                if ($name === '') {
                    $errors[] = "Produkte Zeile {$line}: name fehlt.";
                    continue;
                }

                if (! in_array($unit, ['gram', 'liter', 'piece'], true)) {
                    $errors[] = "Produkte Zeile {$line}: unit muss gram, liter oder piece sein.";
                    continue;
                }

                $productData = [
                    'name' => $name,
                    'manufacturer_designation' => $this->nullableString($row['manufacturer_designation'] ?? null),
                    'serial_number' => $this->nullableString($row['serial_number'] ?? null),
                    'unit' => $unit,
                    'supplier' => $this->nullableString($row['supplier'] ?? null),
                    'minimum_stock' => $this->decimal($row['minimum_stock'] ?? 0),
                    'description' => $this->nullableString($row['description'] ?? null),
                ];

                $product = Product::query()
                    ->where('product_code', $productCode)
                    ->first();

                $duplicateNameQuery = Product::query()
                    ->where('name', $name);

                if ($product !== null) {
                    $duplicateNameQuery->where('id', '!=', $product->id);
                }

                if ($duplicateNameQuery->exists()) {
                    $errors[] = "Produkte Zeile {$line}: Der Produktname {$name} wird bereits von einem anderen Produkt verwendet.";
                    continue;
                }

                if ($product) {
                    $product->update($productData);
                    $updatedProducts++;
                } else {
                    $product = Product::create(array_merge($productData, [
                        'product_code' => $productCode,
                    ]));
                    $createdProducts++;
                }

                ProductPriceTier::ensureForProduct($product);
            }

            foreach ($priceRows as $index => $row) {
                $line = $index + 2;

                $productCode = $this->cleanString($row['product_code'] ?? null);
                $customerGroupValue = $this->cleanString($row['customer_group'] ?? null);
                $tierKey = $this->cleanString($row['tier_key'] ?? null);

                if ($productCode === '') {
                    $errors[] = "Preisstaffeln Zeile {$line}: product_code fehlt.";
                    continue;
                }

                if ($customerGroupValue === '') {
                    $errors[] = "Preisstaffeln Zeile {$line}: customer_group fehlt.";
                    continue;
                }

                if ($tierKey === '') {
                    $errors[] = "Preisstaffeln Zeile {$line}: tier_key fehlt.";
                    continue;
                }

                $product = Product::query()
                    ->where('product_code', $productCode)
                    ->first();

                if (! $product) {
                    $errors[] = "Preisstaffeln Zeile {$line}: Produkt {$productCode} nicht gefunden.";
                    continue;
                }

                $group = CustomerGroup::query()
                    ->where('slug', $customerGroupValue)
                    ->orWhere('name', $customerGroupValue)
                    ->first();

                if (! $group) {
                    $errors[] = "Preisstaffeln Zeile {$line}: Kundengruppe {$customerGroupValue} nicht gefunden.";
                    continue;
                }

                $defaultTier = ProductPriceTier::definitionForKey($tierKey);

                $tierLabel = $this->cleanString($row['tier_label'] ?? null)
                    ?: ($defaultTier?->label ?? $tierKey);

                $minGrams = $this->integer(
                    $row['min_grams'] ?? null,
                    $defaultTier?->min_grams ?? 0
                );
                $maxGrams = $this->nullableInteger(
                    $row['max_grams'] ?? null,
                    $defaultTier?->max_grams
                );
                $price = $this->decimal($row['price'] ?? 0);

                ProductPriceTier::query()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'customer_group_id' => $group->id,
                        'tier_key' => $tierKey,
                    ],
                    [
                        'tier_label' => $tierLabel,
                        'min_grams' => $minGrams,
                        'max_grams' => $maxGrams,
                        'price' => $price,
                    ]
                );

                $updatedPrices++;
            }

            if ($errors !== []) {
                DB::rollBack();

                return back()
                    ->withInput()
                    ->with('error', 'Import wurde wegen Fehlern abgebrochen.')
                    ->with('import_errors', $errors);
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Der Excel-Import konnte nicht abgeschlossen werden. Bitte die Datei prüfen oder einen Administrator kontaktieren.');
        }

        return redirect()
            ->route('products.index')
            ->with('success', "Excel-Import abgeschlossen. Produkte neu: {$createdProducts}, Produkte aktualisiert: {$updatedProducts}, Preise aktualisiert: {$updatedPrices}.");
    }

    private function sheetRows(Spreadsheet $spreadsheet, string $sheetName): array
    {
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if (! $sheet) {
            return [];
        }

        $rows = $sheet->toArray(null, true, true, true);

        if ($rows === []) {
            return [];
        }

        $headerRow = array_shift($rows);
        $headers = [];

        foreach ($headerRow as $column => $value) {
            $header = strtolower(trim((string) $value));

            if ($header !== '') {
                $headers[$column] = $header;
            }
        }

        $mappedRows = [];

        foreach ($rows as $row) {
            $mapped = [];
            $hasValue = false;

            foreach ($headers as $column => $header) {
                $value = $row[$column] ?? null;
                $mapped[$header] = $value;

                if ($this->cleanString($value) !== '') {
                    $hasValue = true;
                }
            }

            if ($hasValue) {
                $mappedRows[] = $mapped;
            }
        }

        return $mappedRows;
    }

    private function cleanString(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->cleanString($value);

        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): float
    {
        $normalized = GermanNumber::parse($this->cleanString($value));

        return round((float) $normalized, 2);
    }

    private function integer(mixed $value, int $default = 0): int
    {
        $value = $this->cleanString($value);

        if ($value === '') {
            return $default;
        }

        return (int) $value;
    }

    private function nullableInteger(mixed $value, ?int $default = null): ?int
    {
        $value = $this->cleanString($value);

        if ($value === '') {
            return $default;
        }

        return (int) $value;
    }
}
