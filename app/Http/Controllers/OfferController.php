<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApplicationSetting;
use App\Models\BranchWithdrawal;
use App\Models\Customer;
use App\Models\ManualPriceRule;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductPriceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OfferController extends Controller
{
    public function index(Request $request): View
    {
        app(\App\Services\ReservationReleaseService::class)->releaseExpired();

        $search = trim((string) $request->query('search'));
        $status = trim((string) $request->query('status'));
        $searchField = (string) $request->query('search_field', 'all');
        $exact = $request->boolean('exact');

        $allowedSearchFields = ['all', 'number', 'customer', 'customer_number', 'product'];
        if (! in_array($searchField, $allowedSearchFields, true)) {
            $searchField = 'all';
        }

        $offers = Offer::query()
            ->with(['customer.group', 'items'])
            ->when($search !== '', function ($query) use ($search, $searchField, $exact) {
                $operator = $exact ? '=' : 'like';
                $value = $exact ? $search : "%{$search}%";

                $query->where(function ($subQuery) use ($searchField, $operator, $value) {
                    switch ($searchField) {
                        case 'number':
                            $subQuery->where('offer_number', $operator, $value);
                            break;

                        case 'customer':
                            $subQuery->whereHas('customer', fn ($customerQuery) => $customerQuery->where('company_name', $operator, $value));
                            break;

                        case 'customer_number':
                            $subQuery->whereHas('customer', fn ($customerQuery) => $customerQuery->where('customer_number', $operator, $value));
                            break;

                        case 'product':
                            $subQuery->whereHas('items', fn ($itemQuery) => $itemQuery->where('product_name', $operator, $value));
                            break;

                        default:
                            $subQuery
                                ->where('offer_number', $operator, $value)
                                ->orWhereHas('customer', function ($customerQuery) use ($operator, $value) {
                                    $customerQuery
                                        ->where('customer_number', $operator, $value)
                                        ->orWhere('company_name', $operator, $value);
                                })
                                ->orWhereHas('items', fn ($itemQuery) => $itemQuery->where('product_name', $operator, $value));
                            break;
                    }
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('pages.offers.index', [
            'offers' => $offers,
            'search' => $search,
            'searchField' => $searchField,
            'exact' => $exact,
            'selectedStatus' => $status,
            'statuses' => $this->statuses(),
        ]);
    }

    public function show(Offer $offer): View { $offer->load(['customer.group', 'items.product', 'internalNotes.user']); return view('pages.offers.show', compact('offer')); }
    public function create(): View { return view('pages.offers.create', ['offer' => new Offer(), 'customers' => $this->customers(), 'products' => $this->products(), 'templates' => $this->templates(), 'formItems' => collect([['product_id' => '', 'quantity' => '', 'line_total' => '']])]); }

    public function pricePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:5000'],
        ]);

        $customer = Customer::with('group')->findOrFail($data['customer_id']);
        $product = Product::query()->with(['categories', 'priceTiers', 'manualPriceRules'])->findOrFail($data['product_id']);
        $quantity = (float) $data['quantity'];
        $pricing = $this->resolvePrice($product, $customer, $quantity);
        $lineTotal = round($quantity * (float) $pricing['price'], 2);

        return response()->json([
            'unit_price' => round((float) $pricing['price'], 2),
            'line_total' => $lineTotal,
            'tier_label' => $pricing['label'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->normalizeShippingData($this->validatedData($request)); $customer = Customer::with('group')->findOrFail($data['customer_id']); $cleanItems = $this->cleanItems($data['items']);
        if ($cleanItems->isEmpty()) return back()->withInput()->with('error', 'Bitte mindestens eine Produktposition mit einer Menge größer als 0 hinzufügen.');
        $prepared = $this->prepareItems($customer, $cleanItems); $total = collect($prepared)->sum('line_total');
        $offer = DB::transaction(function () use ($customer, $data, $prepared, $total) { $offer = Offer::create(['customer_id' => $customer->id, 'user_id' => auth()->id(), 'status' => Offer::STATUS_OFFER, 'template_type' => $data['template_type'], 'shipping_method' => $data['shipping_method'] ?? null, 'shipping_price_gross' => $data['shipping_price_gross'] ?? null, 'carton_count' => $data['carton_count'] ?? null, 'document_type' => 'offer', 'subtotal' => $total, 'total' => $total, 'reserved_until' => now()->addHours(ApplicationSetting::reservationHours()), 'notes' => $data['notes'] ?? null]); $this->syncOfferItems($offer, $prepared); ActivityLog::record('offer.created', $offer, ['offer_number' => $offer->offer_number, 'customer' => $customer->company_name, 'total' => $total]); return $offer; });
        return redirect()->route('offers.show', $offer)->with('success', 'Angebot ' . $offer->offer_number . ' wurde erstellt und Ware wurde reserviert.');
    }

    public function edit(Offer $offer): View|RedirectResponse
    {
        if (! $this->canEdit($offer)) {
            return redirect()
                ->route('offers.show', $offer)
                ->with(
                    'error',
                    auth()->user()?->isSales() && $offer->status !== Offer::STATUS_OFFER
                        ? 'Das Angebot wurde bereits an das Lager übergeben und kann vom Verkauf nicht mehr bearbeitet werden.'
                        : 'Erledigte, stornierte oder abgelaufene Angebote können nicht bearbeitet werden.'
                );
        }

        $offer->load('items');

        return view('pages.offers.edit', [
            'offer' => $offer,
            'customers' => $this->customers(),
            'products' => $this->products(),
            'templates' => $this->templates(),
            'formItems' => $offer->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ])->values(),
        ]);
    }

    public function update(Request $request, Offer $offer): RedirectResponse
    {
        if (! $this->canEdit($offer)) {
            return redirect()
                ->route('offers.show', $offer)
                ->with(
                    'error',
                    auth()->user()?->isSales() && $offer->status !== Offer::STATUS_OFFER
                        ? 'Das Angebot wurde bereits an das Lager übergeben und kann vom Verkauf nicht mehr bearbeitet werden.'
                        : 'Dieses Angebot kann nicht mehr bearbeitet werden.'
                );
        }
        $data = $this->normalizeShippingData($this->validatedData($request)); $customer = Customer::with('group')->findOrFail($data['customer_id']); $cleanItems = $this->cleanItems($data['items']);
        if ($cleanItems->isEmpty()) return back()->withInput()->with('error', 'Bitte mindestens eine Produktposition mit einer Menge größer als 0 hinzufügen.');
        $prepared = $this->prepareItems($customer, $cleanItems, $offer); $total = collect($prepared)->sum('line_total');
        DB::transaction(function () use ($offer, $customer, $data, $prepared, $total) { $offer->update(['customer_id' => $customer->id, 'template_type' => $data['template_type'], 'shipping_method' => $data['shipping_method'] ?? null, 'shipping_price_gross' => $data['shipping_price_gross'] ?? null, 'carton_count' => $data['carton_count'] ?? null, 'subtotal' => $total, 'total' => $total, 'notes' => $data['notes'] ?? null]); $offer->items()->delete(); $this->syncOfferItems($offer, $prepared); ActivityLog::record('offer.updated', $offer, ['offer_number' => $offer->offer_number, 'total' => $total]); });
        return redirect()->route('offers.show', $offer)->with('success', 'Angebot wurde aktualisiert.');
    }

    public function updateStatus(Request $request, Offer $offer): RedirectResponse { $data = $request->validate(['status' => ['required', 'string', 'in:' . implode(',', array_keys($this->statuses()))]]); $oldStatus = $offer->status; $offer->update(['status' => $data['status']]); ActivityLog::record('offer.status.updated', $offer, ['offer_number' => $offer->offer_number, 'old_status' => $oldStatus, 'new_status' => $offer->status]); return redirect()->route('offers.show', $offer)->with('success', 'Status wurde geändert.'); }
    public function cancel(Offer $offer): RedirectResponse
    {
        if (auth()->user()?->isSales() && $offer->status !== Offer::STATUS_OFFER) {
            return redirect()
                ->route('offers.index')
                ->with('error', 'Das Angebot wurde bereits an das Lager übergeben und kann vom Verkauf nicht mehr storniert werden.');
        }

        if (! $offer->isReservationActive()) {
            return redirect()->route('offers.index')->with('error', 'Dieses Angebot kann nicht storniert werden.');
        }

        $offer->update(['status' => Offer::STATUS_CANCELLED]);
        ActivityLog::record('offer.cancelled', $offer, ['offer_number' => $offer->offer_number]);

        return redirect()->route('offers.index')->with('success', 'Angebot wurde storniert. Die Reservierung wurde freigegeben.');
    }

    public function destroy(Offer $offer): RedirectResponse
    {
        if ($offer->status === Offer::STATUS_COMPLETED) { $hasFifoMovements = \App\Models\StockMovement::query()->where('reference_type', Offer::class)->where('reference_id', $offer->id)->exists(); if ($hasFifoMovements) return redirect()->route('offers.index')->with('error', 'Erledigte Angebote mit FIFO-Abbuchung können nicht gelöscht werden. Bitte als Nachweis behalten.'); }
        $number = $offer->offer_number; $offer->items()->delete(); $offer->delete(); ActivityLog::record('offer.deleted', null, ['offer_number' => $number]); return redirect()->route('offers.index')->with('success', 'Angebot wurde gelöscht.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate(['customer_id' => ['required', 'exists:customers,id'], 'template_type' => ['required', 'in:with_company,without_company'], 'shipping_method' => ['nullable', 'string', 'max:255'], 'shipping_price_gross' => ['nullable', 'numeric', 'min:0'], 'carton_count' => ['nullable', 'required_if:shipping_method,Lieferung', 'integer', 'min:1', 'max:9999'], 'notes' => ['nullable', 'string'], 'items' => ['required', 'array'], 'items.*.product_id' => ['nullable', 'exists:products,id'], 'items.*.quantity' => ['nullable', 'numeric', 'min:0'], 'items.*.line_total' => ['nullable', 'numeric', 'min:0', 'max:999999999.99']]);
    }

    private function cleanItems(array $items): Collection { return collect($items)->filter(fn ($item) => ! empty($item['product_id']) && (float) ($item['quantity'] ?? 0) > 0)->values(); }

    private function prepareItems(Customer $customer, Collection $items, ?Offer $editingOffer = null): array
    {
        $products = Product::query()->with(['categories', 'priceTiers', 'manualPriceRules'])->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id'); $prepared = [];
        foreach ($items as $item) {
            $product = $products->get((int) $item['product_id']); $quantity = (float) $item['quantity']; if (! $product) continue;
            $reservedElsewhere = $this->reservedQuantityForProduct($product->id, $editingOffer?->id); $available = max(0, (float) $product->total_stock - $reservedElsewhere);
            if ($quantity > $available) {
                throw ValidationException::withMessages(['items' => "Für {$product->name} sind nur {$available} {$product->unitLabel('de')} verfügbar. Bitte Menge anpassen."]);
            }
            $pricing = $this->resolvePrice($product, $customer, $quantity);
            $automaticTotal = round($quantity * (float) $pricing['price'], 2);
            $hasManualTotal = array_key_exists('line_total', $item) && $item['line_total'] !== null && $item['line_total'] !== '';
            $lineTotal = $hasManualTotal ? round((float) $item['line_total'], 2) : $automaticTotal;
            $effectiveUnitPrice = $quantity > 0 ? round($lineTotal / $quantity, 4) : (float) $pricing['price'];
            $prepared[] = ['product_id' => $product->id, 'product_name' => $product->name, 'product_code' => $product->product_code, 'quantity' => $quantity, 'unit' => $product->unit, 'tier_key' => $pricing['key'], 'tier_label' => $pricing['label'], 'unit_price' => $effectiveUnitPrice, 'line_total' => $lineTotal];
        } return $prepared;
    }

    private function resolvePrice(Product $product, Customer $customer, float $quantity): array
    {
        $groupId = $customer->customer_group_id; if (! $groupId) return ['key' => 'none', 'label' => 'Keine Kundengruppe', 'price' => 0.0];
        if (! $product->usesPriceTiers()) { $rule = ManualPriceRule::query()->where('product_id', $product->id)->where('customer_group_id', $groupId)->where('min_quantity', '<=', $quantity)->where(fn ($q) => $q->whereNull('max_quantity')->orWhere('max_quantity', '>=', $quantity))->orderByDesc('min_quantity')->first(); return $rule ? ['key' => 'manual:' . $rule->id, 'label' => $rule->rangeLabel($product->unitLabel('de')), 'price' => (float) $rule->price] : ['key' => 'manual:none', 'label' => 'Keine Preisregel', 'price' => 0.0]; }
        $tier = ProductPriceTier::query()->where('product_id', $product->id)->where('customer_group_id', $groupId)->where('min_grams', '<=', $quantity)->where('max_grams', '>=', $quantity)->orderByDesc('min_grams')->first(); return $tier ? ['key' => $tier->tier_key, 'label' => $tier->tier_label, 'price' => (float) $tier->price] : ['key' => 'none', 'label' => 'Keine Preisstufe', 'price' => 0.0];
    }

    private function syncOfferItems(Offer $offer, array $prepared): void { foreach ($prepared as $item) $offer->items()->create($item); }
    private function reservedQuantityForProduct(int $productId, ?int $excludeOfferId = null): float { $offerReserved = (float) \App\Models\OfferItem::query()->where('product_id', $productId)->whereHas('offer', fn ($q) => $q->whereIn('status', Offer::RESERVING_STATUSES)->when($excludeOfferId, fn ($q2) => $q2->where('id', '!=', $excludeOfferId)))->sum('quantity'); $branchReserved = (float) \App\Models\BranchWithdrawalItem::query()->where('product_id', $productId)->whereHas('withdrawal', fn ($q) => $q->whereIn('status', BranchWithdrawal::RESERVING_STATUSES))->sum('quantity'); return $offerReserved + $branchReserved; }
    private function canEdit(Offer $offer): bool
    {
        if ($offer->isFinal()) {
            return false;
        }

        if (auth()->user()?->isSales()) {
            return $offer->status === Offer::STATUS_OFFER;
        }

        return auth()->user()?->isManager() ?? false;
    }
    private function customers(): Collection { return Customer::query()->with('group')->orderByRaw('LOWER(company_name) ASC')->get(); }
    /**
     * Bestandsanzeige für die Angebotsformulare in Sammelabfragen berechnen.
     * Die zentrale Lagerlogik für Reservierungen und Buchungen bleibt unverändert.
     */
    private function products(): Collection
    {
        $products = Product::query()
            ->with(['categories', 'priceTiers', 'manualPriceRules'])
            ->naturalNameOrder()
            ->get();

        if ($products->isEmpty()) {
            return $products;
        }

        $totalStocks = DB::table('product_batches')
            ->select('product_id')
            ->selectRaw('SUM(quantity) AS total_quantity')
            ->groupBy('product_id')
            ->pluck('total_quantity', 'product_id');

        $offerReservations = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('offers')
            && \Illuminate\Support\Facades\Schema::hasTable('offer_items')) {
            $offerReservations = DB::table('offer_items')
                ->join('offers', 'offers.id', '=', 'offer_items.offer_id')
                ->whereIn('offers.status', Offer::RESERVING_STATUSES)
                ->select('offer_items.product_id')
                ->selectRaw('SUM(offer_items.quantity) AS reserved_quantity')
                ->groupBy('offer_items.product_id')
                ->pluck('reserved_quantity', 'offer_items.product_id');
        }

        $branchReservations = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('branch_withdrawals')
            && \Illuminate\Support\Facades\Schema::hasTable('branch_withdrawal_items')) {
            $branchReservations = DB::table('branch_withdrawal_items')
                ->join('branch_withdrawals', 'branch_withdrawals.id', '=', 'branch_withdrawal_items.branch_withdrawal_id')
                ->whereIn('branch_withdrawals.status', BranchWithdrawal::RESERVING_STATUSES)
                ->select('branch_withdrawal_items.product_id')
                ->selectRaw('SUM(branch_withdrawal_items.quantity) AS reserved_quantity')
                ->groupBy('branch_withdrawal_items.product_id')
                ->pluck('reserved_quantity', 'branch_withdrawal_items.product_id');
        }

        foreach ($products as $product) {
            $id = $product->getKey();
            $total = (float) $totalStocks->get($id, 0);
            $reserved = (float) $offerReservations->get($id, 0)
                + (float) $branchReservations->get($id, 0);

            $product->setAttribute(
                'offer_editor_available_stock',
                max(0.0, $total - $reserved)
            );
        }

        return $products;
    }
    private function templates(): array { return ['with_company' => 'Mit Firmendaten & Logo', 'without_company' => 'Ohne Firmendaten & Logo']; }
    private function statuses(): array { return Offer::STATUS_LABELS; }
    private function normalizeShippingData(array $data): array
    {
        $data['shipping_price_gross'] = isset($data['shipping_price_gross']) && $data['shipping_price_gross'] !== ''
            ? round((float) $data['shipping_price_gross'], 2)
            : null;

        if (($data['shipping_method'] ?? null) !== 'Lieferung') {
            $data['carton_count'] = null;
        } elseif (isset($data['carton_count'])) {
            $data['carton_count'] = (int) $data['carton_count'];
        }

        return $data;
    }
}
