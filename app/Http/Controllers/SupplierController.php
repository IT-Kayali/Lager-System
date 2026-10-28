<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Supplier;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $searchField = (string) $request->query('search_field', 'all');
        $exact = $request->boolean('exact');

        $allowedSearchFields = ['all', 'number', 'name', 'contact', 'email', 'city'];
        if (! in_array($searchField, $allowedSearchFields, true)) {
            $searchField = 'all';
        }

        $suppliers = Supplier::query()
            ->withCount('products')
            ->when($search !== '', function ($query) use ($search, $searchField, $exact) {
                $operator = $exact ? '=' : 'like';
                $value = $exact ? $search : "%{$search}%";

                $query->where(function ($subQuery) use ($searchField, $operator, $value) {
                    match ($searchField) {
                        'number' => $subQuery->where('supplier_number', $operator, $value),
                        'name' => $subQuery->where('company_name', $operator, $value),
                        'contact' => $subQuery->where('contact_person', $operator, $value),
                        'email' => $subQuery->where('email', $operator, $value),
                        'city' => $subQuery->where('city', $operator, $value),
                        default => $subQuery
                            ->where('supplier_number', $operator, $value)
                            ->orWhere('company_name', $operator, $value)
                            ->orWhere('contact_person', $operator, $value)
                            ->orWhere('email', $operator, $value)
                            ->orWhere('city', $operator, $value),
                    };
                });
            })
            ->orderByRaw('LOWER(company_name) ASC')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('pages.suppliers.index', compact('suppliers', 'search', 'searchField', 'exact'));
    }

    public function create(): View
    {
        return view('pages.suppliers.create', [
            'supplier' => new Supplier([
                'country' => 'Deutschland',
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::create($this->validatedData($request));

        ActivityLog::record('supplier.created', $supplier, [
            'supplier_number' => $supplier->supplier_number,
            'company_name' => $supplier->company_name,
        ]);

        return redirect()
            ->route('suppliers.index')
            ->with('success', 'Lieferant wurde erstellt.');
    }

    public function show(Supplier $supplier): View
    {
        $products = Product::query()
            ->with(['categories', 'batches', 'supplierRecord'])
            ->where(function ($query) use ($supplier) {
                $query->where('supplier_id', $supplier->id)
                    ->orWhere('supplier', $supplier->company_name)
                    ->orWhere('supplier', $supplier->supplier_number);
            })
            ->naturalNameOrder()
            ->get();

        return view('pages.suppliers.show', [
            'supplier' => $supplier,
            'products' => $products,
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('pages.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validatedData($request, $supplier));

        ActivityLog::record('supplier.updated', $supplier, [
            'supplier_number' => $supplier->supplier_number,
            'company_name' => $supplier->company_name,
        ]);

        return redirect()
            ->route('suppliers.index')
            ->with('success', 'Lieferant wurde aktualisiert.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->products()->exists()) {
            return redirect()
                ->route('suppliers.index')
                ->with('error', 'Lieferant kann nicht gelöscht werden, weil Produkte zugeordnet sind.');
        }

        ActivityLog::record('supplier.deleted', $supplier, [
            'supplier_number' => $supplier->supplier_number,
            'company_name' => $supplier->company_name,
        ]);

        $supplier->delete();

        return redirect()
            ->route('suppliers.index')
            ->with('success', 'Lieferant wurde gelöscht.');
    }

    private function validatedData(Request $request, ?Supplier $supplierForUrl = null): array
    {
        return $request->validate([
            'company_name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($supplierForUrl): void {
                    $slug = \Illuminate\Support\Str::slug((string) $value);

                    if ($slug === '' || $slug === 'create') {
                        $fail('Dieser Name kann nicht als eindeutiger Link verwendet werden.');
                        return;
                    }

                    $collision = Supplier::query()
                        ->when(
                            $supplierForUrl !== null,
                            fn ($query) => $query->where('id', '!=', $supplierForUrl?->id)
                        )
                        ->pluck('company_name')
                        ->contains(
                            fn ($existing) =>
                                \Illuminate\Support\Str::slug(
                                    (string) $existing
                                ) === $slug
                        );

                    if ($collision) {
                        $fail('Dieser Name erzeugt denselben Link wie ein vorhandener Eintrag.');
                    }
                },
            ],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone_country_code' => ['required', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:255'],
            'whatsapp_country_code' => ['nullable', 'string', 'max:10'],
            'whatsapp' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }
}
