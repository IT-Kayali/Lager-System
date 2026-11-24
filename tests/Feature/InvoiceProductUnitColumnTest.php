<?php

use App\Http\Controllers\OfferPdfController;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Product;

function documentWithUnits(array $units, bool $withShipping = false): Offer
{
    $offer = new Offer([
        'shipping_method' => $withShipping ? 'Lieferung' : 'Abholung',
        'shipping_price_gross' => $withShipping ? 6.00 : null,
    ]);

    $items = collect($units)->map(function (string $unit, int $index) {
        $product = new Product([
            'name' => 'Produkt ' . ($index + 1),
            'unit' => $unit,
        ]);

        $item = new OfferItem([
            'product_name' => 'Produkt ' . ($index + 1),
            'quantity' => 2,
            'unit_price' => 10,
            'line_total' => 20,
        ]);
        $item->setRelation('product', $product);

        return $item;
    });

    $offer->setRelation('items', $items);

    return $offer;
}

function transformOfferDocumentTable(Offer $offer, bool $withoutLogo, string $html): string
{
    $controller = new OfferPdfController();
    $method = new ReflectionMethod($controller, 'injectProductUnitColumnIntoOfferDocument');
    $method->setAccessible(true);

    return $method->invoke($controller, $html, $offer, $withoutLogo);
}

function logoDocumentTableHtml(bool $includeEmptyRow = false): string
{
    $emptyRow = $includeEmptyRow
        ? '<tr class="empty-product-row"><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html><head><style>.items-table{width:160mm}</style></head><body>
<table class="items-table">
<thead><tr><th>Beschreibung</th><th>Menge</th><th>Preis</th><th>Summe</th></tr></thead>
<tbody>
<tr><td>Produkt 1</td><td>2,00</td><td>10,00€</td><td>20,00€</td></tr>
{$emptyRow}
</tbody>
</table>
</body></html>
HTML;
}

function noLogoDocumentTableHtml(bool $withShipping = false): string
{
    $shippingRow = $withShipping
        ? '<tr><td>Versand</td><td>1,00</td><td>6,00€</td><td>6,00€</td></tr>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html><head><style>table.items{width:170mm}</style></head><body>
<table class="items">
<thead><tr><th>PRODUCT</th><th>QUANTITY</th><th>PRICE</th><th>SUM</th></tr></thead>
<tbody>
<tr><td>Product 1</td><td>2,00</td><td>10,00€</td><td>20,00€</td></tr>
{$shippingRow}
</tbody>
</table>
</body></html>
HTML;
}

it('uses six columns including position and unit with german labels', function () {
    $html = transformOfferDocumentTable(documentWithUnits(['g']), false, logoDocumentTableHtml(true));
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($decoded)
        ->toContain('Produkt')
        ->toContain('Menge')
        ->toContain('Einheit')
        ->toContain('Preis')
        ->toContain('Summe')
        ->toContain('>g<')
        ->toContain('document-unit-table');

    expect($decoded)->toContain('width: 8% !important')->toContain('style="width: 8%; text-align: center;">Pos.</th>')->toContain('<td>1</td>');

    expect(strpos($decoded, '>Produkt<'))
        ->toBeLessThan(strpos($decoded, '>Menge<'));
    expect(strpos($decoded, '>Menge<'))
        ->toBeLessThan(strpos($decoded, '>Einheit<'));
    expect(strpos($decoded, '>Einheit<'))
        ->toBeLessThan(strpos($decoded, '>Preis<'));
    expect(strpos($decoded, '>Preis<'))
        ->toBeLessThan(strpos($decoded, '>Summe<'));

    preg_match('/<tr class="empty-product-row">(.*?)<\/tr>/s', $decoded, $emptyRow);
    expect(substr_count($emptyRow[1] ?? '', '<td>'))->toBe(6);
});

it('uses six columns including position and unit with english labels without logo', function () {
    $html = transformOfferDocumentTable(documentWithUnits(['Stk']), true, noLogoDocumentTableHtml());
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($decoded)
        ->toContain('PRODUCT')
        ->toContain('QUANTITY')
        ->toContain('UNIT')
        ->toContain('PRICE')
        ->toContain('TOTAL')
        ->toContain('>Stk<')
        ->toContain('document-unit-table');

    expect($decoded)->toContain('width: 8% !important')->toContain('style="width: 8%; text-align: center;">Pos.</th>')->toContain('<td>1</td>');

    expect(strpos($decoded, '>PRODUCT<'))
        ->toBeLessThan(strpos($decoded, '>QUANTITY<'));
    expect(strpos($decoded, '>QUANTITY<'))
        ->toBeLessThan(strpos($decoded, '>UNIT<'));
    expect(strpos($decoded, '>UNIT<'))
        ->toBeLessThan(strpos($decoded, '>PRICE<'));
    expect(strpos($decoded, '>PRICE<'))
        ->toBeLessThan(strpos($decoded, '>TOTAL<'));
});

it('shows a dash as unit for the shipping row', function () {
    $html = transformOfferDocumentTable(documentWithUnits(['g'], true), true, noLogoDocumentTableHtml(true));
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($decoded)
        ->toContain('>g<')
        ->toContain('>—<');
});

it('uses the same unit column transformation for offer and invoice pdfs', function () {
    $controller = file_get_contents(app_path('Http/Controllers/OfferPdfController.php'));

    expect($controller)
        ->toContain("if (\$type === 'delivery-note') {")
        ->toContain("\$html = \$this->injectProductUnitColumnIntoOfferDocument(\$html, \$offer, \$isNoLogoPdfTemplate);")
        ->not->toContain("elseif (\$type === 'invoice')");
});


it('numbers product positions continuously across pages without numbering shipping or padding', function () {
    $first = '<table class="items-table"><thead><tr><th>Produkt</th><th>Menge</th><th>Preis</th><th>Summe</th></tr></thead><tbody>'
        . '<tr><td>A</td><td>1</td><td>10</td><td>10</td></tr>'
        . '<tr class="empty-product-row"><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table>';
    $second = '<table class="items-table"><thead><tr><th>Produkt</th><th>Menge</th><th>Preis</th><th>Summe</th></tr></thead><tbody>'
        . '<tr><td>B</td><td>2</td><td>10</td><td>20</td></tr>'
        . '<tr><td>Versand</td><td>1</td><td>6</td><td>6</td></tr></tbody></table>';
    $html = transformOfferDocumentTable(documentWithUnits(['gram', 'piece'], true), false, '<html><head><meta charset="UTF-8"></head><body>' . $first . $second . '</body></html>');

    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $positions = [];
    foreach ($xpath->query('//table/tbody/tr/td[1]') as $cell) {
        $positions[] = str_replace("\u{00A0}", '', $cell->textContent);
    }

    expect($positions)->toBe(['1', '', '2', ''])
        ->and($xpath->query('//table/thead/tr/th[1]')->length)->toBe(2);
});
