<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Services\ReservationReleaseService;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        app(\App\Services\ReservationReleaseService::class)->releaseExpired();

        $search = trim((string) $request->query('search'));
        $searchField = (string) $request->query('search_field', 'all');
        $exact = $request->boolean('exact');
        $sort = trim((string) $request->query('sort'));
        $direction = strtolower(trim((string) $request->query('direction', 'asc')));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $allowedSearchFields = ['all', 'name', 'manufacturer', 'code', 'supplier'];
        if (! in_array($searchField, $allowedSearchFields, true)) {
            $searchField = 'all';
        }

        $products = Product::query()
            ->active()
            ->with('categories')
            ->with('supplierRecord')
            ->with(['batches' => fn ($query) => $query->orderBy('received_at')->orderBy('id')])
            ->when($search !== '', function ($query) use ($search, $searchField, $exact) {
                $operator = $exact ? '=' : 'like';
                $value = $exact ? $search : "%{$search}%";

                $query->where(function ($subQuery) use ($searchField, $operator, $value) {
                    switch ($searchField) {
                        case 'name':
                            $subQuery->where('name', $operator, $value);
                            break;

                        case 'manufacturer':
                            $subQuery->where('manufacturer_designation', $operator, $value);
                            break;

                        case 'code':
                            $subQuery
                                ->where('serial_number', $operator, $value)
                                ->orWhere('product_code', $operator, $value);
                            break;

                        case 'supplier':
                            $subQuery
                                ->where('supplier', $operator, $value)
                                ->orWhereHas('supplierRecord', fn ($supplierQuery) => $supplierQuery->where('company_name', $operator, $value));
                            break;

                        default:
                            $subQuery
                                ->where('name', $operator, $value)
                                ->orWhere('manufacturer_designation', $operator, $value)
                                ->orWhere('serial_number', $operator, $value)
                                ->orWhere('product_code', $operator, $value)
                                ->orWhere('supplier', $operator, $value)
                                ->orWhereHas('supplierRecord', fn ($supplierQuery) => $supplierQuery->where('company_name', $operator, $value));
                            break;
                    }
                });
            })
            ->when(
                $sort === 'name',
                fn ($query) => $query->naturalNameOrder($direction),
                fn ($query) => $query->naturalNameOrder()
            )
            ->paginate(15)
            ->withQueryString();

        return view('pages.products.index', compact('products', 'search', 'searchField', 'exact'));
    }

    public function show(Product $product): View
    {
        $product->load([
            'supplierRecord',
            'categories' => fn ($query) => $query->orderByRaw('LOWER(name) ASC')->orderBy('id'),
            'batches' => fn ($query) => $query
                ->orderBy('received_at')
                ->orderBy('id'),
        ]);

        return view('pages.products.show', [
            'product' => $product,
        ]);
    }

    public function create(): View
    {
        $product = new Product([
            'unit' => 'gram',
            'minimum_stock' => 0,
        ]);

        $suppliers = Supplier::query()
            ->orderByRaw('LOWER(company_name) ASC')
            ->orderBy('id')
            ->get();

        return view('pages.products.create', compact('product', 'suppliers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $initialBatchData = $this->validatedInitialBatchData($request);

        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);

        DB::transaction(function () use ($data, $categoryIds, $initialBatchData): void {
            $product = Product::create($data);
            $product->categories()->sync($categoryIds);

            $initialBatches = collect($initialBatchData['initial_batches'] ?? [])
                ->filter(fn ($row) => (float) ($row['quantity'] ?? 0) > 0)
                ->values();

            foreach ($initialBatches as $row) {
                $receivedAt = Carbon::parse(($row['received_at'] ?? null) ?: now()->toDateString())->startOfDay();
                $expiresAt = ($row['expires_at'] ?? null) ?: $receivedAt
                    ->copy()
                    ->addMonthsNoOverflow(ApplicationSetting::defaultBatchExpiryMonths())
                    ->toDateString();

                $batch = ProductBatch::create([
                    'product_id' => $product->id,
                    'batch_number' => ($row['batch_number'] ?? null) ?: null,
                    'quantity' => (float) ($row['quantity'] ?? 0),
                    'received_at' => $receivedAt->toDateString(),
                    'expires_at' => $expiresAt,
                ]);

                StockMovement::create([
                    'product_id' => $product->id,
                    'product_batch_id' => $batch->id,
                    'user_id' => auth()->id(),
                    'type' => 'in',
                    'quantity' => $batch->quantity,
                    'note' => 'Wareneingang / Charge beim Produkt angelegt',
                ]);
            }
        });

        return redirect()
            ->route('products.index')
            ->with('success', 'Produkt wurde erfolgreich angelegt.');
    }

    public function edit(Product $product): View
    {
        $suppliers = Supplier::query()
            ->orderByRaw('LOWER(company_name) ASC')
            ->orderBy('id')
            ->get();

        return view('pages.products.edit', compact('product', 'suppliers'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validatedData($request, $product);

        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);

        $product->update($data);
        $product->categories()->sync($categoryIds);

        return redirect()
            ->route('products.index')
            ->with('success', 'Produkt wurde erfolgreich aktualisiert.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        try {
            $product->delete();
        } catch (QueryException $exception) {
            if ($this->isForeignKeyConstraintViolation($exception)) {
                return redirect()
                    ->route('products.index')
                    ->with('error', 'Produkt kann nicht gelöscht werden, da es bereits in Angeboten, Lagerbewegungen oder anderen Vorgängen verwendet wurde. Historische Daten bleiben dadurch erhalten.');
            }

            throw $exception;
        }

        return redirect()
            ->route('products.index')
            ->with('success', 'Produkt wurde gelöscht.');
    }

    private function isForeignKeyConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' && in_array($driverCode, [1451, 1452], true);
    }

    private function validatedInitialBatchData(Request $request): array
    {
        return $request->validate([
            'initial_batches' => ['nullable', 'array'],
            'initial_batches.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'initial_batches.*.batch_number' => [
                'nullable',
                'string',
                'max:255',
                'distinct',
                Rule::unique('product_batches', 'batch_number'),
            ],
            'initial_batches.*.received_at' => ['nullable', 'date'],
            'initial_batches.*.expires_at' => ['nullable', 'date'],
        ]);
    }

    private function validatedData(Request $request, ?Product $product = null): array
    {
        $uniqueProductName = Rule::unique('products', 'name');

        if ($product) {
            $uniqueProductName->ignore($product->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $uniqueProductName],
            'manufacturer_designation' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', Rule::in(array_keys($this->units()))],
            'supplier' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:product_categories,id'],
        ], [
            'name.unique' => 'Ein Produkt mit derselben Produktbezeichnung ist bereits vorhanden.',
        ]);
    }

    private function units(): array
    {
        return [
            'gram' => 'Gramm',
            'liter' => 'Liter',
            'piece' => 'Stück',
        ];
    }
}
