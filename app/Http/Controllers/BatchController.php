<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BatchController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $searchField = (string) $request->query('search_field', 'all');
        $exact = $request->boolean('exact');
        $sort = trim((string) $request->query('sort'));
        $direction = strtolower(trim((string) $request->query('direction', 'asc')));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $allowedSearchFields = ['all', 'batch', 'product', 'code', 'manufacturer'];
        if (! in_array($searchField, $allowedSearchFields, true)) {
            $searchField = 'all';
        }

        $batches = ProductBatch::query()
            ->with('product')
            ->when($search !== '', function ($query) use ($search, $searchField, $exact) {
                $operator = $exact ? '=' : 'like';
                $value = $exact ? $search : "%{$search}%";

                $query->where(function ($subQuery) use ($searchField, $operator, $value) {
                    switch ($searchField) {
                        case 'batch':
                            $subQuery->where('batch_number', $operator, $value);
                            break;

                        case 'product':
                            $subQuery->whereHas('product', fn ($productQuery) => $productQuery->where('name', $operator, $value));
                            break;

                        case 'code':
                            $subQuery->whereHas('product', fn ($productQuery) => $productQuery
                                ->where('product_code', $operator, $value)
                                ->orWhere('serial_number', $operator, $value));
                            break;

                        case 'manufacturer':
                            $subQuery->whereHas('product', fn ($productQuery) => $productQuery->where('manufacturer_designation', $operator, $value));
                            break;

                        default:
                            $subQuery
                                ->where('batch_number', $operator, $value)
                                ->orWhereHas('product', function ($productQuery) use ($operator, $value) {
                                    $productQuery
                                        ->where('name', $operator, $value)
                                        ->orWhere('product_code', $operator, $value)
                                        ->orWhere('serial_number', $operator, $value)
                                        ->orWhere('manufacturer_designation', $operator, $value);
                                });
                            break;
                    }
                });
            })
            ->when(
                $sort === 'product',
                fn ($query) => $this->orderBatchesByProductName($query, $direction),
                fn ($query) => $query
                    ->orderBy('product_batches.received_at')
                    ->orderBy('product_batches.id')
            )
            ->paginate(20)
            ->withQueryString();

        return view('pages.batches.index', compact('batches', 'search', 'searchField', 'exact'));
    }

    public function create(Request $request): View
    {
        $productName = $request->query('produkt');

        if ($productName !== null) {
            abort_unless(is_string($productName) && $productName !== '', 404);

            $selectedProduct = Product::query()
                ->where('name', $productName)
                ->firstOrFail();
        } else {
            // Alte Links mit product_id bleiben funktionsfähig.
            $selectedProduct = Product::query()
                ->find($request->integer('product_id'));
        }
        $receivedAt = now()->startOfDay();
        $defaultExpiryMonths = ApplicationSetting::defaultBatchExpiryMonths();

        return view('pages.batches.create', [
            'batch' => new ProductBatch([
                'product_id' => $selectedProduct?->id,
                'received_at' => $receivedAt->toDateString(),
                'expires_at' => $receivedAt->copy()->addMonthsNoOverflow($defaultExpiryMonths)->toDateString(),
            ]),
            'products' => $this->products(),
            'selectedProductId' => $selectedProduct?->id,
            'defaultBatchExpiryMonths' => $defaultExpiryMonths,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data = $this->applyDefaultExpiry($data);

        DB::transaction(function () use ($data) {
            $batch = ProductBatch::create($data);

            StockMovement::create([
                'product_id' => $batch->product_id,
                'product_batch_id' => $batch->id,
                'user_id' => auth()->id(),
                'type' => 'in',
                'quantity' => $batch->quantity,
                'note' => 'Wareneingang / neue Charge erstellt',
            ]);
        });

        return redirect()
            ->route('batches.index')
            ->with('success', 'Charge wurde erfolgreich angelegt und Bestand gebucht.');
    }

    public function edit(ProductBatch $batch): View
    {
        return view('pages.batches.edit', [
            'batch' => $batch,
            'products' => $this->products(),
            'selectedProductId' => $batch->product_id,
            'defaultBatchExpiryMonths' => ApplicationSetting::defaultBatchExpiryMonths(),
        ]);
    }

    public function update(Request $request, ProductBatch $batch): RedirectResponse
    {
        $data = $this->validatedData($request, $batch);
        $data = $this->applyDefaultExpiry($data);

        DB::transaction(function () use ($batch, $data) {
            $oldQuantity = (float) $batch->quantity;

            $batch->update($data);

            $newQuantity = (float) $batch->quantity;
            $difference = $newQuantity - $oldQuantity;

            if (abs($difference) > 0.0001) {
                StockMovement::create([
                    'product_id' => $batch->product_id,
                    'product_batch_id' => $batch->id,
                    'user_id' => auth()->id(),
                    'type' => 'adjustment',
                    'quantity' => $difference,
                    'note' => 'Chargenmenge manuell angepasst',
                ]);
            }
        });

        return redirect()
            ->route('batches.index')
            ->with('success', 'Charge wurde erfolgreich aktualisiert.');
    }

    public function destroy(ProductBatch $batch): RedirectResponse
    {
        if ((float) $batch->quantity > 0) {
            return redirect()
                ->route('batches.index')
                ->with('error', 'Charge kann nur gelöscht werden, wenn die Menge 0 ist.');
        }

        $batch->delete();

        return redirect()
            ->route('batches.index')
            ->with('success', 'Leere Charge wurde gelöscht.');
    }

    public function fifoOutForm(): View
    {
        return view('pages.batches.fifo-out', [
            'products' => $this->products(),
        ]);
    }

    public function fifoOut(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999'],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $requestedQuantity = (float) $data['quantity'];

        if ($requestedQuantity > $product->total_stock) {
            return back()
                ->withInput()
                ->with('error', 'Nicht genug Bestand vorhanden. Aktueller Gesamtbestand: ' . \App\Support\GermanNumber::format($product->total_stock));
        }

        DB::transaction(function () use ($product, $requestedQuantity, $data) {
            $remaining = $requestedQuantity;

            // Das Ablaufdatum ist bewusst KEIN Auswahl- oder Sperrkriterium.
            // Auch abgelaufene Chargen bleiben verkaufbar und werden normal nach FIFO entnommen.
            $batches = ProductBatch::query()
                ->where('product_id', $product->id)
                ->where('quantity', '>', 0)
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $availableInBatch = (float) $batch->quantity;
                $deduct = min($availableInBatch, $remaining);

                $batch->update([
                    'quantity' => $availableInBatch - $deduct,
                ]);

                StockMovement::create([
                    'product_id' => $product->id,
                    'product_batch_id' => $batch->id,
                    'user_id' => auth()->id(),
                    'type' => 'out',
                    'quantity' => -$deduct,
                    'note' => $data['note'] ?: 'FIFO-Warenausgang',
                ]);

                $remaining -= $deduct;
            }

            if ($remaining > 0.0001) {
                throw new \RuntimeException('FIFO-Buchung konnte nicht vollständig abgeschlossen werden.');
            }
        });

        return redirect()
            ->route('batches.index')
            ->with('success', 'FIFO-Warenausgang wurde erfolgreich gebucht.');
    }

    private function validatedData(Request $request, ?ProductBatch $batch = null): array
    {
        return $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'batch_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('product_batches', 'batch_number')->ignore($batch?->id),
            ],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'received_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);
    }

    private function applyDefaultExpiry(array $data): array
    {
        if (! empty($data['expires_at'])) {
            return $data;
        }

        $receivedAt = Carbon::parse($data['received_at'])->startOfDay();
        $data['expires_at'] = $receivedAt
            ->copy()
            ->addMonthsNoOverflow(ApplicationSetting::defaultBatchExpiryMonths())
            ->toDateString();

        return $data;
    }

    private function orderBatchesByProductName($query, string $direction)
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $directionSql = strtoupper($direction);
        $driver = $query->getConnection()->getDriverName();

        $productNameSql = '(SELECT table_sort_product_name.name FROM products AS table_sort_product_name WHERE table_sort_product_name.id = product_batches.product_id LIMIT 1)';

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $query
                ->reorder()
                ->orderByRaw("CASE WHEN {$productNameSql} REGEXP '^[0-9]' THEN 0 ELSE 1 END {$directionSql}")
                ->orderByRaw("CASE WHEN {$productNameSql} REGEXP '^[0-9]' THEN CAST({$productNameSql} AS UNSIGNED) ELSE 0 END {$directionSql}")
                ->orderByRaw("LOWER({$productNameSql}) {$directionSql}")
                ->orderBy('product_batches.id', $direction);
        }

        if ($driver === 'pgsql') {
            return $query
                ->reorder()
                ->orderByRaw("CASE WHEN {$productNameSql} ~ '^[0-9]' THEN 0 ELSE 1 END {$directionSql}")
                ->orderByRaw("CASE WHEN {$productNameSql} ~ '^[0-9]' THEN CAST(SUBSTRING({$productNameSql} FROM '^[0-9]+') AS BIGINT) ELSE 0 END {$directionSql}")
                ->orderByRaw("LOWER({$productNameSql}) {$directionSql}")
                ->orderBy('product_batches.id', $direction);
        }

        return $query
            ->reorder()
            ->orderByRaw("CASE WHEN {$productNameSql} GLOB '[0-9]*' THEN 0 ELSE 1 END {$directionSql}")
            ->orderByRaw("CASE WHEN {$productNameSql} GLOB '[0-9]*' THEN CAST({$productNameSql} AS INTEGER) ELSE 0 END {$directionSql}")
            ->orderByRaw("LOWER({$productNameSql}) {$directionSql}")
            ->orderBy('product_batches.id', $direction);
    }

    private function products()
    {
        return Product::query()
            ->orderBy('name')
            ->get(['id', 'product_code', 'name', 'unit']);
    }
}
