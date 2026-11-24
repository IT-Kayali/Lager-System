<?php

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\DocumentTemplate;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\OfferFulfillmentService;
use App\Support\DeliveryNoteBatchNumber;

function batchPdfOffer(array $quantities): array
{
    $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
    test()->actingAs($user);
    $group = CustomerGroup::query()->create(['name' => 'PDF Batch Gruppe', 'slug' => 'pdf-batch-gruppe', 'color' => '#D4AD16']);
    $customer = Customer::query()->create(['customer_group_id' => $group->id, 'company_name' => 'PDF Batch Kunde']);
    $offer = Offer::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'status' => Offer::STATUS_READY,
        'template_type' => 'with_company',
        'document_type' => 'offer',
        'subtotal' => 10,
        'total' => 10,
        'notes' => 'Versandhinweis bleibt erhalten.',
    ]);

    $product = Product::query()->create(['name' => 'PDF Batch Produkt', 'unit' => 'gram', 'minimum_stock' => 0]);
    foreach ($quantities as $quantity) {
        $offer->items()->create([
            'product_id' => $product->id,
            'product_code' => $product->product_code ?? 'PDF-BATCH',
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit' => 'gram',
            'unit_price' => 1,
            'line_total' => $quantity,
        ]);
    }

    foreach ([['PDF-ALT', '2026-01-01', 2], ['PDF-NEU', '2026-02-01', 4], ['PDF-UNBENUTZT', '2026-03-01', 100]] as [$number, $date, $quantity]) {
        ProductBatch::query()->create([
            'product_id' => $product->id,
            'batch_number' => $number,
            'received_at' => $date,
            'quantity' => $quantity,
        ]);
    }

    return [$offer->load('items.product'), $product];
}

it('prints the newest consumed FIFO batch on both customer delivery templates and excludes unused stock', function () {
    [$offer] = batchPdfOffer([5]);
    expect(DeliveryNoteBatchNumber::forOffer($offer))->toBe([]);

    app(OfferFulfillmentService::class)->complete($offer);
    $offer = $offer->fresh('items.product');
    $numbers = DeliveryNoteBatchNumber::forOffer($offer);
    expect($numbers)->toBe([$offer->items->first()->id => 'PDF-NEU']);

    $template = new DocumentTemplate(['company_name' => 'PDF Test', 'show_logo' => true]);
    foreach (['pdf.delivery-note', 'pdf.delivery-note-ohne'] as $name) {
        $html = view($name, [
            'offer' => $offer,
            'template' => $template,
            'logoDataUri' => null,
            'backgroundDataUri' => null,
            'batchNumbers' => $numbers,
        ])->render();

        expect($html)
            ->toContain('<th>Pos.</th>')
            ->toContain('<td>1</td>')
            ->toContain('Batch: PDF-NEU')
            ->toContain('Versandhinweis bleibt erhalten.')
            ->not->toContain('PDF-ALT')
            ->not->toContain('PDF-UNBENUTZT');

        $withoutBatches = view($name, [
            'offer' => $offer,
            'template' => $template,
            'logoDataUri' => null,
            'backgroundDataUri' => null,
        ])->render();
        expect($withoutBatches)->not->toContain('class="batch-number"');
    }
});

it('does not assign a guessed batch when repeated product positions cannot be distinguished', function () {
    [$offer] = batchPdfOffer([2, 2]);
    app(OfferFulfillmentService::class)->complete($offer);
    $offer = $offer->fresh('items.product');
    expect(DeliveryNoteBatchNumber::forOffer($offer))->toBe([]);
});

it('can distinguish repeated product positions when their actual movement quantities identify unique batches', function () {
    [$offer] = batchPdfOffer([2, 4]);
    app(OfferFulfillmentService::class)->complete($offer);
    $offer = $offer->fresh('items.product');
    $numbers = DeliveryNoteBatchNumber::forOffer($offer);
    expect($numbers[$offer->items->first()->id])->toBe('PDF-ALT')
        ->and($numbers[$offer->items->last()->id])->toBe('PDF-NEU');
});
