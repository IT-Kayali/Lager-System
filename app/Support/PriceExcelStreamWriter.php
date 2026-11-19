<?php

namespace App\Support;

use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * XLSX-Schreiber ohne Zellenobjekte im RAM.
 * PhpSpreadsheet bleibt für das Einlesen der hochgeladenen Dateien zuständig.
 */
class PriceExcelStreamWriter
{
    private XMLWriter $sheet;

    private string $temporaryDirectory;

    private int $rowNumber = 1;

    public function __construct()
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/erp-price-' . bin2hex(random_bytes(12));
        if (! mkdir($this->temporaryDirectory, 0700)) {
            throw new RuntimeException('Temporäres Verzeichnis für den Excel-Export konnte nicht erstellt werden.');
        }

        $this->sheet = new XMLWriter();
        if (! $this->sheet->openUri($this->temporaryDirectory . '/sheet1.xml')) {
            throw new RuntimeException('Temporäres Excel-Blatt konnte nicht erstellt werden.');
        }
        $this->sheet->startDocument('1.0', 'UTF-8', 'yes');
        $this->sheet->startElement('worksheet');
        $this->sheet->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $this->sheet->startElement('cols');
        foreach ([32, 24, 16, 16, 18, 18, 18, 18, 26, 12, 12, 12] as $i => $width) {
            $this->sheet->startElement('col');
            $this->sheet->writeAttribute('min', (string) ($i + 1));
            $this->sheet->writeAttribute('max', (string) ($i + 1));
            $this->sheet->writeAttribute('width', (string) $width);
            $this->sheet->writeAttribute('customWidth', '1');
            if ($i >= 9) {
                $this->sheet->writeAttribute('hidden', '1');
            }
            $this->sheet->endElement();
        }
        $this->sheet->endElement();
        $this->sheet->startElement('sheetData');
        $this->writeRow(PriceExcelImportPlan::HEADERS, true);
    }

    public function append(array $values): void
    {
        $this->writeRow($values);
    }

    public function finish(string $path): void
    {
        $this->sheet->endElement(); // sheetData
        if ($this->rowNumber > 1) {
            $this->sheet->startElement('autoFilter');
            $this->sheet->writeAttribute('ref', 'A1:I' . ($this->rowNumber - 1));
            $this->sheet->endElement();
        }
        $this->sheet->endElement(); // worksheet
        $this->sheet->endDocument();
        $this->sheet->flush();

        $this->writeHelpSheet();

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Die Excel-Datei konnte nicht angelegt werden.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML);
            $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
            $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Preise" sheetId="1" r:id="rId1"/><sheet name="Anleitung" sheetId="2" r:id="rId2"/></sheets>
</workbook>
XML);
            $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML);
            $zip->addFromString('xl/styles.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="2"><numFmt numFmtId="164" formatCode="0.00000"/><numFmt numFmtId="165" formatCode="0.000"/></numFmts>
<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>
<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML);
            $zip->addFile($this->temporaryDirectory . '/sheet1.xml', 'xl/worksheets/sheet1.xml');
            $zip->addFile($this->temporaryDirectory . '/sheet2.xml', 'xl/worksheets/sheet2.xml');
        } finally {
            $success = $zip->close();
            $this->cleanup();
        }

        if (! $success) {
            throw new RuntimeException('Der Excel-Export konnte nicht abgeschlossen werden.');
        }
    }

    private function writeRow(array $values, bool $header = false): void
    {
        $line = $this->rowNumber++;
        $this->sheet->startElement('row');
        $this->sheet->writeAttribute('r', (string) $line);
        foreach (array_values($values) as $index => $value) {
            if ($value === null) {
                continue;
            }

            $column = chr(65 + $index);
            $this->sheet->startElement('c');
            $this->sheet->writeAttribute('r', $column . $line);
            if ($header) {
                $this->sheet->writeAttribute('s', '3');
            } elseif ($index === 7) {
                $this->sheet->writeAttribute('s', '1');
            } elseif ($index === 4 || $index === 5) {
                $this->sheet->writeAttribute('s', '2');
            }

            if (! $header && in_array($index, [4, 5, 7, 9, 10, 11], true)) {
                $this->sheet->writeElement('v', (string) $value);
            } else {
                $this->sheet->writeAttribute('t', 'inlineStr');
                $this->sheet->startElement('is');
                $this->sheet->writeElement('t', (string) $value);
                $this->sheet->endElement();
            }
            $this->sheet->endElement();
        }
        $this->sheet->endElement();
    }

    private function writeHelpSheet(): void
    {
        $writer = new XMLWriter();
        $writer->openUri($this->temporaryDirectory . '/sheet2.xml');
        $writer->startDocument('1.0', 'UTF-8', 'yes');
        $writer->startElement('worksheet');
        $writer->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $writer->startElement('sheetData');
        foreach ([
            'Preis-Excel: Anleitung',
            'Nur die Spalte H (price) bearbeiten. Alle anderen Spalten und die versteckten IDs unverändert lassen.',
            'Preisfelder unterstützen fünf Nachkommastellen (z. B. 0,12345).',
            'Leere Preise überspringen die Zeile, der Preis 0 wird übernommen.',
            'Für neue Kundengruppen und Preisstaffeln immer eine neue Vorlage herunterladen.',
            'Fehlt fuer ein manuell bepreistes Produkt eine Regel, steht eine neue Zeile ab 1 Einheit bereit (Preis-ID 0).',
            'Import: Vorschau prüfen und gewünschte Zeilen bestätigen. Es wird nichts gelöscht.',
        ] as $i => $line) {
            $row = $i + 1;
            $writer->startElement('row');
            $writer->writeAttribute('r', (string) $row);
            $writer->startElement('c');
            $writer->writeAttribute('r', 'A' . $row);
            $writer->writeAttribute('t', 'inlineStr');
            $writer->startElement('is');
            $writer->writeElement('t', $line);
            $writer->endElement();
            $writer->endElement();
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    private function cleanup(): void
    {
        @unlink($this->temporaryDirectory . '/sheet1.xml');
        @unlink($this->temporaryDirectory . '/sheet2.xml');
        @rmdir($this->temporaryDirectory);
    }
}
