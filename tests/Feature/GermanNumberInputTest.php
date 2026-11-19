<?php

use App\Http\Middleware\NormalizeGermanNumbers;
use Illuminate\Http\Request;

it('normalizes German decimal form values before validation', function () {
    $request = Request::create('/test', 'POST', [
        'amount' => '1.234,50',
        'minimum_stock' => '250,25',
        'shipping_price_gross' => '5,95',
        'tax_rate' => '19,00',
        'carton_count' => '3',
        'items' => [
            ['product_id' => '7', 'quantity' => '12,345'],
        ],
        'prices' => [
            '12' => '0,75',
        ],
    ]);

    app(NormalizeGermanNumbers::class)->handle($request, fn () => response('ok'));

    expect($request->input('amount'))->toBe('1234.50')
        ->and($request->input('minimum_stock'))->toBe('250.25')
        ->and($request->input('shipping_price_gross'))->toBe('5.95')
        ->and($request->input('tax_rate'))->toBe('19.00')
        ->and($request->input('items.0.quantity'))->toBe('12.345')
        ->and($request->input('prices.12'))->toBe('0.75')
        ->and($request->input('carton_count'))->toBe('3')
        ->and($request->input('items.0.product_id'))->toBe('7');
});

it('keeps German number handling scoped and preserves quantity precision', function () {
    $script = file_get_contents(resource_path('js/german-numbers.js'));

    expect($script)
        ->toContain("new Intl.NumberFormat('de-DE'")
        ->toContain('minimumFractionDigits,')
        ->toContain('const maximumFractionDigits = isPrice ? 5 : (isQuantityField(input) ? 3 : 2)')
        ->toContain('isPrice ? 5 : 2')
        ->toContain('input[name^="rules["][name$="[price]"]')
        ->toContain("input.inputMode = 'decimal'")
        ->toContain("input.type === 'hidden'")
        ->not->toContain('window.parseFloat =')
        ->not->toContain('Number.parseFloat =');
});

it('uses the central formatter for delivery note quantities', function () {
    $german = file_get_contents(resource_path('views/pdf/delivery-note.blade.php'));
    $english = file_get_contents(resource_path('views/pdf/delivery-note-ohne.blade.php'));
    $branch = file_get_contents(resource_path('views/pdf/branch-withdrawal-delivery-note.blade.php'));

    foreach ([$german, $english, $branch] as $template) {
        expect($template)
            ->toContain('GermanNumber::format($value)')
            ->not->toContain('number_format((float) $value, 3');
    }
});
