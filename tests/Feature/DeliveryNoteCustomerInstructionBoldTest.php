<?php

use App\Http\Controllers\OfferPdfController;
use App\Models\Customer;
use App\Models\Offer;

it('druckt den oberen Kundenhinweis inklusive Text in beiden Lieferschein-Vorlagen fett', function (bool $withoutLogo) {
    $customer = new Customer([
        'delivery_note_instruction' => 'Bitte Kartons ohne Logo liefern.',
    ]);
    $offer = new Offer;
    $offer->setRelation('customer', $customer);

    $shippingLabel = $withoutLogo ? 'Shipping Method:' : 'Versandart:';
    $html = '<html><body>'
        . '<div class="meta"><table><tr><td>' . $shippingLabel . '</td><td>Lieferung</td></tr></table></div>'
        . '<div class="recipient">Empfänger</div><div class="title">Lieferschein</div>'
        . '<div class="intro">Einleitung</div><div class="items-wrap">Positionen</div>'
        . '</body></html>';

    $method = new ReflectionMethod(OfferPdfController::class, 'injectCustomerDeliveryInstructionIntoDeliveryNote');
    $result = $method->invoke(new OfferPdfController, $html, $offer, $withoutLogo);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . $result);
    $xpath = new DOMXPath($dom);
    $cells = $xpath->query('//tr[contains(@class, "customer-delivery-instruction")]/td');

    expect($cells)->not->toBeFalse()
        ->and($cells->length)->toBe(2)
        ->and($cells->item(0)->getAttribute('style'))->toContain('font-weight:700')
        ->and($cells->item(1)->getAttribute('style'))->toContain('font-weight:700')
        ->and($cells->item(1)->textContent)->toBe('Bitte Kartons ohne Logo liefern.');
})->with([false, true]);

it('fügt bei fehlender Kundennotiz keine zusätzliche Tabellenzeile ein', function () {
    $customer = new Customer(['delivery_note_instruction' => '']);
    $offer = new Offer;
    $offer->setRelation('customer', $customer);
    $html = '<html><body><div class="meta"><table><tr><td>Versandart:</td><td>Lieferung</td></tr></table></div></body></html>';

    $method = new ReflectionMethod(OfferPdfController::class, 'injectCustomerDeliveryInstructionIntoDeliveryNote');

    expect($method->invoke(new OfferPdfController, $html, $offer, false))->toBe($html);
});
