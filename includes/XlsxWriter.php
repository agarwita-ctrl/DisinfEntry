<?php
/**
 * Minimal XLSX (Office Open XML) writer.
 *
 * Produces a genuine .xlsx workbook — a ZIP of OOXML parts — so Excel opens it
 * without the "file format and extension don't match" warning that an HTML
 * table renamed to .xls triggers. Depends only on ext-zip, which ships with
 * XAMPP, so the project stays Composer free.
 *
 * Strings are written inline (t="inlineStr"), which avoids maintaining a shared
 * string table and keeps memory flat for large exports.
 *
 * Usage:
 *   $x = new XlsxWriter('Entry Logs');
 *   $x->setColumns([14, 30, 12]);
 *   $x->addRow(['Title'], XlsxWriter::S_TITLE);
 *   $x->addHeader(['A', 'B', 'C']);
 *   $x->addRow(['text', 36.4, 'x'], [XlsxWriter::S_TEXT, XlsxWriter::S_NUMBER, XlsxWriter::S_CENTER]);
 *   $x->download('report.xlsx');
 */

declare(strict_types=1);

final class XlsxWriter
{
    /* ---- Style handles (indices into cellXfs, see styles()) ---- */
    public const S_DEFAULT   = 0;
    public const S_TITLE     = 1;   // large bold brand-green heading
    public const S_META      = 2;   // small grey caption
    public const S_HEADER    = 3;   // white on green, bordered, centred
    public const S_TEXT      = 4;   // bordered body text
    public const S_CENTER    = 5;   // bordered, centred
    public const S_HIGH      = 6;   // red fill — above threshold
    public const S_DENIED    = 7;   // red fill — access denied
    public const S_GRANTED   = 8;   // green text — access granted
    public const S_NUMBER    = 9;   // bordered, centred, one decimal place
    public const S_LABEL     = 10;  // bold bordered label
    public const S_SECTION   = 11;  // bold section heading, no border

    private string $sheetName;
    private string $rows = '';
    private int $rowNum = 0;
    private int $maxCol = 1;
    private string $colsXml = '';
    private int $freezeRow = 0;
    private ?array $autoFilter = null;

    public function __construct(string $sheetName = 'Sheet1')
    {
        // Excel forbids : \ / ? * [ ] in sheet names and caps them at 31 chars.
        $clean = preg_replace('/[:\\\\\/?*\[\]]/', '-', $sheetName) ?? 'Sheet1';
        $this->sheetName = mb_substr($clean, 0, 31) ?: 'Sheet1';
    }

    /* ============================================================
     * Layout
     * ========================================================== */

    /** @param array<int,float> $widths Column widths in character units. */
    public function setColumns(array $widths): void
    {
        $xml = '';
        foreach (array_values($widths) as $i => $w) {
            $xml .= sprintf('<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $i + 1, (float) $w);
        }
        $this->colsXml = $xml !== '' ? "<cols>$xml</cols>" : '';
        $this->maxCol = max($this->maxCol, count($widths));
    }

    /** Freezes every row above the current position, so headers stay visible. */
    public function freezeHere(): void
    {
        $this->freezeRow = $this->rowNum;
    }

    /* ============================================================
     * Rows
     * ========================================================== */

    /**
     * Appends a row.
     *
     * @param array<int,mixed>          $cells
     * @param int|array<int,int>        $style A single style for the whole row,
     *                                         or one style per cell.
     */
    public function addRow(array $cells, int|array $style = self::S_DEFAULT): void
    {
        $this->rowNum++;
        $cells = array_values($cells);
        $this->maxCol = max($this->maxCol, count($cells));

        $xml = '';
        foreach ($cells as $i => $value) {
            $s = is_array($style) ? ($style[$i] ?? self::S_DEFAULT) : $style;
            $xml .= $this->cell($this->colLetter($i + 1) . $this->rowNum, $value, $s);
        }

        $this->rows .= sprintf('<row r="%d">%s</row>', $this->rowNum, $xml);
    }

    public function addBlankRow(): void
    {
        $this->rowNum++;
        $this->rows .= sprintf('<row r="%d"/>', $this->rowNum);
    }

    /** Adds a styled header row and turns on the auto-filter for it. */
    public function addHeader(array $labels): void
    {
        $this->addRow($labels, self::S_HEADER);
        $this->autoFilter = [$this->rowNum, count($labels)];
    }

    /* ============================================================
     * Cell rendering
     * ========================================================== */

    private function cell(string $ref, mixed $value, int $style): string
    {
        // Blank cell: still emit it so borders and fills render.
        if ($value === null || $value === '') {
            return sprintf('<c r="%s" s="%d"/>', $ref, $style);
        }

        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        // Write real numbers as numbers so Excel can sum and chart them.
        if (is_int($value) || is_float($value)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, $style, $this->numeric($value));
        }

        $text = (string) $value;

        // A numeric-looking string still becomes text unless the caller asked
        // for a number style — that keeps IDs like "0912..." intact.
        if (($style === self::S_NUMBER) && is_numeric($text)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, $style, $this->numeric((float) $text));
        }

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $ref,
            $style,
            $this->esc(csv_safe($text))
        );
    }

    private function numeric(int|float $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (!is_finite($n)) {
            return '0';
        }
        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.') ?: '0';
    }

    /** Escapes XML text and strips control characters Excel rejects. */
    private function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function colLetter(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $index = (int) (($index - $rem - 1) / 26);
        }
        return $letters;
    }

    /* ============================================================
     * Package parts
     * ========================================================== */

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<workbookPr/>'
            . '<sheets><sheet name="' . $this->esc($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function coreProps(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->esc($this->sheetName) . '</dc:title>'
            . '<dc:creator>DisinfEntry</dc:creator>'
            . '<cp:lastModifiedBy>DisinfEntry</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>DisinfEntry</Application>'
            . '</Properties>';
    }

    /**
     * Style table. Fill 0 must be "none" and fill 1 must be "gray125" —
     * Excel treats those two slots as reserved.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'

            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0"/></numFmts>'

            . '<fonts count="8">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'                                                    // 0 default
            . '<font><b/><sz val="16"/><color rgb="FF0D7A5F"/><name val="Calibri"/></font>'                          // 1 title
            . '<font><sz val="9"/><color rgb="FF64748B"/><name val="Calibri"/></font>'                               // 2 meta
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'                          // 3 header
            . '<font><sz val="10"/><name val="Calibri"/></font>'                                                     // 4 body
            . '<font><b/><sz val="10"/><color rgb="FF991B1B"/><name val="Calibri"/></font>'                          // 5 alert
            . '<font><b/><sz val="10"/><color rgb="FF166534"/><name val="Calibri"/></font>'                          // 6 granted
            . '<font><b/><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'                          // 7 label
            . '</fonts>'

            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'                                                       // 0 reserved
            . '<fill><patternFill patternType="gray125"/></fill>'                                                    // 1 reserved
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D7A5F"/><bgColor indexed="64"/></patternFill></fill>'  // 2 header
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>'  // 3 high temp
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFDECEE"/><bgColor indexed="64"/></patternFill></fill>'  // 4 denied
            . '</fills>'

            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'                                           // 0 none
            . '<border>'
            . '<left style="thin"><color rgb="FFD0D7DE"/></left>'
            . '<right style="thin"><color rgb="FFD0D7DE"/></right>'
            . '<top style="thin"><color rgb="FFD0D7DE"/></top>'
            . '<bottom style="thin"><color rgb="FFD0D7DE"/></bottom>'
            . '<diagonal/></border>'                                                                                 // 1 thin
            . '</borders>'

            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'

            . '<cellXfs count="12">'
            // 0 default
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 title
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 2 meta
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 3 header
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1"'
            . ' applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 4 body text
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"'
            . ' applyAlignment="1"><alignment vertical="center"/></xf>'
            // 5 body centred
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"'
            . ' applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 6 high temperature
            . '<xf numFmtId="164" fontId="5" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1"'
            . ' applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 7 denied
            . '<xf numFmtId="0" fontId="5" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1"'
            . ' applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 8 granted
            . '<xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"'
            . ' applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 9 number, one decimal
            . '<xf numFmtId="164" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1"'
            . ' applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 10 bold label
            . '<xf numFmtId="0" fontId="7" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"'
            . ' applyAlignment="1"><alignment vertical="center"/></xf>'
            // 11 section heading
            . '<xf numFmtId="0" fontId="7" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'

            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/>'
            . '<tableStyles count="0" defaultTableStyle="TableStyleMedium2"/>'
            . '</styleSheet>';
    }

    /**
     * Worksheet part. Child element order follows the CT_Worksheet schema
     * sequence — Excel rejects the file if these are out of order.
     */
    private function sheet(): string
    {
        $lastRef = $this->colLetter($this->maxCol) . max(1, $this->rowNum);

        $views = '<sheetViews><sheetView workbookViewId="0"';
        if ($this->freezeRow > 0) {
            $views .= '><pane ySplit="' . $this->freezeRow . '" topLeftCell="A' . ($this->freezeRow + 1) . '"'
                . ' activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A' . ($this->freezeRow + 1) . '"'
                . ' sqref="A' . ($this->freezeRow + 1) . '"/></sheetView>';
        } else {
            $views .= '/>';
        }
        $views .= '</sheetViews>';

        $filter = '';
        if ($this->autoFilter !== null && $this->rowNum > $this->autoFilter[0]) {
            [$row, $cols] = $this->autoFilter;
            $filter = sprintf(
                '<autoFilter ref="A%d:%s%d"/>',
                $row,
                $this->colLetter($cols),
                $this->rowNum
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:' . $lastRef . '"/>'
            . $views
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $this->colsXml
            . '<sheetData>' . $this->rows . '</sheetData>'
            . $filter
            . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" paperSize="9"/>'
            . '</worksheet>';
    }

    /* ============================================================
     * Output
     * ========================================================== */

    /** Builds the workbook and returns the raw .xlsx bytes. */
    public function build(): string
    {
        $files = [
            '[Content_Types].xml'        => $this->contentTypes(),
            '_rels/.rels'                => $this->rootRels(),
            'docProps/core.xml'          => $this->coreProps(),
            'docProps/app.xml'           => $this->appProps(),
            'xl/workbook.xml'            => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml'              => $this->styles(),
            'xl/worksheets/sheet1.xml'   => $this->sheet(),
        ];

        // The zip extension is optional in PHP and is off in a stock XAMPP
        // php.ini; without it every export died with "Class ZipArchive not found".
        // The pure-PHP writer below produces the same container, so the export
        // works whether or not the extension is loaded.
        return class_exists('ZipArchive') ? $this->zipWithExtension($files) : $this->zipPure($files);
    }

    /** @param array<string,string> $files */
    private function zipWithExtension(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'disinf_xlsx_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file for the workbook.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not create the workbook archive.');
        }
        foreach ($files as $name => $data) {
            $zip->addFromString($name, $data);
        }
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Minimal ZIP writer (local headers + central directory), deflating each
     * entry when zlib is available and storing it otherwise.
     *
     * @param array<string,string> $files
     */
    private function zipPure(array $files): string
    {
        $dosTime = ((int) date('H') << 11) | ((int) date('i') << 5) | ((int) date('s') >> 1);
        $dosDate = (((int) date('Y') - 1980) << 9) | ((int) date('n') << 5) | (int) date('j');

        $body    = '';
        $central = '';
        $count   = 0;

        foreach ($files as $name => $data) {
            $crc  = crc32($data);
            $size = strlen($data);

            $packed = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
            if ($packed !== false && strlen($packed) < $size) {
                $method = 8;
            } else {
                $method = 0;
                $packed = $data;
            }
            $csize  = strlen($packed);
            $offset = strlen($body);
            $nameLen = strlen($name);

            // Local file header
            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate,
                          $crc, $csize, $size, $nameLen, 0) . $name . $packed;

            // Central directory record
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate,
                             $crc, $csize, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
            $count++;
        }

        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);

        return $body . $central . $end;
    }

    /** Streams the workbook to the browser as a download and exits. */
    public function download(string $filename): never
    {
        $bytes = $this->build();

        // Discard any stray output so the ZIP stream is not corrupted.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'export.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: max-age=0, must-revalidate');
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');

        echo $bytes;
        exit;
    }
}
