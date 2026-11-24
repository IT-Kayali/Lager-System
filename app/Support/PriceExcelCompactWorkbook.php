<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PriceExcelCompactWorkbook
{
    private const MARKER = 'ALOWIDAT_PRICE_MATRIX_V1';

    private const MAX_SLOTS = 50000;

    private const MAX_COLUMNS = 500;

    private array $units = [];

    private int $count = 0;

    public function __construct(private array $groupOrder = []) {}

    public function append(array $values, string $unit): void
    {
        if (++$this->count > self::MAX_SLOTS) {
            self::reject('Das Kompaktformat ist auf 50.000 Preisfelder begrenzt. Bitte eine einzelne Kundengruppe oder das Standardformat verwenden.');
        }

        $key = self::columnKey($values);
        $id = (int) $values[9];
        $this->units[$unit]['label'] = $values[6];
        $this->units[$unit]['columns'][$key] = [
            $values[10], $values[1], $values[2], $values[3], $values[4], $values[5],
        ];
        $this->units[$unit]['products'][$id]['name'] = $values[0];
        if (isset($this->units[$unit]['products'][$id]['prices'][$key])) {
            self::reject('Mehrere Preisregeln haben denselben Mengenbereich. Bitte das Standardformat verwenden.');
        }

        $this->units[$unit]['products'][$id]['prices'][$key] = $values;
    }

    public function finish(string $path): void
    {
        $book = new Spreadsheet();
        try {
            $help = $book->getActiveSheet();
            $help->setTitle('Anleitung');
            foreach ([
                'ALOWIDAT – Kompakte Preisliste',
                'Nur die hellen Preisfelder ausfüllen. Graue Felder gehören zu keiner Preisregel.',
                'Leere Preise werden ignoriert; 0 wird übernommen. Maximal fünf Nachkommastellen.',
                'Keine Formeln, keine Änderungen an Überschriften oder ausgeblendeten Zuordnungen.',
                'Einheiten und Kundengruppen werden beim Export dynamisch aus der Datenbank geladen.',
                'Importvorschau prüfen und bestätigen. Alte Standarddateien bleiben gültig.',
            ] as $i => $line) {
                self::writeText($help, 1, $i + 1, $line);
            }
            $help->getColumnDimension('A')->setWidth(105);
            $help->getStyle('A1')->getFont()->setBold(true)->setSize(16);

            $slots = 0;
            foreach ($this->units as $unit) {
                $width = count($unit['columns']);
                $slots += $width * count($unit['products']);
                if ($width > self::MAX_COLUMNS || $slots > self::MAX_SLOTS) {
                    self::reject('Zu viele unterschiedliche Mengenbereiche für das Kompaktformat. Bitte einzelne Gruppen oder das Standardformat verwenden.');
                }
            }

            $names = ['anleitung' => true, 'preise' => true];
            uasort($this->units, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
            foreach ($this->units as $unit) {
                $name = self::sheetName($unit['label'], $names);
                $this->writeUnit($book->createSheet()->setTitle($name), $unit);
            }
            if ($book->getSheetCount() > 1) {
                $book->setActiveSheetIndex(1);
            }

            (new Xlsx($book))->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function writeUnit(Worksheet $sheet, array $unit): void
    {
        $columns = $unit['columns'];
        uasort($columns, function ($a, $b): int {
            return (($this->groupOrder[$a[0]] ?? $a[0]) <=> ($this->groupOrder[$b[0]] ?? $b[0]))
                ?: ((float) $a[4] <=> (float) $b[4])
                ?: (($a[5] === null ? INF : (float) $a[5]) <=> ($b[5] === null ? INF : (float) $b[5]))
                ?: strcmp($a[2], $b[2])
                ?: strcmp((string) $a[3], (string) $b[3]);
        });
        $products = $unit['products'];
        uasort($products, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $width = count($columns);

        self::writeText($sheet, 1, 1, 'Produkt');
        self::writeText($sheet, 1, 2, $unit['label']);
        self::writeText($sheet, 2, 1, self::MARKER);
        $sheet->setCellValue([2, 2], $width);
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setVisible(false);

        $colors = ['EAD99F', 'DFE2E7', 'CDE5F5', 'DFC1A5', 'DCD2EB'];
        $index = 0;
        $groupStart = 3;
        $previous = null;
        $groupIndex = -1;
        foreach ($columns as $key => $column) {
            $col = $index + 3;
            if ($previous !== $column[0]) {
                if ($previous !== null && $col - 1 > $groupStart) {
                    $sheet->mergeCells(self::address($groupStart, 1).':'.self::address($col - 1, 1));
                }
                $groupStart = $col;
                $previous = $column[0];
                $groupIndex++;
                self::writeText($sheet, $col, 1, (string) $column[1]);
            }
            self::writeText($sheet, $col, 2, self::rangeLabel($column));
            $sheet->getStyle(self::address($col, 1).':'.self::address($col, 2))
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colors[$groupIndex % count($colors)]);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(25);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col + $width))->setVisible(false);
            $index++;
        }
        if ($width + 2 > $groupStart) {
            $sheet->mergeCells(self::address($groupStart, 1).':'.self::address($width + 2, 1));
        }

        $row = 3;
        foreach ($products as $product) {
            self::writeText($sheet, 1, $row, $product['name']);
            $index = 0;
            foreach ($columns as $key => $column) {
                $col = $index++ + 3;
                $cell = self::address($col, $row);
                $values = $product['prices'][$key] ?? null;
                if ($values === null) {
                    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');

                    continue;
                }
                if ($values[7] !== null) {
                    $sheet->setCellValueExplicit($cell, (float) $values[7], DataType::TYPE_NUMERIC);
                }
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('0.00000');
                $values[7] = null;
                $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                self::writeText($sheet, $col + $width, $row, hash_hmac('sha256', $json, (string) config('app.key')).':'.$json);
            }
            $row++;
        }

        $sheet->getStyle('A1:'.self::address($width + 2, 2))->getFont()->setBold(true);
        $sheet->freezePane('C3');
    }

    public static function readRows(Spreadsheet $book): array
    {
        $rows = [];
        $sources = [];
        $slots = 0;
        $units = 0;
        foreach ($book->getWorksheetIterator() as $sheet) {
            if ($sheet->getTitle() === 'Anleitung') {
                continue;
            }
            $units++;
            if (self::readCell($sheet, 2, 1) !== self::MARKER) {
                self::reject('Unbekanntes Preisblatt: '.$sheet->getTitle());
            }
            $width = filter_var(self::readCell($sheet, 2, 2), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => self::MAX_COLUMNS],
            ]);
            $last = $sheet->getHighestDataRow();
            if ($width === false || $last > self::MAX_SLOTS + 2
                || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 2 + 2 * $width) {
                self::reject('Ungültiger Aufbau des kompakten Preisblatts.');
            }
            $slots += $width * max(0, $last - 2);
            if ($slots > self::MAX_SLOTS) {
                self::reject('Maximal 50.000 kompakte Preisfelder pro Import.');
            }

            $headers = [];
            $group = null;
            for ($col = 3; $col < $width + 3; $col++) {
                $title = self::readCell($sheet, $col, 1);
                if ($title !== null && $title !== '') {
                    $group = (string) $title;
                }
                $headers[$col] = [$group, self::readCell($sheet, $col, 2)];
            }

            for ($row = 3; $row <= $last; $row++) {
                $name = self::readCell($sheet, 1, $row);
                for ($col = 3; $col < $width + 3; $col++) {
                    $price = self::readCell($sheet, $col, $row);
                    if ($price === null || trim((string) $price) === '') {
                        continue;
                    }
                    $source = $sheet->getTitle().'!'.self::address($col, $row);
                    $encoded = (string) self::readCell($sheet, $col + $width, $row);
                    [$signature, $json] = array_pad(explode(':', $encoded, 2), 2, '');
                    if ($json === '' || ! hash_equals(hash_hmac('sha256', $json, (string) config('app.key')), $signature)) {
                        self::reject("{$source}: Keine gültige Preiszuordnung. Graue Felder dürfen nicht ausgefüllt werden.");
                    }
                    $values = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($values) || ! array_is_list($values) || count($values) !== count(PriceExcelImportPlan::HEADERS)
                        || (string) $name !== (string) $values[0]
                        || (string) self::readCell($sheet, 1, 2) !== (string) $values[6]
                        || $headers[$col] !== [(string) $values[1], self::rangeLabel([$values[10], $values[1], $values[2], $values[3], $values[4], $values[5]])]) {
                        self::reject("{$source}: Produkt, Einheit oder Spaltenüberschrift wurde verändert.");
                    }
                    $values[7] = $price;
                    $rows[] = $values;
                    $sources[count($rows) + 1] = $source;
                }
            }
        }
        if ($units === 0) {
            self::reject('Keine Einheitentabellen vorhanden.');
        }

        return [$rows, $sources];
    }

    private static function columnKey(array $values): string
    {
        return json_encode([
            (int) $values[10], $values[2], (string) $values[3],
            number_format((float) $values[4], 3, '.', ''),
            $values[5] === null ? null : number_format((float) $values[5], 3, '.', ''),
        ], JSON_THROW_ON_ERROR);
    }

    private static function rangeLabel(array $column): string
    {
        $min = rtrim(rtrim(number_format((float) $column[4], 3, ',', '.'), '0'), ',');
        $max = $column[5] === null ? null : rtrim(rtrim(number_format((float) $column[5], 3, ',', '.'), '0'), ',');
        $range = $max === null ? 'ab '.$min : $min.'–'.$max;

        return $range.($column[2] === 'staffel' ? ' ('.$column[3].')' : ' (manuell)');
    }

    private static function sheetName(string $name, array &$names): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\', "'"], '-', $name));
        $name = $name === '' ? 'Einheit' : $name;
        $base = mb_substr($name, 0, 31);
        $name = $base;
        $i = 1;
        while (isset($names[mb_strtolower($name)])) {
            $suffix = '-'.++$i;
            $name = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
        }
        $names[mb_strtolower($name)] = true;

        return $name;
    }

    private static function address(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column).$row;
    }

    private static function writeText(Worksheet $sheet, int $column, int $row, string $value): void
    {
        $sheet->setCellValueExplicit(self::address($column, $row), $value, DataType::TYPE_STRING);
    }

    private static function readCell(Worksheet $sheet, int $column, int $row): mixed
    {
        $cell = $sheet->getCell([$column, $row]);
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            self::reject($sheet->getTitle().'!'.self::address($column, $row).': Formeln sind nicht erlaubt.');
        }

        return $cell->getValue();
    }

    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['excel_file' => $message]);
    }
}
