<?php

namespace App\Modules\Payroll\Services;

use RuntimeException;
use ZipArchive;

/**
 * Fills Sham Cash's "one to many" transfer template (Resources/templates/shamcash_one_to_many.xlsx) without
 * touching its layout, validations or controls: rows from 2 get NO / Full Name / Account / Amount / user_note,
 * and the side panel gets the currency (G1), the DocId (G2) and the note (G3). Totals are formulas that Excel
 * recalculates on open.
 */
class ShamCashFile
{
    public const MAX_ROWS = 1000;

    private const TEMPLATE = __DIR__.'/../Resources/templates/shamcash_one_to_many.xlsx';

    /** Cell styles of the template's sample row, reused for every data row. */
    private const STYLES = ['A' => 6, 'B' => 7, 'C' => 27, 'D' => 8, 'E' => 9];

    /**
     * @param  list<array{name: string, wallet: string, amount: float, note: string}>  $rows
     * @return string path of a temporary .xlsx file
     */
    public function build(array $rows, string $currency, string $docId, string $note): string
    {
        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('Sham Cash file takes 1 to '.self::MAX_ROWS.' rows.');
        }
        $path = tempnam(sys_get_temp_dir(), 'shamcash').'.xlsx';
        if (! copy(self::TEMPLATE, $path)) {
            throw new RuntimeException('Sham Cash template is missing.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open the Sham Cash template.');
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        foreach (array_values($rows) as $i => $row) {
            $r = $i + 2;
            $cells = $this->number('A', $r, $i + 1).$this->text('B', $r, $row['name']).$this->text('C', $r, $row['wallet'])
                .$this->number('D', $r, $row['amount']).$this->text('E', $r, $row['note']);
            $sheet = $this->replaceCells($sheet, $r, '[A-E]', $cells, prepend: true);
        }
        $sheet = $this->replaceCells($sheet, 1, 'G', '<c r="G1" s="5" t="inlineStr"><is><t>'.($currency === 'USD' ? 'دولار' : 'سوري').'</t></is></c>');
        $sheet = $this->replaceCells($sheet, 2, 'G', '<c r="G2" s="11"><v>'.$docId.'</v></c>');
        $sheet = $this->replaceCells($sheet, 3, 'G', '<c r="G3" s="14" t="inlineStr"><is><t>'.$this->escape(mb_substr($note, 0, 149)).'</t></is></c>');
        // Drop cached formula results (the sample's 1500 / 1) so nothing stale shows before recalculation.
        $sheet = preg_replace('#(<c r="G[45]"[^>]*><f>[^<]*</f>)<v>[^<]*</v>#', '$1', $sheet);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        $zip->deleteName('xl/calcChain.xml');
        $zip->addFromString('xl/_rels/workbook.xml.rels', preg_replace('#<Relationship [^>]*Target="calcChain.xml"/>#', '',
            $zip->getFromName('xl/_rels/workbook.xml.rels')));
        $zip->addFromString('[Content_Types].xml', str_replace('<Override PartName="/xl/calcChain.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.calcChain+xml"/>', '',
            $zip->getFromName('[Content_Types].xml')));
        $zip->addFromString('xl/workbook.xml', preg_replace('#<calcPr ([^>]*?)/>#', '<calcPr $1 fullCalcOnLoad="1"/>',
            $zip->getFromName('xl/workbook.xml')));
        $zip->close();

        return $path;
    }

    /** Replaces the cells of the given columns in row $r (keeping the row's other cells). */
    private function replaceCells(string $sheet, int $r, string $columns, string $cells, bool $prepend = false): string
    {
        $count = 0;
        $sheet = preg_replace_callback('#(<row r="'.$r.'"[^>]*>)(.*?)(</row>)#s', function (array $m) use ($r, $columns, $cells, $prepend) {
            $rest = preg_replace('#<c r="'.$columns.$r.'"[^>]*?(?:/>|>.*?</c>)#s', '', $m[2]);

            return $m[1].($prepend ? $cells.$rest : $rest.$cells).$m[3];
        }, $sheet, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException("Row $r is missing in the Sham Cash template.");
        }

        return $sheet;
    }

    private function number(string $col, int $r, float|int $value): string
    {
        $value = is_float($value) && floor($value) === $value ? (int) $value : $value;

        return '<c r="'.$col.$r.'" s="'.self::STYLES[$col].'"><v>'.$value.'</v></c>';
    }

    private function text(string $col, int $r, string $value): string
    {
        return '<c r="'.$col.$r.'" s="'.self::STYLES[$col].'" t="inlineStr"><is><t>'.$this->escape($value).'</t></is></c>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
