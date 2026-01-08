# System Architecture

## Layers
The system follows a strict **Layered Architecture** with a **Page Controller** pattern for the main interface.

### 1. Persistence Layer (Database)
*   **Technology**: SQLite
*   **Location**: `storage/database/app.sqlite`
*   **Role**: Ultimate source of truth.
*   **Access**: Strictly via `App\Support\Database` PDO connection.

### 2. Data Access Layer (Repositories)
*   **Location**: `app/Repositories/`
*   **Responsibilities**:
    *   Constructing SQL queries.
    *   Hydrating Models (`App\Models\*`).
    *   Isolating the rest of the application from SQL.
    *   *Key Files*: `GuaranteeRepository`, `GuaranteeDecisionRepository`.

### 3. Business Logic Layer (Services)
*   **Location**: `app/Services/`
*   **Responsibilities**:
    *   Complex business rules (e.g., `LetterBuilder`, `StatusEvaluator`).
    *   Orchestration of data (e.g., `TimelineDisplayService`).
    *   Data processing (e.g., `ImportService`).
*   **Rule**: Services MUST NOT output HTML directly (except for specific Server-Driven UI endpoints like suggestions).

### 4. Controller Layer (Page & API)
*   **Location**: Root (`index.php`) and `api/` directory.
*   **Responsibilities**:
    *   **Page Controllers** (`index.php`): Bootstrapping, dependency injection, and rendering the initial View.
    *   **API Controllers** (`api/*.php`): Handling HTTP input/output.
    *   **Delegation**: Controllers **MUST** delegate all complex logic to Services. They should not contain direct SQL or heavy business rules.
*   **Rule**: Controllers handle HTTP input/output only. Business logic is delegated to Services.

### 5. View Layer (Partials & Templates)
*   **Location**: `partials/`
*   **Responsibilities**:
    *   Rendering HTML based on provided data.
    *   Display logic (e.g., showing/hiding badges based on status).
*   **Rule**: specific logic should be pre-calculated in the Controller/Service before reaching the View.

### 6. Client Layer (JavaScript)
*   **Location**: `public/js/`
*   **Responsibilities**:
    *   DOM manipulation.
    *   Event handling.
    *   Server communication (Fetch API).
*   **Constraint**: **Vanilla JavaScript** only. No frameworks (Alpine.js removed).

---

## Data Flow
**Read Flow (Page Load):**
1.  User requests `index.php`.
2.  `index.php` connects to DB.
3.  `GuaranteeRepository` fetches `Guarantee` and `GuaranteeDecision`.
4.  `StatusEvaluator` service calculates the current status logic.
5.  `TimelineDisplayService` fetches and formats history.
6.  `index.php` includes `partials/record-form.php` and `partials/timeline-section.php` to render.

**Write Flow (Action):**
1.  User clicks "Save" in UI.
2.  JS (`records.controller.js`) sends JSON payload to `api/save-and-next.php`.
3.  API Controller validates input.
4.  `GuaranteeDecisionRepository` updates the DB.
5.  `TimelineRecorder` service logs the event to `guarantee_history` table.
6.  API returns JSON success.
7.  JS reloads or updates UI.

## Transformation Points
*   **Raw -> Model**: Occurs in Repositories (`hydrate`).
*   **Model -> View Data**: Occurs in `index.php` (Preparation of `$mockRecord`).
*   **DB Event -> Timeline Display**: Occurs in `TimelineDisplayService` (JSON decoding, icon mapping).
