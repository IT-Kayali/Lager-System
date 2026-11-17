<?php

namespace App\Http\Controllers;

use App\Models\ProductBatch;
use App\Support\BatchExcelImportPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BatchExcelController extends Controller
{
    public function export(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Chargen');
        $sheet->fromArray([[
            'batch_number', 'product', 'quantity', 'expires_at', 'received_at',
            '_batch_id', '_product_id',
        ]]);

        $metadata = $spreadsheet->createSheet();
        $metadata->setTitle('__batch_sync');
        $metadata->setCellValueExplicit('A1', 'lager-batch-export-v1', DataType::TYPE_STRING);
        $metadata->fromArray([[
            'batch_id', 'product_id', 'batch_number', 'quantity', 'received_at', 'expires_at', 'updated_at',
        ]], null, 'A2');

        $row = 2;
        ProductBatch::query()->with('product')->orderBy('received_at')->orderBy('id')
            ->chunk(500, function ($batches) use ($sheet, $metadata, &$row) {
                foreach ($batches as $batch) {
                    $fields = [
                        'A' => $batch->batch_number,
                        'B' => $batch->product?->name ?? '',
                        'D' => $batch->expires_at?->format('Y-m-d') ?? '',
                        'E' => $batch->received_at?->format('Y-m-d') ?? '',
                    ];
                    foreach ($fields as $column => $value) {
                        $sheet->setCellValueExplicit($column . $row, (string) $value, DataType::TYPE_STRING);
                    }
                    $sheet->setCellValue('C' . $row, (float) $batch->quantity);
                    $sheet->setCellValue('F' . $row, $batch->id);
                    $sheet->setCellValue('G' . $row, $batch->product_id);

                    $original = BatchExcelImportPlan::snapshot($batch);
                    $values = array_values($original);
                    foreach (range('A', 'G') as $index => $column) {
                        $metadata->setCellValueExplicit($column . ($row + 1), (string) ($values[$index] ?? ''), DataType::TYPE_STRING);
                    }
                    $row++;
                }
            });

        if ($row > 2) {
            $sheet->getStyle('C2:C' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.000');
        }
        foreach (range('A', 'E') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getColumnDimension('F')->setVisible(false);
        $sheet->getColumnDimension('G')->setVisible(false);
        $metadata->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $spreadsheet->setActiveSheetIndex(0);

        $path = storage_path('app/chargen-' . Str::uuid() . '.xlsx');
        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return response()->download($path, 'chargen.xlsx')->deleteFileAfterSend(true);
    }

    public function importForm(): View
    {
        return view('pages.batches.excel-import');
    }

    public function import(Request $request, BatchExcelImportPlan $service): View|RedirectResponse
    {
        $data = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        try {
            $spreadsheet = IOFactory::load($data['excel_file']->getRealPath());
            try {
                $plan = $service->prepare($spreadsheet);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Die Excel-Datei konnte nicht gelesen werden. Bitte eine gültige Datei hochladen.');
        }

        $token = Str::random(48);
        Cache::put($this->previewKey($token), $plan, now()->addMinutes(30));

        return view('pages.batches.excel-import', [
            'preview' => $plan,
            'previewToken' => $token,
        ]);
    }

    public function confirm(Request $request, BatchExcelImportPlan $service): RedirectResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:48'],
            'selected_rows' => ['nullable', 'array'],
            'selected_rows.*' => ['integer', 'min:2'],
            'stock_confirmation' => ['nullable', 'string'],
        ]);

        $key = $this->previewKey($data['preview_token']);
        $plan = Cache::get($key);
        if (! is_array($plan)) {
            return redirect()->route('batches.excel.import.form')
                ->with('error', 'Die Vorschau ist abgelaufen. Bitte die Excel-Datei erneut hochladen.');
        }

        $lines = array_values(array_unique(array_map('intval', $data['selected_rows'] ?? [])));
        if (array_diff($lines, array_column($plan['rows'], 'line')) !== []) {
            return redirect()->route('batches.excel.import.form')
                ->with('error', 'Die Zeilenauswahl ist ungültig. Bitte eine neue Vorschau erstellen.');
        }

        $selected = array_filter($plan['rows'], fn (array $row) => in_array($row['line'], $lines, true));
        $stockChanges = array_filter($selected, fn (array $row) => $row['quantity_delta'] !== 0.0);
        if ($stockChanges !== [] && ($data['stock_confirmation'] ?? '') !== 'BESTAND') {
            return redirect()->route('batches.excel.import.form')
                ->with('error', 'Für Mengenänderungen bitte das Bestätigungswort BESTAND eingeben.');
        }

        try {
            $result = $service->apply($plan, $lines, auth()->id());
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return redirect()->route('batches.excel.import.form')
                ->with('error', $exception->validator->errors()->first('excel_file'));
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->route('batches.excel.import.form')
                ->with('error', 'Der Import wurde ohne Änderungen abgebrochen. Bitte das Serverprotokoll prüfen.');
        }

        Cache::forget($key);

        return redirect()->route('batches.index')->with('success',
            "Chargen-Excel-Import abgeschlossen: {$result['created']} neu, {$result['updated']} aktualisiert, {$result['movements']} Lagerbewegungen."
        );
    }

    private function previewKey(string $token): string
    {
        return 'batch-excel-import:' . auth()->id() . ':' . $token;
    }
}
