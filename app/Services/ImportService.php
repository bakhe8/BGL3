<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Models\Guarantee;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * V3 Import Service
 * 
 * Handles importing guarantees from Excel or manual entry
 * Simplified version for V3 (no sessions, uses import_source)
 */
class ImportService
{
    private GuaranteeRepository $guaranteeRepo;
    
    public function __construct(?GuaranteeRepository $guaranteeRepo = null)
    {
        $db = Database::connect();
        $this->guaranteeRepo = $guaranteeRepo ?? new GuaranteeRepository($db);
    }

    /**
     * Import from Excel file
     * 
     * @param string $filePath Path to uploaded Excel file
     * @param string $importedBy User who imported (default: 'system')
     * @return array Result with count, errors, skipped
     */
    public function importFromExcel(string $filePath, string $importedBy = 'system'): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('الملف غير موجود');
        }

        // Load Excel
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        if (count($rows) < 2) {
            throw new RuntimeException('الملف فارغ أو لا يحتوي على بيانات');
        }


        // Smart Header Detection: Try first 5 rows to find the actual headers
        $headerMap = null;
        $headerRowIndex = 0;
        
        for ($i = 0; $i < min(5, count($rows)); $i++) {
            $testMap = $this->detectColumns($rows[$i]);
            
            // If we found supplier AND bank columns, this is the header row!
            if (isset($testMap['supplier']) && isset($testMap['bank'])) {
                $headerMap = $testMap;
                $headerRowIndex = $i;
                break;
            }
        }
        
        if (!$headerMap || !isset($headerMap['supplier']) || !isset($headerMap['bank'])) {
            throw new RuntimeException('لم يتم العثور على عمود المورد أو البنك');
        }

        // Data rows (skip header and any rows before it)
        $dataRows = array_slice($rows, $headerRowIndex + 1);
        
        $imported = 0;
        $skipped = [];
        $errors = [];

        foreach ($dataRows as $index => $row) {
            $rowNumber = $index + 2; // Excel row number (1-indexed + header)
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                $skipped[] = "الصف #{$rowNumber}: فارغ";
                continue;
            }

            try {
                // Extract data
                $supplier = $this->getColumn($row, $headerMap['supplier']);
                $bank = $this->getColumn($row, $headerMap['bank']);
                $guaranteeNumber = $this->getColumn($row, $headerMap['guarantee'] ?? null);
                $amount = $this->normalizeAmount($this->getColumn($row, $headerMap['amount'] ?? null));
                $issueDate = $this->normalizeDate($this->getColumn($row, $headerMap['issue'] ?? null));
                $expiryDate = $this->normalizeDate($this->getColumn($row, $headerMap['expiry'] ?? null));
                $type = $this->getColumn($row, $headerMap['type'] ?? null);
                $contractNumber = $this->getColumn($row, $headerMap['contract'] ?? null);

                // Validation
                if (empty($supplier) || empty($bank)) {
                    $skipped[] = "الصف #{$rowNumber}: نقص المورد أو البنك";
                    continue;
                }

                if (empty($guaranteeNumber)) {
                    $skipped[] = "الصف #{$rowNumber}: نقص رقم الضمان";
                    continue;
                }

                // Build raw_data
                $rawData = [
                    'supplier' => $supplier,
                    'bank' => $bank,
                    'guarantee_number' => $guaranteeNumber,
                    'amount' => $amount,
                    'issue_date' => $issueDate,
                    'expiry_date' => $expiryDate,
                    'type' => $type ?: 'ابتدائي',
                    'contract_number' => $contractNumber,
                ];

                // Create Guarantee
                $guarantee = new Guarantee(
                    id: null,
                    guaranteeNumber: $guaranteeNumber,
                    rawData: $rawData,
                    importSource: 'excel_' . date('Ymd_His'),
                    importedAt: date('Y-m-d H:i:s'),
                    importedBy: $importedBy
                );

                $this->guaranteeRepo->create($guarantee);
                $imported++;

            } catch (\Throwable $e) {
                $errors[] = "الصف #{$rowNumber}: " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'total_rows' => count($dataRows),
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Create guarantee manually (from form)
     */
    public function createManually(array $data, string $createdBy = 'system'): int
    {
        // Validation
        if (empty($data['guarantee_number'])) {
            throw new RuntimeException('رقم الضمان مطلوب');
        }

        if (empty($data['supplier'])) {
            throw new RuntimeException('اسم المورد مطلوب');
        }

        if (empty($data['bank'])) {
            throw new RuntimeException('اسم البنك مطلوب');
        }

        // Build raw_data
        $rawData = [
            'supplier' => $data['supplier'],
            'bank' => $data['bank'],
            'guarantee_number' => $data['guarantee_number'],
            'amount' => isset($data['amount']) ? floatval($data['amount']) : 0,
            'issue_date' => $data['issue_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'type' => $data['type'] ?? 'ابتدائي',
            'contract_number' => $data['contract_number'] ?? null,
        ];

        $guarantee = new Guarantee(
            id: null,
            guaranteeNumber: $data['guarantee_number'],
            rawData: $rawData,
            importSource: 'manual_entry',
            importedAt: date('Y-m-d H:i:s'),
            importedBy: $createdBy
        );

        $created = $this->guaranteeRepo->create($guarantee);
        return $created->id;
    }

    /**
     * Detect Excel column mapping using smart keyword matching
     * Supports both Arabic and English column names with variations
     */
    private function detectColumns(array $headerRow): array
    {
        $keywords = [
            'supplier' => [
                'supplier', 'vendor', 'supplier name', 'vendor name', 'party name', 'contractor name',
                'المورد', 'اسم المورد', 'اسم الموردين', 'الشركة', 'اسم الشركة', 'مقدم الخدمة',
            ],
            'guarantee' => [
                'guarantee no', 'guarantee number', 'reference', 'ref no',
                'bank guarantee number', 'bank gurantee number', 'bank guaranty number',
                'gurantee no', 'gurantee number', 'bank gurantee', 'guranttee number',
                'رقم الضمان', 'رقم المرجع', 'مرجع الضمان',
            ],
            'type' => [
                'type', 'guarantee type', 'category',
                'نوع الضمان', 'نوع', 'فئة الضمان',
            ],
            'amount' => [
                'amount', 'value', 'total amount', 'guarantee amount',
                'المبلغ', 'قيمة الضمان', 'قيمة', 'مبلغ الضمان',
            ],
            'expiry' => [
                'expiry date', 'exp date', 'validity', 'valid until', 'end date', 'validity date',
                'تاريخ الانتهاء', 'صلاحية', 'تاريخ الصلاحية', 'ينتهي في',
            ],
            'issue' => [
                'issue date', 'issuance date', 'issued on', 'release date',
                'تاريخ الاصدار', 'تاريخ الإصدار', 'تاريخ التحرير', 'تاريخ الاصدار/التحرير',
            ],
            'contract' => [
                'contract number', 'contract no', 'contract #', 'contract reference', 'contract id',
                'agreement number', 'agreement no',
                'رقم العقد', 'رقم الاتفاقية', 'مرجع العقد',
            ],
            'bank' => [
                'bank', 'bank name', 'issuing bank', 'beneficiary bank',
                'البنك', 'اسم البنك', 'البنك المصدر', 'بنك الاصدار', 'بنك الإصدار',
            ],
        ];

        $map = [];
        
        foreach ($headerRow as $idx => $header) {
            $h = $this->normalizeHeader($header);
            
            // Protect against capturing guarantee columns as Bank
            $isGuaranteeish = str_contains($h, 'guarantee');
            
            foreach ($keywords as $field => $synonyms) {
                if ($field === 'bank' && $isGuaranteeish) {
                    continue;
                }
                
                foreach ($synonyms as $synonym) {
                    if (str_contains($h, $this->normalizeHeader($synonym))) {
                        // Store first match only (avoid duplicates)
                        if (!isset($map[$field])) {
                            $map[$field] = $idx;
                        }
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Normalize header for comparison
     */
    private function normalizeHeader(string|null $str): string
    {
        if ($str === null || $str === '') {
            return '';
        }
        
        $str = mb_strtolower($str);
        // Remove symbols, commas, dots
        $str = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $str);
        $str = preg_replace('/\s+/u', ' ', trim($str));
        return $str ?? '';
    }

    /**
     * Get column value safely
     */
    private function getColumn(array $row, ?int $index): string
    {
        if ($index === null || !isset($row[$index])) {
            return '';
        }

        return trim((string) $row[$index]);
    }

    /**
     * Normalize amount (remove commas, convert to float)
     */
    private function normalizeAmount(string $value): ?float
    {
        if (empty($value)) {
            return null;
        }

        // Remove commas and spaces
        $clean = str_replace([',', ' ', 'SAR', 'ريال'], '', $value);

        if (!is_numeric($clean)) {
            return null;
        }

        return round(floatval($clean), 2);
    }

    /**
     * Normalize date to Y-m-d format
     */
    private function normalizeDate(string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Try strtotime
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        // Try Excel serial number
        if (is_numeric($value)) {
            $unixDate = ($value - 25569) * 86400;
            return gmdate('Y-m-d', (int) $unixDate);
        }

        // Return as-is if unable to parse
        return $value;
    }

    /**
     * Validate import data before saving
     */
    public function validateImportData(array $data): array
    {
        $errors = [];

        if (empty($data['guarantee_number'])) {
            $errors[] = 'رقم الضمان مطلوب';
        }

        if (empty($data['supplier'])) {
            $errors[] = 'اسم المورد مطلوب';
        }

        if (empty($data['bank'])) {
            $errors[] = 'اسم البنك مطلوب';
        }

        if (isset($data['amount']) && !is_numeric($data['amount'])) {
            $errors[] = 'المبلغ يجب أن يكون رقماً';
        }

        return $errors;
    }

    /**
     * Preview Excel file contents without saving
     */
    public function previewExcel(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('الملف غير موجود');
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        if (count($rows) < 2) {
            throw new RuntimeException('الملف فارغ');
        }

        $headerMap = $this->detectColumns($rows[0]);
        $preview = [];

        // Preview first 10 rows
        $dataRows = array_slice($rows, 1, 10);

        foreach ($dataRows as $row) {
            $preview[] = [
                'supplier' => $this->getColumn($row, $headerMap['supplier'] ?? null),
                'bank' => $this->getColumn($row, $headerMap['bank'] ?? null),
                'guarantee_number' => $this->getColumn($row, $headerMap['guarantee'] ?? null),
                'amount' => $this->normalizeAmount($this->getColumn($row, $headerMap['amount'] ?? null)),
                'type' => $this->getColumn($row, $headerMap['type'] ?? null),
            ];
        }

        return [
            'headers' => $headerMap,
            'preview' => $preview,
            'total_rows' => count($rows) - 1,
        ];
    }
}
