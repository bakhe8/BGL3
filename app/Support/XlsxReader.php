<?php
declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class XlsxReader
{
    /**
     * قراءة ملف XLSX باستخدام PhpSpreadsheet
     *
     * @return array<int, array<int, string|null>>
     */
    public function read(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        // Safety: Limit column reading to first 100 columns (A to CV)
        // This prevents memory exhaustion issues when files have "used" cells in far columns (e.g., XFD)
        $filter = new ColumnReadFilter(1, 100000, range(1, 100));
        $reader->setReadFilter($filter);

        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        // If highestCol is beyond our limit, fallback to mapped limit
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
        $highestColIndex = min($highestColIndex, 100);

        $rows = [];
        for ($row = 1; $row <= $highestRow; $row++) {
            $cells = [];
            for ($col = 1; $col <= $highestColIndex; $col++) {
                $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                $cells[] = $sheet->getCell($coord)->getFormattedValue();
            }
            // Strict check: if row is completely empty (all null/empty strings), skip it
            // Note: getFormattedValue returns '' for empty cells usually
            $nonEmpty = array_filter($cells, fn($v) => $v !== null && trim((string) $v) !== '');
            if ($nonEmpty) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }
}

/**
 * Filter to read only specific columns and rows
 */
class ColumnReadFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter
{
    private $startRow;
    private $endRow;
    private $allowedColumns;

    public function __construct($startRow, $endRow, $allowedColumnsIndices)
    {
        $this->startRow = $startRow;
        $this->endRow = $endRow;
        $this->allowedColumns = $allowedColumnsIndices;
    }

    public function readCell($columnAddress, $row, $worksheetName = '')
    {
        // Check Row Range
        if ($row < $this->startRow || $row > $this->endRow) {
            return false;
        }

        // Check Column Range (Convert Address 'A' -> Index 1)
        $colIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress);
        return in_array($colIndex, $this->allowedColumns);
    }
}
