<?php
declare(strict_types=1);

namespace App\Services\Suggestions;

use App\Repositories\LearningRepository;
use App\Repositories\SupplierRepository;

/**
 * LearningSuggestionService
 * 
 * Orchestrates the Hybrid Learning System (ADR-009)
 */
class LearningSuggestionService
{
    private LearningRepository $learningRepo;
    private ArabicLevelBSuggestions $arabicService;
    private ConfidenceCalculator $calculator;
    private SupplierRepository $supplierRepo;

    public function __construct(
        LearningRepository $learningRepo,
        ArabicLevelBSuggestions $arabicService,
        ConfidenceCalculator $calculator,
        SupplierRepository $supplierRepo
    ) {
        $this->learningRepo = $learningRepo;
        $this->arabicService = $arabicService;
        $this->calculator = $calculator;
        $this->supplierRepo = $supplierRepo;
    }

    public function getSuggestions(string $rawName): array
    {
        // 1. Get Feedback (Confirmations & Rejections)
        $feedback = $this->learningRepo->getUserFeedback($rawName);
        
        $confirmationsMap = [];
        $rejectionsMap = [];
        
        foreach ($feedback as $f) {
            $sid = (int)$f['supplier_id'];
            if ($f['action'] === 'confirm') {
                $confirmationsMap[$sid] = (int)$f['count'];
            } elseif ($f['action'] === 'reject') {
                $rejectionsMap[$sid] = (int)$f['count'];
            }
        }

        // 2. Gather Candidates
        $candidates = [];

        // A. From Entity Anchors (Level B base)
        $anchorSuggestions = $this->arabicService->find($rawName);
        foreach ($anchorSuggestions as $sugg) {
            $id = $sugg['supplier_id'];
            
            // Note: We do NOT block anymore, just penalized later
            
            $candidates[$id] = [
                'id' => $id,
                'official_name' => $sugg['official_name'],
                'matched_anchor' => $sugg['matched_anchor'] ?? null,
                'source' => 'entity_anchor',
                'base_score' => 85
            ];
        }

        // B. From Learned Confirmations
        foreach ($confirmationsMap as $id => $count) {
             if (!isset($candidates[$id])) {
                $supplier = $this->supplierRepo->find($id);
                if ($supplier) {
                    $candidates[$id] = [
                        'id' => $id,
                        'official_name' => $supplier->officialName,
                        'matched_anchor' => null,
                        'source' => 'learned',
                        'base_score' => 65
                    ];
                }
            }
        }

        // C. From Historical
        $historical = $this->learningRepo->getHistoricalSelections($rawName);
        $historicalMap = [];
        foreach ($historical as $h) {
            $id = (int)$h['supplier_id'];
            $historicalMap[$id] = (int)$h['frequency'];
            
            if (!isset($candidates[$id])) {
                 $supplier = $this->supplierRepo->find($id);
                 if ($supplier) {
                    $candidates[$id] = [
                        'id' => $id,
                        'official_name' => $supplier->officialName,
                        'matched_anchor' => null,
                        'source' => 'historical',
                        'base_score' => 40
                    ];
                 }
            }
        }

        // 3. Calculate Confidence
        $results = [];
        foreach ($candidates as $id => $cand) {
            $confirms = $confirmationsMap[$id] ?? 0;
            $history = $historicalMap[$id] ?? 0;
            $rejections = $rejectionsMap[$id] ?? 0;
            
            if ($cand['source'] === 'historical' && $confirms > 0) {
                $cand['source'] = 'learned';
            }

            $score = $this->calculator->calculate(
                $cand['source'],
                $confirms,
                $history,
                $rejections // Penalize
            );
            
            $level = $this->calculator->getLevel($score);
            
            if ($level) {
                $reason = $this->buildReason($cand, $level, $confirms, $history, $rejections);
                
                $results[] = [
                    'id' => $id,
                    'official_name' => $cand['official_name'],
                    'confidence' => $score,
                    'score' => $score,
                    'level' => $level,
                    'source_type' => $cand['source'],
                    'matched_anchor' => $cand['matched_anchor'],
                    'confirmation_count' => $confirms,
                    'historical_count' => $history,
                    'rejection_count' => $rejections,
                    'reason_ar' => $reason
                ];
            }
        }

        usort($results, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        return $results;
    }

    private function buildReason(array $cand, string $level, int $confirms, int $history, int $rejections = 0): string
    {
        $parts = [];
        if ($rejections > 0) {
            $parts[] = "تم رفضه {$rejections} مرات";
        }
        if (!empty($cand['matched_anchor'])) {
            $parts[] = "كلمة مميزة: '{$cand['matched_anchor']}'";
        }
        
        if ($confirms > 0) {
            $parts[] = "تم تأكيده {$confirms} مرات";
        } elseif ($history > 0) {
            $parts[] = "تم اختياره {$history} مرات";
        }
        
        if ($level === 'D') {
             $parts[] = "بيانات تاريخية محدودة";
        }

        return implode(' + ', $parts);
    }
}
