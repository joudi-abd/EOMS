<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel-friendly CSV downloads: UTF-8 BOM for Arabic text and neutralised spreadsheet formulas. */
class CsvResponse
{
    /** @param iterable<array<int, scalar|null>> $rows */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $value = (string) $value;

        // A leading = + - @ (or tab/CR) would make Excel evaluate user text as a formula.
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && ! is_numeric($value) ? "'".$value : $value;
    }
}
