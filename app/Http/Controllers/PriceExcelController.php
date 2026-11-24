<?php

namespace App\Http\Controllers;

use App\Models\CustomerGroup;
use App\Models\ManualPriceRule;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Support\PriceExcelCompactWorkbook;
use App\Support\PriceExcelImportPlan;
use App\Support\PriceExcelStreamWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PriceExcelController extends Controller
{
    public function export(Request $request, PriceExcelImportPlan $service): BinaryFileResponse
    {
        return $this->workbook($request, $service, false);
    }

    public function template(Request $request, PriceExcelImportPlan $service): BinaryFileResponse
    {
        return $this->workbook($request, $service, true);
    }

    private function workbook(Request $request, PriceExcelImportPlan $service, bool $template): BinaryFileResponse
    {
        $scope = (string) $request->query('customer_group_id', 'all');
        $groupId = $service->groupId($scope);
        $groups = CustomerGroup::query()
            ->when($groupId !== null, fn ($query) => $query->whereKey($groupId))
            ->ordered()
            ->get();

        // Maximal 50 Produkte sowie deren aktuelle Preise gleichzeitig laden.
        // Der XLSX-Schreiber schreibt XML auf die Festplatte statt alle Zellen
        // als PhpSpreadsheet-Objekte im Arbeitsspeicher zu halten.
        $format = $request->validate(['format' => ['nullable', 'in:standard,compact']])['format'] ?? 'standard';
        $compact = $format === 'compact';
        $writer = $compact
            ? new PriceExcelCompactWorkbook(array_flip($groups->pluck('id')->all()))
            : new PriceExcelStreamWriter();

        Product::query()
            ->active()
            ->with('categories')
            ->chunkById(50, function ($products) use ($groups, $template, $writer): void {
                $productIds = $products->pluck('id');
                $groupIds = $groups->pluck('id');

                $tiers = ProductPriceTier::query()
                    ->whereIn('product_id', $productIds)
                    ->whereIn('customer_group_id', $groupIds)
                    ->orderBy('min_grams')
                    ->get()
                    ->groupBy(fn ($rule) => $rule->product_id . ':' . $rule->customer_group_id);

                $manualRules = ManualPriceRule::query()
                    ->whereIn('product_id', $productIds)
                    ->whereIn('customer_group_id', $groupIds)
                    ->orderBy('min_quantity')
                    ->get()
                    ->groupBy(fn ($rule) => $rule->product_id . ':' . $rule->customer_group_id);

                foreach ($products as $product) {
                    $append = function (array $values) use ($writer, $product): void {
                        if ($writer instanceof PriceExcelCompactWorkbook) {
                            $writer->append($values, (string) $product->unit);
                        } else {
                            $writer->append($values);
                        }
                    };
                    $mode = $product->usesPriceTiers() ? 'staffel' : 'manuell';

                    foreach ($groups as $group) {
                        $key = $product->id . ':' . $group->id;
                        $rules = $mode === 'staffel'
                            ? ($tiers[$key] ?? collect())
                            : ($manualRules[$key] ?? collect());

                        // Ohne vorhandene manuelle Regel eine ausfüllbare Standardzeile
                        // anbieten. Sie wird nur bei ausdruecklicher Preisangabe erstellt.
                        if ($mode === 'manuell' && $rules->isEmpty()) {
                            $append([
                                $product->name, $group->name, 'manuell', '',
                                1, null, $product->unitLabel('de'), null,
                                'ab 1 ' . $product->unitLabel('de'),
                                $product->id, $group->id, 0,
                            ]);
                        }

                        foreach ($rules as $rule) {
                            $append([
                                $product->name,
                                $group->name,
                                $mode,
                                $mode === 'staffel' ? $rule->tier_key : '',
                                $mode === 'staffel' ? $rule->min_grams : (float) $rule->min_quantity,
                                $mode === 'staffel' ? $rule->max_grams : ($rule->max_quantity !== null ? (float) $rule->max_quantity : null),
                                $product->unitLabel('de'),
                                $template ? null : number_format((float) $rule->price, 5, '.', ''),
                                $mode === 'staffel' ? $rule->tier_label : $rule->label,
                                $product->id,
                                $group->id,
                                $rule->id,
                            ]);
                        }
                    }
                }
            });

        $path = storage_path('app/preise-' . Str::uuid() . '.xlsx');
        $writer->finish($path);

        $suffix = $groupId === null ? 'alle-gruppen' : 'gruppe-' . $groupId;
        $name = ($template ? 'preisvorlage-' : 'preise-').($compact ? 'kompakt-' : '');

        return response()->download($path, $name . $suffix . '.xlsx')->deleteFileAfterSend(true);
    }

    public function importForm(): View
    {
        return view('pages.prices.excel-import', [
            'groups' => CustomerGroup::query()->ordered()->get(),
        ]);
    }

    public function import(Request $request, PriceExcelImportPlan $service): View|RedirectResponse
    {
        $data = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'customer_group_id' => ['required', 'string'],
        ]);

        $scope = (string) $data['customer_group_id'];
        $service->groupId($scope);

        try {
            // Nur das benoetigte Preisblatt ohne Formatobjekte laden.
            // Das Anleitung-Blatt wird fuer die Vorschau nicht gebraucht.
            $path = $data['excel_file']->getRealPath();
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $names = $reader->listWorksheetNames($path);
            $compact = ! in_array('Preise', $names, true) && in_array('Anleitung', $names, true);
            if ($compact) {
                $sheets = array_values(array_diff($names, ['Anleitung']));
                $cells = 0;
                foreach ($reader->listWorksheetInfo($path) as $info) {
                    if (in_array($info['worksheetName'], $sheets, true)) {
                        $cells += $info['totalRows'] * $info['totalColumns'];
                    }
                }
                if ($cells > 210000 || count($sheets) > 100) {
                    throw ValidationException::withMessages(['excel_file' => 'Die kompakte Preisdatei ist zu groß. Bitte eine einzelne Kundengruppe exportieren.']);
                }
                $reader->setLoadSheetsOnly($sheets);
            } else {
                $reader->setLoadSheetsOnly(['Preise']);
            }
            $spreadsheet = $reader->load($path);
            try {
                if ($compact) {
                    [$rows, $sources] = PriceExcelCompactWorkbook::readRows($spreadsheet);
                    $spreadsheet->disconnectWorksheets();
                    $preview = $service->prepareCells(
                        count($rows) + 1,
                        fn (int $column, int $line) => $rows[$line - 2][$column - 1],
                        $scope
                    );
                    foreach ($preview['rows'] as &$row) {
                        $row['source_cell'] = $sources[$row['line']];
                    }
                    unset($row);
                } else {
                    $preview = $service->prepare($spreadsheet, $scope);
                }
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            return back()->with('error', 'Die Excel-Datei konnte nicht gelesen werden. Bitte eine gültige Vorlage hochladen.');
        }

        $token = Str::random(48);
        Cache::put($this->previewKey($token), $preview, now()->addMinutes(30));

        return view('pages.prices.excel-import', [
            'groups' => CustomerGroup::query()->ordered()->get(),
            'preview' => $preview,
            'previewToken' => $token,
        ]);
    }

    public function confirm(Request $request, PriceExcelImportPlan $service): RedirectResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:48'],
            'selected_rows' => ['nullable', 'array'],
            'selected_rows.*' => ['integer', 'min:2'],
        ]);

        $key = $this->previewKey($data['preview_token']);
        $plan = Cache::get($key);
        if (! is_array($plan)) {
            return redirect()->route('prices.excel.import.form')
                ->with('error', 'Die Vorschau ist abgelaufen. Bitte Excel-Datei neu hochladen.');
        }

        $lines = array_values(array_unique(array_map('intval', $data['selected_rows'] ?? [])));
        if (count($lines) > 100) {
            return redirect()->route('prices.excel.import.form')
                ->with('error', 'Für große Importe bitte die neue Importvorschau mit Paketverarbeitung verwenden.');
        }
        try {
            $count = $service->apply($plan, $lines);
        } catch (ValidationException $exception) {
            return redirect()->route('prices.excel.import.form')
                ->with('error', $exception->validator->errors()->first('excel_file'));
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->route('prices.excel.import.form')
                ->with('error', 'Keine Preise übernommen. Bitte das Fehlerprotokoll prüfen.');
        }

        Cache::forget($key);

        return redirect()->route('prices.index')->with('success', "Excel-Preisimport abgeschlossen: {$count} Preise aktualisiert.");
    }

    /**
     * Startet den Import ohne Tausende POST-Checkboxen (max_input_vars).
     * Im Formular werden nur die abgewählten Zeilen übertragen.
     */
    public function begin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:48'],
            'excluded_rows_csv' => ['nullable', 'string', 'max:400000', 'regex:/^\\d+(?:,\\d+)*$/D'],
        ]);

        $token = $data['preview_token'];

        return Cache::lock($this->progressKey($token) . ':lock', 30)->block(10, function () use ($data, $token): JsonResponse {
            $state = Cache::get($this->progressKey($token));
            if (is_array($state)) {
                return response()->json($this->progressData($state));
            }

            $plan = Cache::get($this->previewKey($token));
            if (! is_array($plan)) {
                return response()->json(['message' => 'Die Vorschau ist abgelaufen. Bitte die Excel-Datei erneut hochladen.'], 410);
            }

            $all = array_column($plan['rows'], 'line');
            $excluded = trim((string) ($data['excluded_rows_csv'] ?? '')) === ''
                ? []
                : array_values(array_unique(array_map('intval', explode(',', $data['excluded_rows_csv']))));

            if (array_diff($excluded, $all) !== []) {
                return response()->json(['message' => 'Ungültige Zeilenauswahl. Bitte eine neue Vorschau erstellen.'], 422);
            }

            $lines = array_values(array_diff($all, $excluded));
            if ($lines === []) {
                return response()->json(['message' => 'Bitte mindestens einen Preis auswählen.'], 422);
            }

            $state = ['lines' => $lines, 'next' => 0, 'total' => count($lines)];
            Cache::put($this->progressKey($token), $state, now()->addMinutes(60));

            return response()->json($this->progressData($state));
        });
    }

    /**
     * Pro HTTP-Anfrage maximal 100 Preise. Nach einem Verbindungsabbruch
     * kann der Browser ab dem zuletzt bestaetigten Paket fortfahren.
     */
    public function batch(Request $request, PriceExcelImportPlan $service): JsonResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:48'],
        ]);

        $token = $data['preview_token'];

        return Cache::lock($this->progressKey($token) . ':lock', 30)->block(10, function () use ($token, $service): JsonResponse {
            $state = Cache::get($this->progressKey($token));
            if (! is_array($state)) {
                return response()->json(['message' => 'Der Import wurde noch nicht gestartet oder ist abgelaufen.'], 410);
            }

            if ($state['next'] >= $state['total']) {
                return response()->json($this->progressData($state));
            }

            $plan = Cache::get($this->previewKey($token));
            if (! is_array($plan)) {
                return response()->json(['message' => 'Die Importvorschau ist abgelaufen. Bisher übernommene Preise bleiben gespeichert.'], 410);
            }

            $lines = array_slice($state['lines'], $state['next'], 100);
            try {
                $service->apply($plan, $lines);
            } catch (ValidationException $exception) {
                return response()->json([
                    'message' => $exception->validator->errors()->first('excel_file'),
                    ...$this->progressData($state),
                ], 422);
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Fehler beim Speichern dieses Pakets. Vorherige Pakete bleiben gespeichert; bitte das Serverprotokoll prüfen.',
                    ...$this->progressData($state),
                ], 500);
            }

            $state['next'] += count($lines);
            Cache::put($this->progressKey($token), $state, now()->addMinutes(60));

            if ($state['next'] === $state['total']) {
                Cache::forget($this->previewKey($token));
                session()->flash('success', "Excel-Preisimport abgeschlossen: {$state['total']} Preise übernommen.");
            } else {
                Cache::put($this->previewKey($token), $plan, now()->addMinutes(30));
            }

            return response()->json($this->progressData($state));
        });
    }

    private function progressData(array $state): array
    {
        return [
            'processed' => $state['next'],
            'total' => $state['total'],
            'done' => $state['next'] >= $state['total'],
        ];
    }

    private function progressKey(string $token): string
    {
        return $this->previewKey($token) . ':progress';
    }

    private function previewKey(string $token): string
    {
        return 'price-excel-import:' . auth()->id() . ':' . $token;
    }
}
