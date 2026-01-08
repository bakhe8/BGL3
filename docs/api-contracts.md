# API Contracts

## Overview
All API endpoints serve as functional controllers. They accept JSON/GET inputs and return JSON/HTML.
**Authentication**: Currently strictly internal (Session/IP based irrelevant for this scope, effectively "Single User").

## Endpoints

### 1. Save & Next (`/api/save-and-next.php`)
*   **Method**: `POST`
*   **Purpose**: Saves the current decision (Supplier selection) and navigates to the next record.
*   **Input**:
    ```json
    {
      "guarantee_id": "123",
      "supplier_id": "55",
      "supplier_name": "Official Supplier Name",
      "current_index": 1,
      "status_filter": "all"
    }
    ```
*   **Process**:
    1.  Validates Supplier presence (Required).
    2.  Check for Duplicate Import (Logic depends on `ImportService`).
    3.  Updates `guarantee_decisions` (Lazy create if missing).
    4.  Clears `active_action` if data changed (ADR-007 behavior).
    5.  Records `decision` event in `guarantee_history`.
    6.  Records `status_change` event if status changed.
    7.  Updates `learning_confirmations` via `LearningRepository`.
*   **Output** (JSON):
    ```json
    {
      "success": true,
      "finished": false,
      "record": {
          "id": 123,
          "guarantee_number": "G-100",
          "supplier_name": "Supplier Corp",
          "bank_name": "Bank Name",
          "bank_id": 5,
          "amount": 50000,
          "expiry_date": "2025-12-31",
          "issue_date": "2024-01-01",
          "contract_number": "C-999",
          "type": "FINAL",
          "status": "pending|ready"
      },
      "banks": [
          { "id": 1, "official_name": "Al Rajhi Bank" },
          { "id": 2, "official_name": "NCB" }
      ],
      "currentIndex": 5,
      "totalRecords": 100
    }
    ```
*   **Evidence**: `api/save-and-next.php` (Lines 400-407).

### 2. Extend Guarantee (`/api/extend.php`)
*   **Method**: `POST`
*   **Purpose**: Sets active action to 'extension', calculates new expiry (+1 year), and returns updated HTML form.
*   **Input**:
    ```json
    { "guarantee_id": "123" }
    ```
*   **Gates**:
    *   **Lock Check**: `is_locked` MUST be false.
    *   **Status Check**: `status` MUST be 'ready'.
*   **Process**:
    1.  snapshots current state.
    2.  Updates `guarantees.raw_data.expiry_date`.
    3.  Sets `active_action` = 'extension'.
    4.  Records `modified` event (subtype: extension).
*   **Output**: `HTML Fragment` (The entire `decision-card`).
*   **Evidence**: `api/extend.php`.

### 3. Supplier Suggestions (`/api/suggestions-learning.php`)
*   **Method**: `GET`
*   **Purpose**: Returns HTML fragments for supplier chips.
*   **Input**: `?raw=part_of_name&guarantee_id=123`
*   **Output**: `HTML String` (Button elements).
*   **Evidence**: `api/suggestions-learning.php`.

### 4. Release Guarantee (`/api/release.php`)
*   **Method**: `POST`
*   **Purpose**: Finalizes and locks the guarantee.
*   **Input**:
    ```json
    { "guarantee_id": "123", "reason": "Optional" }
    ```
*   **Gates**:
    *   **Status Check**: `status` MUST be 'ready' (Lines 31-45).
    *   **Lock Check**: `is_locked` MUST be false (Lines 63-65).
*   **Process**:
    1.  Snapshots current state (`TimelineRecorder::createSnapshot`).
    2.  Checks if `supplier_id` and `bank_id` are present.
    3.  Sets `is_locked = 1` and `status = 'released'` in DB.
    4.  Sets `active_action = 'release'`.
    5.  Records `release` event in `guarantee_history` (UE-04).
*   **Output**: `HTML Fragment` (The entire `decision-card` with "Released" banner).
*   **Evidence**: `api/release.php` (Lines 15-111).

## Error Handling
## Evidence Index
*   **Save Logic**: `api/save-and-next.php` (Lines 219-231).
*   **Extend Logic**: `api/extend.php` (Lines 80-120).
*   **Suggestions Logic**: `api/suggestions-learning.php` (Lines 27-50).
*   **Learning Integration**: `App/Repositories/LearningRepository.php` (Called in `save-and-next.php` line 294).
