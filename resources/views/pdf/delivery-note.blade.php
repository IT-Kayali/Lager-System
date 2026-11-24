<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 0; size: A4 portrait; }
        @font-face { font-family:'ExoPDF';font-style:normal;font-weight:400;src:url("file://{{ public_path('fonts/exo/Exo-Regular.ttf') }}") format("truetype"); }
        @font-face { font-family:'ExoPDF';font-style:normal;font-weight:700;src:url("file://{{ public_path('fonts/exo/Exo-Bold.ttf') }}") format("truetype"); }
        *{box-sizing:border-box}body{margin:0;padding:0;font-family:'ExoPDF',DejaVu Sans,sans-serif;color:#111;background:#fff}.page{position:relative;width:210mm;height:297mm;page-break-after:always;overflow:hidden;background:#fff}.page:last-child{page-break-after:auto}.background{position:absolute;inset:0;width:210mm;height:297mm;object-fit:cover;z-index:0}.content{position:relative;z-index:1;width:210mm;height:297mm}.logo{position:absolute;top:13mm;right:22mm;width:40mm;max-height:24mm;object-fit:contain}.sender-line{position:absolute;top:28mm;left:12mm;width:104mm;font-size:7.2pt;white-space:nowrap;overflow:hidden}.recipient{position:absolute;top:42mm;left:12mm;width:90mm;font-size:10.3pt;line-height:1.36}.meta{position:absolute;top:43mm;right:14mm;width:78mm;font-size:10pt;line-height:1.48}.meta table{width:100%;border-collapse:collapse}.meta td:first-child{width:32mm;font-weight:700}.title{position:absolute;top:92mm;left:12mm;right:12mm;text-align:center;font-size:18pt;font-weight:700}.intro{position:absolute;top:108mm;left:12mm;width:184mm;font-size:10.3pt;line-height:1.55}.items-wrap{position:absolute;left:12mm;top:132mm;width:184mm}.items-wrap.continuation{top:27mm}table.items{width:184mm;border-collapse:collapse;table-layout:fixed}table.items thead{display:table-header-group}table.items tbody{display:table-row-group}table.items tr{page-break-inside:avoid}table.items th{background:#f0f0f0;font-size:10pt;font-weight:700;line-height:1.25;padding:2.2mm 2mm;border-bottom:1px solid #777;overflow-wrap:break-word;word-wrap:break-word}table.items td{font-size:10pt;line-height:1.25;padding:2mm 2mm;border-bottom:1px solid #cfcfcf;vertical-align:top;overflow-wrap:break-word;word-wrap:break-word}table.items th:nth-child(1),table.items td:nth-child(1){width:102mm;text-align:left}table.items th:nth-child(2),table.items td:nth-child(2){width:48mm;text-align:center}table.items th:nth-child(3),table.items td:nth-child(3){width:34mm;text-align:center}.ownership{position:absolute;left:12mm;right:12mm;bottom:62mm;font-size:10pt;line-height:1.35}.footer{position:absolute;left:12mm;right:12mm;bottom:12mm;height:34mm;font-size:8pt;line-height:1.35}.footer-col{position:absolute;top:0;width:58mm}.footer-left{left:0}.footer-middle{left:70mm}.footer-right{right:0}.page-number{position:absolute;right:12mm;bottom:5mm;font-size:7.4pt;color:#777}
    </style>
</head>
<body>
@php
    $companyName=trim((string)($template->company_name?:'Alowidat Parfümerie'));
    $companyStreet=trim((string)(($template->company_street??'').' '.($template->company_house_number??'')));
    $companyCity=trim((string)(($template->company_postal_code??'').' '.($template->company_city??'')));
    $companyCountry=trim((string)($template->company_country??'Deutschland'));
    $companyAddressLines=array_values(array_filter([$companyStreet,$companyCity,$companyCountry],fn($line)=>trim((string)$line)!==''));
    $senderLine=trim($companyName.' – '.$companyStreet.' – '.$companyCity);
    $customer=$offer->customer;$recipientLines=[];
    if($customer){if(!empty($customer->company_name))$recipientLines[]=$customer->company_name;if(!empty($customer->contact_person))$recipientLines[]=$customer->contact_person;$streetLine=trim((string)(($customer->delivery_street??'').' '.($customer->delivery_house_number??'')));$cityLine=trim((string)(($customer->delivery_postal_code??'').' '.($customer->delivery_city??'')));$countryLine=trim((string)($customer->delivery_country??''));if($streetLine==='')$streetLine=trim((string)(($customer->billing_street??'').' '.($customer->billing_house_number??'')));if($cityLine==='')$cityLine=trim((string)(($customer->billing_postal_code??'').' '.($customer->billing_city??'')));if($countryLine==='')$countryLine=trim((string)($customer->billing_country??''));foreach([$streetLine,$cityLine,$countryLine] as $line)if($line!=='')$recipientLines[]=$line;if(count($recipientLines)<=1&&!empty($customer->billing_address))$recipientLines=array_values(array_filter(preg_split('/\R/u',trim((string)$customer->billing_address)),fn($line)=>trim((string)$line)!==''));}
    $customerNumber=$customer->customer_number??$customer->number??('KD-'.str_pad((string)($customer->id??0),5,'0',STR_PAD_LEFT));$shippingMethod=$offer->shipping_method?:'—';
    $formatQty=fn($value):string=>\App\Support\GermanNumber::format($value);
    $unitLabels=['gram'=>'Gramm','liter'=>'Liter','piece'=>'Stück'];
    $items=$offer->items->values()->map(function($item)use($formatQty,$unitLabels){$unit=trim((string)($item->product?->unit??''));$quantity=$formatQty($item->quantity);$product=$item->product_name??$item->description??($item->product?->name??'');return(object)['product'=>$product,'unit'=>$unit!==''?($unitLabels[$unit]??$unit):'—','quantity'=>$quantity];});
    $pages=\App\Support\DeliveryNoteLayout::paginate($items, (string)($offer->notes??''), trim((string)($customer?->delivery_note_instruction??''))!=='', true);$pageCount=$pages->count();
@endphp
@foreach($pages as $pageIndex=>$page)
<div class="page">@if(!empty($backgroundDataUri))<img class="background" src="{{ $backgroundDataUri }}" alt="">@endif<div class="content">
@if($page['first'])@if(!empty($logoDataUri))<img class="logo" src="{{ $logoDataUri }}" alt="">@endif<div class="sender-line">{{ $senderLine }}</div><div class="recipient">@foreach($recipientLines as $line){{ $line }}<br>@endforeach</div><div class="meta"><table><tr><td>Datum:</td><td>{{ now()->format('d.m.Y') }}</td></tr><tr><td>Kunden-Nr.:</td><td>{{ $customerNumber }}</td></tr><tr><td>Bestell-Nr.:</td><td>{{ $offer->offer_number }}</td></tr><tr><td>Versandart:</td><td>{{ $shippingMethod }}</td></tr></table></div><div class="title">Lieferschein</div><div class="intro">Sehr geehrte Damen und Herren,<br>vielen Dank für Ihre Bestellung. Wir liefern Ihnen wie vereinbart folgende Waren:</div>@endif
<div class="items-wrap {{ $page['first']?'':'continuation' }}">
@if($page['items']->isNotEmpty())
<table class="items"><thead><tr><th>Produkt</th><th>Menge</th><th>Einheit</th></tr></thead><tbody>@foreach($page['items'] as $item)<tr><td>{{ $item->product }}</td><td>{{ $item->quantity }}</td><td>{{ $item->unit }}</td></tr>@endforeach</tbody></table>
@endif
@if($page['note_lines'] !== [])
<div class="offer-delivery-note-footer-note" style="margin-top:5mm;font-size:9.5pt;line-height:1.35;color:#111;overflow-wrap:break-word;word-wrap:break-word;">
<strong>{{ $page['note_continued'] ? 'Hinweis (Fortsetzung):' : 'Hinweis:' }}</strong><br>
@foreach($page['note_lines'] as $line)@if(!$loop->first)<br>@endif{{ $line === '' ? ' ' : $line }}@endforeach
</div>
@endif
</div>
@if($loop->last)<div class="ownership">Die gelieferte Ware bleibt bis zur vollständigen Bezahlung unser Eigentum.</div>@endif
<div class="footer"><div class="footer-col footer-left"><strong>{{ $companyName }}</strong><br>@foreach($companyAddressLines as $line){{ $line }}<br>@endforeach</div><div class="footer-col footer-middle"><strong>Kontakt:</strong><br>@if($template->company_phone)Tel: {{ $template->company_phone }}<br>@endif @if($template->company_email)E-Mail: {{ $template->company_email }}<br>@endif @if($template->company_website)Web: {{ $template->company_website }}<br>@endif</div><div class="footer-col footer-right">@if($template->company_vat_id)USt-IdNr.: {{ $template->company_vat_id }}<br>@endif Vielen Dank für Ihr Vertrauen.</div></div><div class="page-number">Seite {{ $pageIndex+1 }} / {{ $pageCount }}</div></div></div>
@endforeach
</body></html>
