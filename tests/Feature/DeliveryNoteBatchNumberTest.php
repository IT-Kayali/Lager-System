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
            ->toContain('<th style="width:8%;text-align:center">Pos.</th>')
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


it('shows a read-only provisional FIFO allocation for each active offer status', function (string $status) {
    [$offer, $product] = batchPdfOffer([5]);
    $offer->update(['status' => $status]);

    $before = $product->batches()->orderBy('id')->pluck('quantity', 'id')->all();
    $movementCount = \App\Models\StockMovement::query()->count();
    $data = DeliveryNoteBatchNumber::forDocument($offer);
    expect($data)->toBe(['numbers' => [$offer->items->first()->id => 'PDF-NEU'], 'provisional' => true])
        ->and($product->batches()->orderBy('id')->pluck('quantity', 'id')->all())->toBe($before)
        ->and(\App\Models\StockMovement::query()->count())->toBe($movementCount);

    $template = new DocumentTemplate(['company_name' => 'PDF Test', 'show_logo' => true]);
    foreach (['pdf.delivery-note' => 'Batch (voraussichtlich): ', 'pdf.delivery-note-ohne' => 'Batch (provisional): '] as $name => $label) {
        $html = view($name, [
            'offer' => $offer,
            'template' => $template,
            'logoDataUri' => null,
            'backgroundDataUri' => null,
            'batchNumbers' => $data['numbers'],
            'batchProvisional' => $data['provisional'],
        ])->render();

        expect($html)->toContain($label.'PDF-NEU')
            ->not->toContain('PDF-ALT')
            ->not->toContain('PDF-UNBENUTZT');
    }
})->with([Offer::STATUS_OFFER, Offer::STATUS_IN_PROGRESS, Offer::STATUS_READY]);

it('switches from preview to actually booked batches and never substitutes current stock', function () {
    [$offer, $product] = batchPdfOffer([2]);
    $itemId = $offer->items->first()->id;
    expect(DeliveryNoteBatchNumber::forDocument($offer))->toBe(['numbers' => [$itemId => 'PDF-ALT'], 'provisional' => true]);

    // A different withdrawal consumes the old batch before fulfillment.
    $product->batches()->where('batch_number', 'PDF-ALT')->update(['quantity' => 0]);
    app(OfferFulfillmentService::class)->complete($offer);
    $offer = $offer->fresh('items.product');
    expect(DeliveryNoteBatchNumber::forDocument($offer))->toBe(['numbers' => [$itemId => 'PDF-NEU'], 'provisional' => false]);

    \App\Models\StockMovement::query()->where('reference_type', Offer::class)->where('reference_id', $offer->id)->delete();
    expect(DeliveryNoteBatchNumber::forDocument($offer))->toBe(['numbers' => [], 'provisional' => false]);
});

it('allocates repeated product rows in original order independently of PDF sorting', function () {
    [$offer] = batchPdfOffer([2, 4]);
    $first = $offer->items->first()->id;
    $second = $offer->items->last()->id;
    $offer->setRelation('items', $offer->items->reverse()->values());

    expect(DeliveryNoteBatchNumber::forDocument($offer))->toBe(['numbers' => [$first => 'PDF-ALT', $second => 'PDF-NEU'], 'provisional' => true]);
});

it('does not show an incomplete allocation or a preview for a cancelled or expired offer', function () {
    [$offer, $product] = batchPdfOffer([200]);
    expect(DeliveryNoteBatchNumber::forDocument($offer))->toBe(['numbers' => [], 'provisional' => true]);
    $product->batches()->where('batch_number', 'PDF-UNBENUTZT')->update(['quantity' => 300]);

    foreach ([Offer::STATUS_CANCELLED, Offer::STATUS_RESERVATION_EXPIRED] as $status) {
        $offer->update(['status' => $status]);
        expect(DeliveryNoteBatchNumber::forDocument($offer))->toBe(['numbers' => [], 'provisional' => false]);
    }
});

it('renders a narrow position column and a wider product column in DomPDF', function (string $view, string $productLabel) {
    [$offer] = batchPdfOffer([5]);
    $data = DeliveryNoteBatchNumber::forDocument($offer);
    $html = view($view, [
        'offer' => $offer,
        'template' => new DocumentTemplate(['company_name' => 'PDF Test', 'show_logo' => true]),
        'logoDataUri' => null,
        'backgroundDataUri' => null,
        'batchNumbers' => $data['numbers'],
        'batchProvisional' => $data['provisional'],
    ])->render();

    $widths = [];
    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4')->getDomPDF();
    $pdf->setCallbacks([[
        'event' => 'end_frame',
        'f' => function ($frame) use (&$widths) {
            $node = $frame->get_node();
            if ($node instanceof DOMElement && $node->tagName === 'th') {
                $box = $frame->get_border_box();
                $widths[trim($node->textContent)] = (float) ($box['w'] ?? $box[2]);
            }
        },
    ]]);
    $pdf->render();

    expect($widths)->toHaveKeys(['Pos.', $productLabel])
        ->and($widths['Pos.'])->toBeGreaterThan(0)
        ->and($widths['Pos.'])->toBeLessThan($widths[$productLabel] / 3);
})->with([
    ['pdf.delivery-note', 'Produkt'],
    ['pdf.delivery-note-ohne', 'Product'],
]);
