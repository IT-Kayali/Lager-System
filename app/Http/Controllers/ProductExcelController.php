<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductExcelController extends Controller
{
    public function export(): BinaryFileResponse
    {
        $products = Product::query()
            ->active()
            ->with('supplierRecord')
            ->orderBy('name')
            ->get();

        $spreadsheet = new Spreadsheet();

        $productsSheet = $spreadsheet->getActiveSheet();
        $productsSheet->setTitle('Produkte');
        $productsSheet->fromArray([
            [
                'name',
                'manufacturer_designation',
                'serial_number',
                'unit',
                'supplier',
                'minimum_stock',
                'description',
                '_internal_id',
            ],
        ]);

        $row = 2;

        foreach ($products as $product) {
            $productsSheet->fromArray([
                [
                    $product->name,
                    $product->manufacturer_designation,
                    $product->serial_number,
                    $product->unit,
                    $product->supplierRecord?->company_name ?: $product->supplier,
                    (float) $product->minimum_stock,
                    $product->description,
                    $product->id,
                ],
            ], null, 'A' . $row);

            $row++;
        }

        if ($row > 2) {
            $productsSheet
                ->getStyle('F2:F' . ($row - 1))
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        foreach (range('A', 'G') as $column) {
            $productsSheet->getColumnDimension($column)->setAutoSize(true);
        }
        $productsSheet->getColumnDimension('H')->setVisible(false);

        // Der ausgeblendete Metadatenbereich enthält die ursprüngliche vollständige
        // Exportmenge. Nur so lassen sich bewusst entfernte Zeilen archivieren,
        // ohne später erstellte Produkte versehentlich zu erfassen.
        $metadata = $spreadsheet->createSheet();
        $metadata->setTitle('__product_sync');
        $metadata->fromArray([['lager-product-export-v1'], ['product_id', 'updated_at']]);
        foreach ($products as $position => $product) {
            $metadata->fromArray([
                [$product->id, $product->updated_at?->toIso8601String()],
            ], null, 'A' . ($position + 3));
        }
        $metadata->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        $spreadsheet->setActiveSheetIndex(0);

        $path = storage_path('app/produkte-' . Str::uuid() . '.xlsx');

        (new Xlsx($spreadsheet))->save($path);

        return response()
            ->download($path, 'produkte.xlsx')
            ->deleteFileAfterSend(true);
    }

    public function importForm(): View
    {
        return view('pages.products.excel-import');
    }

    public function import(Request $request, \App\Support\ProductExcelImportPlan $service): View|RedirectResponse
    {
        $data = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'import_mode' => ['required', 'in:update,sync'],
        ]);

        try {
            $spreadsheet = IOFactory::load($data['excel_file']->getRealPath());
            try {
                $plan = $service->prepare($spreadsheet, $data['import_mode']);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Die Excel-Datei konnte nicht gelesen werden. Bitte eine gültige Excel-Datei hochladen.');
        }

        $token = Str::random(48);
        Cache::put($this->previewKey($token), $plan, now()->addMinutes(30));

        return view('pages.products.excel-import', [
            'preview' => $plan,
            'previewToken' => $token,
        ]);
    }

    public function confirm(Request $request, \App\Support\ProductExcelImportPlan $service): RedirectResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:48'],
            'selected_rows' => ['nullable', 'array'],
            'selected_rows.*' => ['integer', 'min:2'],
            'archive_ids' => ['nullable', 'array'],
            'archive_ids.*' => ['integer', 'min:1'],
            'archive_confirmation' => ['nullable', 'string'],
        ]);

        $key = $this->previewKey($data['preview_token']);
        $plan = Cache::get($key);

        if (! is_array($plan)) {
            return redirect()->route('products.excel.import.form')
                ->with('error', 'Die Importvorschau ist abgelaufen. Bitte die Datei erneut hochladen.');
        }

        $lines = array_values(array_unique(array_map('intval', $data['selected_rows'] ?? [])));
        $archiveIds = array_values(array_unique(array_map('intval', $data['archive_ids'] ?? [])));

        $allowedLines = array_column($plan['rows'], 'line');
        $allowedArchives = array_column($plan['archives'], 'id');

        if (array_diff($lines, $allowedLines) !== [] || array_diff($archiveIds, $allowedArchives) !== []) {
            return redirect()->route('products.excel.import.form')
                ->with('error', 'Die Auswahl der Importzeilen ist ungültig. Bitte die Vorschau erneut erstellen.');
        }

        if ($archiveIds !== [] && ($data['archive_confirmation'] ?? '') !== 'ARCHIVIEREN') {
            return redirect()->route('products.excel.import.form')
                ->with('error', 'Zur Archivierung bitte das Bestätigungswort ARCHIVIEREN eintragen.');
        }

        try {
            $result = $service->apply($plan, $lines, $archiveIds);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return redirect()->route('products.excel.import.form')
                ->with('error', $exception->validator->errors()->first('excel_file'));
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->route('products.excel.import.form')
                ->with('error', 'Import wurde ohne Änderungen abgebrochen. Bitte Protokoll prüfen und neu versuchen.');
        }

        Cache::forget($key);

        return redirect()->route('products.index')->with(
            'success',
            "Excel-Abgleich abgeschlossen: {$result['created']} neu, {$result['updated']} aktualisiert, {$result['restored']} reaktiviert, {$result['archived']} archiviert."
        );
    }

    private function previewKey(string $token): string
    {
        return 'product-excel-import:' . auth()->id() . ':' . $token;
    }
}
