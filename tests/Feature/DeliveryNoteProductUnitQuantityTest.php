<?php

it('uses position product quantity unit columns on branded delivery notes', function () {
    $template = file_get_contents(resource_path('views/pdf/delivery-note.blade.php'));

    expect($template)
        ->toContain('<th>Produkt</th>')
        ->toContain('<th>Menge</th>')
        ->toContain('<th>Einheit</th>')
        ->toContain('<th>Pos.</th>')
        ->toContain('<td>{{ $item->position }}</td>')
        ->toContain('<td>{{ $item->product }}@if($item->batch_number !== null)')
        ->toContain('class="batch-number">Batch: {{ $item->batch_number }}</div>')
        ->toContain('<td>{{ $item->quantity }}</td>')
        ->toContain('<td>{{ $item->unit }}</td>')
        ->toContain("'product'=>\$product")
        ->toContain("'unit'=>\$unit!==''?(\$unitLabels[\$unit]??\$unit):'—'")
        ->toContain("'quantity'=>\$quantity")
        ->not->toContain('<th>Bezeichnung</th>')
        ->not->toContain("trim(\$qty . ' ' . \$unit)");

    expect(strpos($template, '<th>Pos.</th>'))
        ->toBeLessThan(strpos($template, '<th>Produkt</th>'));
    expect(strpos($template, '<th>Produkt</th>'))
        ->toBeLessThan(strpos($template, '<th>Menge</th>'));
    expect(strpos($template, '<th>Menge</th>'))
        ->toBeLessThan(strpos($template, '<th>Einheit</th>'));
});

it('uses position product quantity unit columns on no-logo delivery notes', function () {
    $template = file_get_contents(resource_path('views/pdf/delivery-note-ohne.blade.php'));

    expect($template)
        ->toContain('<th>Product</th>')
        ->toContain('<th>Quantity</th>')
        ->toContain('<th>Unit</th>')
        ->toContain('<th>Pos.</th>')
        ->toContain('<td>{{ $item->position }}</td>')
        ->toContain('<td>{{ $item->product }}@if($item->batch_number !== null)')
        ->toContain('class="batch-number">Batch: {{ $item->batch_number }}</div>')
        ->toContain('<td>{{ $item->quantity }}</td>')
        ->toContain('<td>{{ $item->unit }}</td>')
        ->toContain("'product'=>\$product")
        ->toContain("'unit'=>\$unit!==''?(\$unitLabels[\$unit]??\$unit):'—'")
        ->toContain("'quantity'=>\$quantity")
        ->not->toContain("trim(\$qty . ' ' . \$unit)");

    expect(strpos($template, '<th>Pos.</th>'))
        ->toBeLessThan(strpos($template, '<th>Product</th>'));
    expect(strpos($template, '<th>Product</th>'))
        ->toBeLessThan(strpos($template, '<th>Quantity</th>'));
    expect(strpos($template, '<th>Quantity</th>'))
        ->toBeLessThan(strpos($template, '<th>Unit</th>'));
});
