<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Plant delivery-note pages in millimetres before DomPDF renders them.
 * The note is in normal flow after the table, never absolutely overlaid.
 */
final class DeliveryNoteLayout
{
    public static function paginate(Collection $items, string $note, bool $hasCustomerInstruction, bool $branded): Collection
    {
        $pages = [self::blankPage(true, $hasCustomerInstruction, $branded)];

        foreach ($items as $item) {
            $height = self::rowHeight($item);
            $index = count($pages) - 1;
            $header = $pages[$index]['items']->isEmpty() ? 11.0 : 0.0;

            if ($pages[$index]['used_mm'] + $header + $height > $pages[$index]['available_mm']) {
                // Never place an oversized row across the ownership/footer zone.
                if ($pages[$index]['items']->isEmpty()) {
                    throw new \RuntimeException('Produktposition ist für eine einzelne Lieferscheinseite zu lang.');
                }
                $pages[] = self::blankPage(false, $hasCustomerInstruction, $branded);
                $index = count($pages) - 1;
                $header = 11.0;
                if ($header + $height > $pages[$index]['available_mm']) {
                    throw new \RuntimeException('Produktposition ist für eine einzelne Lieferscheinseite zu lang.');
                }
            }

            $pages[$index]['items']->push($item);
            $pages[$index]['used_mm'] += $header + $height;
        }

        $lines = self::wrapNote(trim($note), $branded ? 64 : 58);
        $written = 0;
        $lineHeight = $branded ? 5.5 : 5.7;

        foreach ($lines as $line) {
            $index = count($pages) - 1;
            // The first line of a note also needs a gap and a label line.
            $startHeight = $pages[$index]['note_lines'] === [] ? 11.0 : 0.0;
            $needed = $startHeight + $lineHeight;

            if ($pages[$index]['used_mm'] + $needed > $pages[$index]['available_mm']) {
                $pages[] = self::blankPage(false, $hasCustomerInstruction, $branded);
                $index = count($pages) - 1;
                $startHeight = 11.0;
            }

            if ($pages[$index]['note_lines'] === []) {
                $pages[$index]['note_continued'] = $written > 0;
            }
            $pages[$index]['note_lines'][] = $line;
            $pages[$index]['used_mm'] += $startHeight + $lineHeight;
            $written++;
        }

        return collect($pages);
    }

    private static function blankPage(bool $first, bool $instruction, bool $branded): array
    {
        $top = $branded ? ($first ? ($instruction ? 137.0 : 132.0) : 27.0) : ($first ? 132.0 : 30.0);
        // Branded pages keep the ownership statement at 235 mm and
        // the three-column footer below it. The safe content end is 214 mm.
        // No-logo pages keep clear of the bottom page number.
        $bottom = $branded ? 214.0 : 259.0;

        return [
            'first' => $first,
            'items' => collect(),
            'note_lines' => [],
            'note_continued' => false,
            'used_mm' => 0.0,
            'available_mm' => $bottom - $top,
        ];
    }

    private static function rowHeight(object $item): float
    {
        $product = (string) ($item->product ?? '');
        $quantity = (string) ($item->quantity ?? '');
        $unit = (string) ($item->unit ?? '');
        $productLines = 0;
        foreach (preg_split('/\R/u', $product) ?: [''] as $line) {
            $productLines += max(1, (int) ceil(mb_strwidth($line, 'UTF-8') / 39));
        }
        $lines = max($productLines, (int) ceil(mb_strwidth($quantity, 'UTF-8') / 14), (int) ceil(mb_strwidth($unit, 'UTF-8') / 11), 1);
        // Conservative estimate: padding + line-height + safety margin.
        return 10.0 + (($lines - 1) * 6.1);
    }

    private static function wrapNote(string $note, int $width): array
    {
        if ($note === '') return [];
        $result = [];
        $paragraphs = preg_split('/\R/u', $note) ?: [$note];

        foreach ($paragraphs as $paragraph) {
            if (trim($paragraph) === '') {
                $result[] = '';
                continue;
            }
            $words = preg_split('/[ \t]+/u', trim($paragraph)) ?: [];
            $line = '';

            foreach ($words as $word) {
                while (mb_strwidth($word, 'UTF-8') > $width) {
                    if ($line !== '') { $result[] = $line; $line = ''; }
                    $chunk = mb_strimwidth($word, 0, $width, '', 'UTF-8');
                    if ($chunk === '') break;
                    $result[] = $chunk;
                    $word = mb_substr($word, mb_strlen($chunk, 'UTF-8'), null, 'UTF-8');
                }
                if ($word === '') continue;
                if ($line === '') { $line = $word; continue; }
                if (mb_strwidth($line . ' ' . $word, 'UTF-8') <= $width) {
                    $line .= ' ' . $word;
                } else {
                    $result[] = $line;
                    $line = $word;
                }
            }
            if ($line !== '') $result[] = $line;
        }
        return $result;
    }
}
