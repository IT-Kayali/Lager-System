<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\DocumentTemplate;
use App\Models\Offer;
use App\Services\DocumentItemSorter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use InvalidArgumentException;

class OfferPdfController extends Controller
{
    public function stream(Offer $offer, string $type): Response
    {
        $documentLabels = ['offer' => 'Angebot', 'invoice' => 'Rechnung', 'delivery-note' => 'Lieferschein'];
        if (! array_key_exists($type, $documentLabels)) throw new InvalidArgumentException('Ungültiger Dokumenttyp.');

        $offer->load(['customer.group', 'items.product.categories']);
        $offer->setRelation('items', app(DocumentItemSorter::class)->sort($offer->items, $type === 'delivery-note'));

        $template = DocumentTemplate::byKey($offer->template_type); $title = $documentLabels[$type];
        $templateName = strtolower((string) ($template->name ?? '')); $isNoLogoPdfTemplate = str_contains($templateName, 'ohne') || ! (bool) ($template->show_logo ?? false);
        if ($type === 'delivery-note') {
            $pdfView = $isNoLogoPdfTemplate ? 'pdf.delivery-note-ohne' : 'pdf.delivery-note';
            $logoDataUri = $isNoLogoPdfTemplate ? null : $this->publicStorageDataUri($template->delivery_logo_path);
            $backgroundDataUri = $this->publicStorageDataUri($template->delivery_background_image_path);
        } else {
            $pdfView = $isNoLogoPdfTemplate ? 'pdf.offer-document-ohne' : 'pdf.offer-document';
            $logoDataUri = $this->publicStorageDataUri($template->logo_path); $backgroundDataUri = $this->publicStorageDataUri($template->background_image_path);
        }
        $activityEvents = ['offer' => 'offer.pdf.generated', 'invoice' => 'invoice.pdf.generated', 'delivery-note' => 'delivery_note.pdf.generated'];
        ActivityLog::record($activityEvents[$type], $offer, ['offer_number' => $offer->offer_number, 'template' => $template->name]);
        $viewData = compact('offer', 'template', 'title', 'logoDataUri', 'backgroundDataUri') + ['documentType' => $type];
        if ($type === 'delivery-note') {
            $html = view($pdfView, $viewData)->render();
            $html = $this->injectCartonCountIntoDeliveryNote($html, $offer, $isNoLogoPdfTemplate);
            $html = $this->injectCustomerDeliveryInstructionIntoDeliveryNote($html, $offer, $isNoLogoPdfTemplate);
            $pdf = Pdf::loadHTML($html)->setPaper('a4');
        } else {
            $html = view($pdfView, $viewData)->render(); $html = $this->injectProductUnitColumnIntoOfferDocument($html, $offer, $isNoLogoPdfTemplate); $pdf = Pdf::loadHTML($html)->setPaper('a4');
        }
        $filenamePrefixes = ['offer' => 'angebot-', 'invoice' => 'rechnung-', 'delivery-note' => 'lieferschein-'];
        return $pdf->stream($filenamePrefixes[$type] . $offer->offer_number . '.pdf');
    }

    private function injectCartonCountIntoDeliveryNote(string $html, Offer $offer, bool $isNoLogoPdfTemplate): string
    {
        if ($offer->shipping_method !== 'Lieferung' || ! $offer->carton_count || $offer->carton_count < 1) return $html;
        $shippingLabel = $isNoLogoPdfTemplate ? 'Shipping Method:' : 'Versandart:'; $cartonLabel = $isNoLogoPdfTemplate ? 'Number of Cartons:' : 'Anzahl Kartons:';
        $pattern = '/(<tr>\s*<td>' . preg_quote($shippingLabel, '/') . '<\/td>\s*<td>.*?<\/td>\s*<\/tr>)/s';
        $cartonRow = "\n                    <tr>\n                        <td>{$cartonLabel}</td>\n                        <td>{$offer->carton_count}</td>\n                    </tr>";
        $rendered = preg_replace($pattern, '$1' . $cartonRow, $html, 1); return is_string($rendered) ? $rendered : $html;
    }

    private function injectCustomerDeliveryInstructionIntoDeliveryNote(string $html, Offer $offer, bool $isNoLogoPdfTemplate): string
    {
        $instruction = trim((string) ($offer->customer?->delivery_note_instruction ?? ''));

        if ($instruction === '') {
            return $html;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previousLibxmlState = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);

        if (! $loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($dom);
        $metaRows = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " meta ")]//table//tr');

        if ($metaRows === false || $metaRows->length === 0) {
            return $html;
        }

        $shippingLabel = $isNoLogoPdfTemplate ? 'Shipping Method:' : 'Versandart:';
        $cartonLabel = $isNoLogoPdfTemplate ? 'Number of Cartons:' : 'Anzahl Kartons:';
        $noteLabel = $isNoLogoPdfTemplate ? 'Note:' : 'Hinweis:';
        $targetRow = null;

        foreach ($metaRows as $row) {
            if (! $row instanceof \DOMElement) {
                continue;
            }

            $cells = $xpath->query('./td', $row);

            if ($cells === false || $cells->length < 1) {
                continue;
            }

            $label = trim((string) $cells->item(0)?->textContent);

            if ($label === $shippingLabel) {
                $targetRow = $row;
                continue;
            }

            if ($targetRow !== null && $label === $cartonLabel) {
                $targetRow = $row;
                break;
            }
        }

        if (! $targetRow instanceof \DOMElement || ! $targetRow->parentNode) {
            return $html;
        }

        $noteRow = $dom->createElement('tr');
        $noteRow->setAttribute('class', 'customer-delivery-instruction');

        $labelCell = $dom->createElement('td');
        $labelCell->setAttribute(
            'style',
            $isNoLogoPdfTemplate
                ? 'padding-top:1.5mm;padding-right:2mm;font-weight:700;color:#111111;vertical-align:top;'
                : 'padding-top:1.1mm;padding-right:2mm;font-weight:700;color:#111111;vertical-align:top;position:relative;top:-0.7mm;'
        );
        $labelCell->appendChild($dom->createTextNode($noteLabel));

        $textCell = $dom->createElement('td');
        $textCell->setAttribute(
            'style',
            'padding-top:1.5mm;font-weight:400;color:#111111;line-height:1.3;white-space:normal;overflow-wrap:break-word;word-wrap:break-word;vertical-align:top;'
        );
        $textCell->appendChild($dom->createTextNode($instruction));

        $noteRow->appendChild($labelCell);
        $noteRow->appendChild($textCell);

        if ($targetRow->nextSibling) {
            $targetRow->parentNode->insertBefore($noteRow, $targetRow->nextSibling);
        } else {
            $targetRow->parentNode->appendChild($noteRow);
        }

        if ($isNoLogoPdfTemplate) {
            $recipient = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " recipient ")]')->item(0);

            if ($recipient instanceof \DOMElement) {
                $recipient->setAttribute('style', trim($recipient->getAttribute('style') . ';top:72mm;'));
            }
        } else {
            $title = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " title ")]')->item(0);
            $intro = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " intro ")]')->item(0);
            $firstItemsWrap = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " items-wrap ") and not(contains(concat(" ", normalize-space(@class), " "), " continuation "))]')->item(0);

            if ($title instanceof \DOMElement) {
                $title->setAttribute('style', trim($title->getAttribute('style') . ';top:96mm;'));
            }

            if ($intro instanceof \DOMElement) {
                $intro->setAttribute('style', trim($intro->getAttribute('style') . ';top:113mm;'));
            }

            if ($firstItemsWrap instanceof \DOMElement) {
                $firstItemsWrap->setAttribute('style', trim($firstItemsWrap->getAttribute('style') . ';top:137mm;'));
            }
        }

        $rendered = $dom->saveHTML();

        if (! is_string($rendered)) {
            return $html;
        }

        return (string) preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/i', '', $rendered);
    }

    private function injectProductUnitColumnIntoOfferDocument(string $html, Offer $offer, bool $isNoLogoPdfTemplate): string
    {
        $unitLocale = $isNoLogoPdfTemplate ? 'en' : 'de';
        $units = $offer->items->values()->map(fn ($item): string => $item->product?->unitLabel($unitLocale) ?? '—')->all();
        $hasShippingRow = (($offer->shipping_method ?? null) === 'Lieferung' && (float) ($offer->shipping_price_gross ?? 0) > 0);
        if ($hasShippingRow) $units[] = '—';
        $dom = new \DOMDocument('1.0', 'UTF-8'); $previousLibxmlState = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD); libxml_clear_errors(); libxml_use_internal_errors($previousLibxmlState);
        if (! $loaded) return $html;
        $xpath = new \DOMXPath($dom); $tables = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " items-table ") or contains(concat(" ", normalize-space(@class), " "), " items ")]');
        if ($tables === false || $tables->length === 0) return $html;
        $productLabel = $isNoLogoPdfTemplate ? 'PRODUCT' : 'Produkt'; $quantityLabel = $isNoLogoPdfTemplate ? 'QUANTITY' : 'Menge'; $unitLabel = $isNoLogoPdfTemplate ? 'UNIT' : 'Einheit'; $priceLabel = $isNoLogoPdfTemplate ? 'PRICE' : 'Preis'; $totalLabel = $isNoLogoPdfTemplate ? 'TOTAL' : 'Summe'; $unitIndex = 0; $productRowCount = $offer->items->count();
        foreach ($tables as $table) {
            if (! $table instanceof \DOMElement) continue;
            $table->setAttribute('class', trim($table->getAttribute('class') . ' document-unit-table'));
            $headerNodes = []; $headers = $xpath->query('.//thead/tr[1]/th', $table); if ($headers !== false) foreach ($headers as $header) $headerNodes[] = $header;
            if (count($headerNodes) >= 4) { $headerNodes[0]->nodeValue = $productLabel; $headerNodes[1]->nodeValue = $quantityLabel; $headerNodes[2]->nodeValue = $priceLabel; $headerNodes[3]->nodeValue = $totalLabel; $unitHeader = $dom->createElement('th'); $unitHeader->appendChild($dom->createTextNode($unitLabel)); $headerNodes[2]->parentNode?->insertBefore($unitHeader, $headerNodes[2]); }
            $rows = $xpath->query('.//tbody/tr', $table); if ($rows === false) continue;
            foreach ($rows as $row) {
                if (! $row instanceof \DOMElement) continue; $cells = [];
                foreach ($row->childNodes as $child) if ($child instanceof \DOMElement && strtolower($child->tagName) === 'td') $cells[] = $child;
                if (count($cells) < 4) continue;
                $rowClasses = ' ' . preg_replace('/\s+/', ' ', trim($row->getAttribute('class'))) . ' '; $isEmptyRow = str_contains($rowClasses, ' empty-product-row '); $isShippingRow = ! $isEmptyRow && $hasShippingRow && $unitIndex >= $productRowCount; $unit = $isEmptyRow ? "\u{00A0}" : ($units[$unitIndex] ?? '—');
                if ($isShippingRow) $cells[1]->nodeValue = '—'; if (! $isEmptyRow) $unitIndex++;
                $unitCell = $dom->createElement('td'); $unitCell->appendChild($dom->createTextNode($unit)); $cells[2]->parentNode?->insertBefore($unitCell, $cells[2]);
            }
        }
        $head = $dom->getElementsByTagName('head')->item(0); if ($head instanceof \DOMElement) { $style = $dom->createElement('style'); $style->setAttribute('id', 'document-unit-column-styles'); $style->appendChild($dom->createTextNode($isNoLogoPdfTemplate ? $this->noLogoDocumentUnitColumnCss() : $this->logoDocumentUnitColumnCss())); $head->appendChild($style); }
        $rendered = $dom->saveHTML(); if (! is_string($rendered)) return $html; return (string) preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/i', '', $rendered);
    }

    private function logoDocumentUnitColumnCss(): string { return <<<'CSS'
.items-table.document-unit-table th:nth-child(1),
.items-table.document-unit-table td:nth-child(1) { width: 20% !important; text-align: left !important; }
.items-table.document-unit-table th:nth-child(2),
.items-table.document-unit-table td:nth-child(2) { width: 20% !important; text-align: center !important; }
.items-table.document-unit-table th:nth-child(3),
.items-table.document-unit-table td:nth-child(3) { width: 20% !important; text-align: center !important; }
.items-table.document-unit-table th:nth-child(4),
.items-table.document-unit-table td:nth-child(4) { width: 20% !important; text-align: right !important; }
.items-table.document-unit-table th:nth-child(5),
.items-table.document-unit-table td:nth-child(5) { width: 20% !important; text-align: right !important; }
CSS;
    }
    private function noLogoDocumentUnitColumnCss(): string { return <<<'CSS'
table.items.document-unit-table th:nth-child(1),
table.items.document-unit-table td:nth-child(1) { width: 20% !important; text-align: left !important; }
table.items.document-unit-table th:nth-child(2),
table.items.document-unit-table td:nth-child(2) { width: 20% !important; text-align: center !important; }
table.items.document-unit-table th:nth-child(3),
table.items.document-unit-table td:nth-child(3) { width: 20% !important; text-align: center !important; }
table.items.document-unit-table th:nth-child(4),
table.items.document-unit-table td:nth-child(4) { width: 20% !important; text-align: right !important; }
table.items.document-unit-table th:nth-child(5),
table.items.document-unit-table td:nth-child(5) { width: 20% !important; text-align: right !important; }
CSS;
    }

    private function publicStorageDataUri(?string $relativePath): ?string
    {
        if (! $relativePath) return null;
        $paths = [storage_path('app/public/' . $relativePath), public_path('storage/' . $relativePath)];
        foreach ($paths as $path) {
            if (! is_file($path)) continue; $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($extension) { 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => mime_content_type($path) ?: 'image/jpeg' };
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
        }
        return null;
    }
}
