<?php

it('uses position product quantity unit columns on branded delivery notes', function () {
    $template = file_get_contents(resource_path('views/pdf/delivery-note.blade.php'));

    expect($template)
        ->toContain('<th style="width:52%;text-align:left">Produkt</th>')
        ->toContain('<th style="width:22%;text-align:center">Menge</th>')
        ->toContain('<th style="width:18%;text-align:center">Einheit</th>')
        ->toContain('<th style="width:8%;text-align:center">Pos.</th>')
        ->toContain('<td>{{ $item->position }}</td>')
        ->toContain('<td>{{ $item->product }}@if($item->batch_number !== null)')
        ->toContain('class="batch-number">{{ $item->batch_label }}{{ $item->batch_number }}</div>')
        ->toContain('<td>{{ $item->quantity }}</td>')
        ->toContain('<td>{{ $item->unit }}</td>')
        ->toContain("'product'=>\$product")
        ->toContain("'unit'=>\$unit!==''?(\$unitLabels[\$unit]??\$unit):'—'")
        ->toContain("'quantity'=>\$quantity")
        ->not->toContain('<th>Bezeichnung</th>')
        ->not->toContain("trim(\$qty . ' ' . \$unit)");

    expect(strpos($template, '<th style="width:8%;text-align:center">Pos.</th>'))
        ->toBeLessThan(strpos($template, '<th style="width:52%;text-align:left">Produkt</th>'));
    expect(strpos($template, '<th style="width:52%;text-align:left">Produkt</th>'))
        ->toBeLessThan(strpos($template, '<th style="width:22%;text-align:center">Menge</th>'));
    expect(strpos($template, '<th style="width:22%;text-align:center">Menge</th>'))
        ->toBeLessThan(strpos($template, '<th style="width:18%;text-align:center">Einheit</th>'));
});

it('uses position product quantity unit columns on no-logo delivery notes', function () {
    $template = file_get_contents(resource_path('views/pdf/delivery-note-ohne.blade.php'));

    expect($template)
        ->toContain('<th style="width:52%;text-align:left">Product</th>')
        ->toContain('<th style="width:22%;text-align:center">Quantity</th>')
        ->toContain('<th style="width:18%;text-align:center">Unit</th>')
        ->toContain('<th style="width:8%;text-align:center">Pos.</th>')
        ->toContain('<td>{{ $item->position }}</td>')
        ->toContain('<td>{{ $item->product }}@if($item->batch_number !== null)')
        ->toContain('class="batch-number">{{ $item->batch_label }}{{ $item->batch_number }}</div>')
        ->toContain('<td>{{ $item->quantity }}</td>')
        ->toContain('<td>{{ $item->unit }}</td>')
        ->toContain("'product'=>\$product")
        ->toContain("'unit'=>\$unit!==''?(\$unitLabels[\$unit]??\$unit):'—'")
        ->toContain("'quantity'=>\$quantity")
        ->not->toContain("trim(\$qty . ' ' . \$unit)");

    expect(strpos($template, '<th style="width:8%;text-align:center">Pos.</th>'))
        ->toBeLessThan(strpos($template, '<th style="width:52%;text-align:left">Product</th>'));
    expect(strpos($template, '<th style="width:52%;text-align:left">Product</th>'))
        ->toBeLessThan(strpos($template, '<th style="width:22%;text-align:center">Quantity</th>'));
    expect(strpos($template, '<th style="width:22%;text-align:center">Quantity</th>'))
        ->toBeLessThan(strpos($template, '<th style="width:18%;text-align:center">Unit</th>'));
});
