<?php

use App\Support\DeliveryNoteLayout;

function deliveryNoteTestRows(int $count): \Illuminate\Support\Collection
{
    return collect(range(1, $count))->map(fn ($id) => (object) [
        'product' => 'Testprodukt ' . $id,
        'quantity' => '100,00',
        'unit' => 'Gramm',
    ]);
}

it('places a delivery note beneath the final product without crossing reserved zones', function (bool $branded) {
    $pages = DeliveryNoteLayout::paginate(
        deliveryNoteTestRows(9),
        '20 درج 50 مل روسا دراج 10 مل Hinweis zum Versand',
        true,
        $branded
    );

    expect($pages->last()['note_lines'])->not->toBeEmpty();
    foreach ($pages as $page) {
        expect($page['used_mm'])->toBeLessThanOrEqual($page['available_mm']);
    }
})->with([true, false]);

it('moves long notes to continuation pages rather than the PDF footer', function (bool $branded) {
    $note = str_repeat('Sehr langer Versandhinweis mit mehreren Informationen. ', 90);
    $pages = DeliveryNoteLayout::paginate(deliveryNoteTestRows(15), $note, false, $branded);
    $notes = $pages->filter(fn ($page) => $page['note_lines'] !== []);

    expect($pages->count())->toBeGreaterThan(1)
        ->and($notes->count())->toBeGreaterThan(1);

    foreach ($pages as $page) {
        expect($page['used_mm'])->toBeLessThanOrEqual($page['available_mm']);
    }
})->with([true, false]);

it('keeps the note and all line items when the item list is empty', function () {
    $pages = DeliveryNoteLayout::paginate(collect(), 'Hinweis ohne Positionen', false, true);
    expect($pages)->toHaveCount(1)
        ->and($pages->first()['note_lines'])->not->toBeEmpty();
});
