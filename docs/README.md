# BGL System v3.0 - Documentation Center

## Why this system exists?
The BGL System (Bank Guarantee Lifecycle) v3.0 exists to manage the lifecycle of bank guarantees with a strict **Audit-First** and **Timeline-First** approach. It solves the problem of tracking the complex state changes of a guarantee (from import to decision to action) while maintaining a reliable, immutable history.

## What problem does it solve effectively?
1.  **State Management**: Distinguishes clearly between "Raw Imported Data" (Immutable) and "Operational Decisions" (Mutable).
2.  **Audit Trail**: detailed history of every event (System import, AI matching, User action).
3.  **Letter Generation**: Automates the creation of legal letters (Extension, Reduction, Release) based on the current state.
4.  **Data Integrity**: Ensures referential integrity between Guarantees, Suppliers, and Banks, even through re-imports and data cleanups.

## What it does NOT try to solve?
1.  **ERP Integration**: It is currently a standalone system, importing data via Excel rather than direct API integration with an ERP.
2.  **User Authentication/RBAC**: Currently assumes a single-tier access model (implied by "User" or "System" actors in history).
3.  **Complex Accounting**: It tracks amounts and dates but is not a general ledger.

## Core Philosophy
*   **Single Source of Truth**: The Database `guarantees` table (Raw) and `guarantee_decisions` (State).
*   **No Magic**: All UI states are derived from explicit database fields.
*   **Vanilla Implementation**: Zero frontend framework dependencies (No Alpine, No React) to ensure long-term maintainability.

> [!WARNING]
> **Audit Criticality**: This system is designed for forensic auditability. **Never** manually delete rows from `guarantee_history` or `guarantees` in the production database. Doing so breaks the chain of custody and invalidates the system's core purpose.
