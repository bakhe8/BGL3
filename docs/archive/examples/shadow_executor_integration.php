<?php

/**
 * Example: How to integrate ShadowExecutor into existing endpoints
 * 
 * This file demonstrates the pattern for adding dual-run to any
 * suggestion endpoint WITHOUT changing user experience.
 * 
 * DO NOT execute this file - it's a reference/template.
 */

// ============================================
// EXAMPLE 1: Simple Service Method
// ============================================

class ExampleController
{
    private ShadowExecutor $shadowExecutor;
    private LearningSuggestionService $legacyService;

    public function __construct()
    {
        // Initialize shadow executor (Phase 3)
        $authority = AuthorityFactory::create();
        $logger = new ComparisonLogger();
        $this->shadowExecutor = new ShadowExecutor($authority, $logger);
        
        // Legacy service (unchanged)
        $this->legacyService = new LearningSuggestionService();
    }

    /**
     * BEFORE (Phase 2):
     * ```php
     * public function getSuggestions(Request $request) {
     *     $input = $request->input('supplier_name');
     *     $suggestions = $this->legacyService->getSuggestions($input);
     *     return response()->json($suggestions);
     * }
     * ```
     * 
     * AFTER (Phase 3 - Dual Run):
     */
    public function getSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');

        // Execute BOTH Legacy + Authority, log comparison, return Legacy
        $suggestions = $this->shadowExecutor->execute(
            rawInput: $input,
            legacyCallable: fn() => $this->legacyService->getSuggestions($input)
        );

        // User receives UNCHANGED Legacy results
        return response()->json($suggestions);
    }
}

// ============================================
// EXAMPLE 2: Multiple Endpoints
// ============================================

class SupplierController
{
    private ShadowExecutor $shadowExecutor;

    /**
     * Endpoint 1: Learning-based suggestions
     */
    public function learningSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');

        $suggestions = $this->shadowExecutor->execute(
            rawInput: $input,
            legacyCallable: function() use ($input) {
                $service = new LearningSuggestionService();
                return $service->getSuggestions($input);
            }
        );

        return response()->json($suggestions);
    }

    /**
     * Endpoint 2: Fuzzy matching candidates
     */
    public function fuzzyCandidates(Request $request)
    {
        $input = $request->input('supplier_name');

        $candidates = $this->shadowExecutor->execute(
            rawInput: $input,
            legacyCallable: function() use ($input) {
                $service = new SupplierCandidateService();
                return $service->supplierCandidates($input);
            }
        );

        return response()->json($candidates);
    }

    /**
     * Endpoint 3: Level B (entity anchors)
     */
    public function levelBSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');

        $suggestions = $this->shadowExecutor->execute(
            rawInput: $input,
            legacyCallable: function() use ($input) {
                $service = new ArabicLevelBSuggestions();
                return $service->find($input);
            }
        );

        return response()->json($suggestions);
    }
}

// ============================================
// EXAMPLE 3: Conditional Shadow Execution
// ============================================

class SmartController
{
    private ShadowExecutor $shadowExecutor;

    public function getSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');

        // Check if user is in dual-run testing group (optional)
        $isDualRunEnabled = config('features.dual_run_enabled', false);
        
        // Or check user-specific flag
        // $isDualRunEnabled = $request->user()?->isInDualRunGroup() ?? false;

        if ($isDualRunEnabled) {
            // Dual run mode
            $suggestions = $this->shadowExecutor->execute(
                rawInput: $input,
                legacyCallable: fn() => $this->getLegacySuggestions($input)
            );
        } else {
            // Legacy only (no shadow)
            $suggestions = $this->getLegacySuggestions($input);
        }

        return response()->json($suggestions);
    }

    private function getLegacySuggestions(string $input): array
    {
        $service = new LearningSuggestionService();
        return $service->getSuggestions($input);
    }
}

// ============================================
// EXAMPLE 4: Error Handling
// ============================================

class RobustController
{
    private ShadowExecutor $shadowExecutor;
    private LearningSuggestionService $legacyService;

    public function getSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');

        try {
            // Shadow executor already catches Authority errors internally
            // So this try-catch is for Legacy errors (production)
            $suggestions = $this->shadowExecutor->execute(
                rawInput: $input,
                legacyCallable: fn() => $this->legacyService->getSuggestions($input)
            );

            return response()->json($suggestions);

        } catch (\Exception $e) {
            // Legacy failed (actual production error)
            // Authority errors are logged separately, don't affect this
            return response()->json([
                'error' => 'Failed to retrieve suggestions',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

// ============================================
// CONFIGURATION EXAMPLE
// ============================================

// config/features.php
return [
    'dual_run_enabled' => env('DUAL_RUN_ENABLED', false),
    'dual_run_sample_rate' => env('DUAL_RUN_SAMPLE_RATE', 1.0), // 1.0 = 100%, 0.5 = 50%
];

// .env
// DUAL_RUN_ENABLED=true
// DUAL_RUN_SAMPLE_RATE=1.0

// ============================================
// SAMPLING EXAMPLE (Optional)
// ============================================

class SampledController
{
    private ShadowExecutor $shadowExecutor;
    private float $sampleRate;

    public function __construct()
    {
        $this->shadowExecutor = new ShadowExecutor(...);
        $this->sampleRate = config('features.dual_run_sample_rate', 1.0);
    }

    public function getSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');

        // Sample: only execute shadow for X% of requests
        $shouldSample = (mt_rand() / mt_getrandmax()) < $this->sampleRate;

        if ($shouldSample) {
            $suggestions = $this->shadowExecutor->execute(
                rawInput: $input,
                legacyCallable: fn() => $this->getLegacySuggestions($input)
            );
        } else {
            $suggestions = $this->getLegacySuggestions($input);
        }

        return response()->json($suggestions);
    }

    private function getLegacySuggestions(string $input): array
    {
        return (new LearningSuggestionService())->getSuggestions($input);
    }
}
