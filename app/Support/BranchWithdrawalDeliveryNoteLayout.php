<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Plant the product rows and the optional warehouse note before DomPDF renders
 * the branch delivery note. Both stay above the ownership statement/footer.
 */
final class BranchWithdrawalDeliveryNoteLayout
{
    private const FIRST_PAGE_TOP_MM = 132.0;
    private const NEXT_PAGE_TOP_MM = 27.0;
    private const CONTENT_BOTTOM_MM = 224.0;
    private const TABLE_HEADER_MM = 9.5;
    private const ROW_BASE_MM = 9.0;
    private const ROW_EXTRA_LINE_MM = 4.5;
    private const NOTE_HEADING_MM = 11.0;
    private const NOTE_LINE_MM = 5.5;
    private const NOTE_WRAP_WIDTH = 76;

    public static function paginate(Collection $items, string $note): Collection
    {
        $pages = [self::newPage(true)];

        foreach ($items as $item) {
            $height = self::rowHeight($item);
            $index = count($pages) - 1;
            $header = $pages[$index]['items']->isEmpty() ? self::TABLE_HEADER_MM : 0.0;

            if ($pages[$index]['used_mm'] + $header + $height > $pages[$index]['available_mm']) {
                $pages[] = self::newPage(false);
                $index = count($pages) - 1;
                $header = self::TABLE_HEADER_MM;
            }

            if ($header + $height > $pages[$index]['available_mm']) {
                throw new \RuntimeException('Produktposition passt nicht auf eine Filial-Lieferscheinseite.');
            }

            $pages[$index]['items']->push($item);
            $pages[$index]['used_mm'] += $header + $height;
        }

        $note = trim($note);
        if ($note === '') {
            return collect($pages);
        }

        $noteStarted = false;

        foreach (self::wrapNote($note) as $line) {
            $index = count($pages) - 1;
            $heading = $pages[$index]['note_lines'] === [] ? self::NOTE_HEADING_MM : 0.0;

            if ($pages[$index]['used_mm'] + $heading + self::NOTE_LINE_MM > $pages[$index]['available_mm']) {
                $pages[] = self::newPage(false);
                $index = count($pages) - 1;
                $heading = self::NOTE_HEADING_MM;
            }

            if ($pages[$index]['note_lines'] === []) {
                $pages[$index]['note_continued'] = $noteStarted;
            }

            $pages[$index]['note_lines'][] = $line;
            $pages[$index]['used_mm'] += $heading + self::NOTE_LINE_MM;
            $noteStarted = true;
        }

        return collect($pages);
    }

    private static function newPage(bool $first): array
    {
        $top = $first ? self::FIRST_PAGE_TOP_MM : self::NEXT_PAGE_TOP_MM;

        return [
            'first' => $first,
            'items' => collect(),
            'note_lines' => [],
            'note_continued' => false,
            'used_mm' => 0.0,
            'available_mm' => self::CONTENT_BOTTOM_MM - $top,
        ];
    }

    private static function rowHeight(object $item): float
    {
        $description = (string) ($item->description ?? '');
        $quantity = (string) ($item->quantity ?? '');
        $lines = 0;

        foreach (preg_split('/\R/u', $description) ?: [''] as $part) {
            $lines += max(1, (int) ceil(mb_strwidth($part, 'UTF-8') / 54));
        }

        $lines = max($lines, (int) ceil(mb_strwidth($quantity, 'UTF-8') / 22), 1);

        return self::ROW_BASE_MM + ($lines - 1) * self::ROW_EXTRA_LINE_MM;
    }

    private static function wrapNote(string $note): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $note) ?: [$note] as $paragraph) {
            if (trim($paragraph) === '') {
                $lines[] = '';
                continue;
            }

            $current = '';
            foreach (preg_split('/[ \t]+/u', trim($paragraph)) ?: [] as $word) {
                while (mb_strwidth($word, 'UTF-8') > self::NOTE_WRAP_WIDTH) {
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }

                    $chunk = mb_strimwidth($word, 0, self::NOTE_WRAP_WIDTH, '', 'UTF-8');
                    if ($chunk === '') {
                        break;
                    }

                    $lines[] = $chunk;
                    $word = mb_substr($word, mb_strlen($chunk, 'UTF-8'), null, 'UTF-8');
                }

                if ($word === '') {
                    continue;
                }

                if ($current === '') {
                    $current = $word;
                } elseif (mb_strwidth($current . ' ' . $word, 'UTF-8') <= self::NOTE_WRAP_WIDTH) {
                    $current .= ' ' . $word;
                } else {
                    $lines[] = $current;
                    $current = $word;
                }
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines;
    }
}
