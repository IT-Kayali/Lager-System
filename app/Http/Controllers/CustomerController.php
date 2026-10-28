<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Offer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $group = trim((string) $request->query('group'));
        $searchField = (string) $request->query('search_field', 'all');
        $exact = $request->boolean('exact');

        $allowedSearchFields = ['all', 'number', 'name', 'email', 'phone', 'city', 'vat'];
        if (! in_array($searchField, $allowedSearchFields, true)) {
            $searchField = 'all';
        }

        $customers = Customer::query()
            ->with('group')
            ->when($search !== '', function ($query) use ($search, $searchField, $exact) {
                $operator = $exact ? '=' : 'like';
                $value = $exact ? $search : "%{$search}%";

                $query->where(function ($subQuery) use ($searchField, $operator, $value) {
                    match ($searchField) {
                        'number' => $subQuery->where('customer_number', $operator, $value),
                        'name' => $subQuery->where('company_name', $operator, $value),
                        'email' => $subQuery->where('email', $operator, $value),
                        'phone' => $subQuery->where('phone', $operator, $value),
                        'city' => $subQuery
                            ->where('city', $operator, $value)
                            ->orWhere('billing_city', $operator, $value)
                            ->orWhere('delivery_city', $operator, $value),
                        'vat' => $subQuery->where('vat_number', $operator, $value),
                        default => $subQuery
                            ->where('customer_number', $operator, $value)
                            ->orWhere('company_name', $operator, $value)
                            ->orWhere('email', $operator, $value)
                            ->orWhere('phone', $operator, $value)
                            ->orWhere('city', $operator, $value)
                            ->orWhere('billing_city', $operator, $value)
                            ->orWhere('delivery_city', $operator, $value)
                            ->orWhere('vat_number', $operator, $value),
                    };
                });
            })
            ->when($group !== '', function ($query) use ($group) {
                $query->whereHas('group', fn ($groupQuery) => $groupQuery->where('slug', $group));
            })
            ->orderByRaw('LOWER(company_name) ASC')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('pages.customers.index', [
            'customers' => $customers,
            'groups' => $this->groups(),
            'search' => $search,
            'searchField' => $searchField,
            'exact' => $exact,
            'selectedGroup' => $group,
        ]);
    }

    public function show(Request $request, Customer $customer): View
    {
        $customer->load('group');

        $offers = Offer::query()
            ->withCount('items')
            ->where('customer_id', $customer->id)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $walletTransactions = $customer->walletTransactions()
            ->with(['user', 'offer'])
            ->latest()
            ->paginate(15, ['*'], 'wallet_page')
            ->withQueryString();

        $currentYear = (int) now()->format('Y');
        $customerCreatedYear = (int) ($customer->created_at?->format('Y') ?: $currentYear);
        $firstRevenueYear = min($customerCreatedYear, $currentYear);
        $revenueYears = range($firstRevenueYear, $currentYear);

        $requestedRevenueYear = (string) $request->query('revenue_year', 'all');
        $selectedRevenueYear = 'all';

        if (ctype_digit($requestedRevenueYear)) {
            $year = (int) $requestedRevenueYear;

            if (in_array($year, $revenueYears, true)) {
                $selectedRevenueYear = $year;
            }
        }

        $completedOffers = Offer::query()
            ->with('items')
            ->where('customer_id', $customer->id)
            ->where('status', Offer::STATUS_COMPLETED)
            ->get();

        $revenueOffers = $completedOffers
            ->filter(function (Offer $offer) use ($selectedRevenueYear): bool {
                if ($selectedRevenueYear === 'all') {
                    return true;
                }

                $saleDate = $offer->completed_at ?: $offer->created_at;

                return $saleDate && (int) $saleDate->format('Y') === $selectedRevenueYear;
            })
            ->values();

        $revenueItems = $revenueOffers
            ->flatMap(fn (Offer $offer) => $offer->items)
            ->values();

        $revenueProductSales = $revenueItems
            ->groupBy('product_id')
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'product_id' => $first->product_id,
                    'product_code' => $first->product_code,
                    'product_name' => $first->product_name,
                    'unit' => $first->unit,
                    'quantity' => (float) $items->sum('quantity'),
                    'revenue' => (float) $items->sum('line_total'),
                    'sales_count' => $items->pluck('offer_id')->unique()->count(),
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        $revenueQuantitySummary = $revenueItems
            ->groupBy(fn ($item) => trim((string) $item->unit) ?: 'unit')
            ->map(fn ($items, $unit) => [
                'unit' => (string) $unit,
                'quantity' => (float) $items->sum('quantity'),
            ])
            ->values();

        return view('pages.customers.show', [
            'customer' => $customer,
            'offers' => $offers,
            'walletTransactions' => $walletTransactions,
            'walletBalance' => $customer->wallet_balance,
            'statusLabels' => Offer::STATUS_LABELS,
            'revenueYears' => $revenueYears,
            'selectedRevenueYear' => $selectedRevenueYear,
            'customerRevenue' => (float) $revenueItems->sum('line_total'),
            'completedSalesCount' => $revenueOffers->count(),
            'revenueProductSales' => $revenueProductSales,
            'revenueProductCount' => $revenueProductSales->count(),
            'revenueQuantitySummary' => $revenueQuantitySummary,
        ]);
    }

    public function create(): View
    {
        return view('pages.customers.create', [
            'customer' => new Customer(),
            'groups' => $this->groups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Customer::create($this->validatedData($request));

        return redirect()
            ->route('customers.index')
            ->with('success', 'Kunde wurde erfolgreich angelegt.');
    }

    public function edit(Customer $customer): View
    {
        return view('pages.customers.edit', [
            'customer' => $customer,
            'groups' => $this->groups(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validatedData($request, $customer));

        return redirect()
            ->route('customers.index')
            ->with('success', 'Kunde wurde erfolgreich aktualisiert.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if (Schema::hasTable('offers')) {
            $usedInOffers = \DB::table('offers')
                ->where('customer_id', $customer->id)
                ->exists();

            if ($usedInOffers) {
                return redirect()
                    ->route('customers.index')
                    ->with('error', 'Kunde kann nicht gelöscht werden, weil er bereits in Angeboten verwendet wird.');
            }
        }

        $customer->delete();

        return redirect()
            ->route('customers.index')
            ->with('success', 'Kunde wurde gelöscht.');
    }

    private function validatedData(Request $request, ?Customer $customerForUrl = null): array
    {
        $data = $request->validate([
            'customer_group_id' => ['required', 'integer', 'exists:customer_groups,id'],
            'company_name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($customerForUrl): void {
                    $slug = \Illuminate\Support\Str::slug((string) $value);

                    if ($slug === '' || $slug === 'create') {
                        $fail('Dieser Name kann nicht als eindeutiger Link verwendet werden.');
                        return;
                    }

                    $collision = Customer::query()
                        ->when(
                            $customerForUrl !== null,
                            fn ($query) => $query->where('id', '!=', $customerForUrl?->id)
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
            'email' => ['nullable', 'email', 'max:255'],
            'phone_country_code' => ['required', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'billing_street' => ['nullable', 'string', 'max:255'],
            'billing_house_number' => ['nullable', 'string', 'max:50'],
            'billing_postal_code' => ['nullable', 'string', 'max:50'],
            'billing_city' => ['nullable', 'string', 'max:255'],
            'billing_country' => ['nullable', 'string', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:3000'],
            'delivery_address_different' => ['nullable', 'boolean'],
            'delivery_street' => ['nullable', 'string', 'max:255'],
            'delivery_house_number' => ['nullable', 'string', 'max:50'],
            'delivery_postal_code' => ['nullable', 'string', 'max:50'],
            'delivery_city' => ['nullable', 'string', 'max:255'],
            'delivery_country' => ['nullable', 'string', 'max:255'],
            'delivery_address' => ['nullable', 'string', 'max:3000'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'delivery_note_instruction' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (! $request->boolean('delivery_address_different')) {
            $data['delivery_street'] = $data['billing_street'] ?? null;
            $data['delivery_house_number'] = $data['billing_house_number'] ?? null;
            $data['delivery_postal_code'] = $data['billing_postal_code'] ?? null;
            $data['delivery_city'] = $data['billing_city'] ?? null;
            $data['delivery_country'] = $data['billing_country'] ?? null;
            $data['delivery_address'] = $data['billing_address'] ?? null;
        }

        unset($data['delivery_address_different']);

        return $data;
    }

    private function groups()
    {
        return CustomerGroup::query()
            ->ordered()
            ->get();
    }
}
