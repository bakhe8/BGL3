# BGL3 Business Rules & Logic (Comprehensive)

## 1. Domain Object: Bank Guarantee (BG)

The system manages **Letter of Guarantees** (BGs) issued by banks to beneficiaries on behalf of suppliers.

### Lifecycle Phases

1. **Issue (إصدار)**: Initial creation of the BG.
2. **Extend (تجديد/تجميد)**: Extending the validity date or increasing the value.
3. **Reduce (تخفيض)**: Decreasing the BG amount.
4. **Release (إطلاق/إغلاق)**: Final closure of the BG when the obligation ends.

### Prohibited Concepts (Hallucinations)

- **NO Financial Payments**: "Batches" (الدفعات) refers to batches of documents, NOT money transfers.
- **NO Loans/Credit Cards**: This is a documentation system, not a retail banking app.

---

## 2. Artificial Intelligence & Trust

The system uses "Hybrid Intelligence" to match raw extracted text to official entities.

### Trust Levels (Confidence)

- **Level A (Excellent)**: Score > 95%. Eligible for `auto_match`.
- **Level B (Good)**: Score 70% - 95%. Requires review but likely correct.
- **Level C (Fair)**: Score 40% - 70%. Needs significant verification.
- **Level D (Poor)**: Score < 40%. Highly likely to be a different entity or gibberish.

### The Conflict Delta

If the difference between the top suggestion and the second suggestion is less than **0.1 (10%)**, a "Conflict" is flagged for manual resolution.

---

## 3. Operational Constraints

- **Production Mode**: When enabled (`PRODUCTION_MODE=true`):
  - Test data (`is_test_data=1`) is hidden from all reports/views.
  - Maintenance/Delete tools are disabled.
  - Manual creation of test batches is blocked.
- **Immutable Source**: `raw_data` in the `guarantees` table is the immutable original source. The system only updates the `bank` field within this JSON if a 100% match is found.

---

## 4. UI/UX Directives

- **Arabic First**: All primary UI labels and AI reasoning intended for the user must be in formal Arabic.
- **Smart Paste**: Users can paste raw text; the system must extract fields (`ParseCoordinatorService`) before proceeding to the lifecycle.
- **Action Previews**: Letters (`LetterBuilder`) must be previewed and confirmed by the user before the action is finalized in the database.
