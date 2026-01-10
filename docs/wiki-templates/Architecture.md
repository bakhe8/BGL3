# Architecture Overview

## 🏗️ System Architecture

BGL3 follows a **simple MVC-like pattern** without a framework.

```
Request → Router → Controller/Handler → Service Layer → Database
                                      ↓
                                    Response
```

---

## 📦 Core Components

### 1. Entry Point

- **`index.php`**: Main application page
- **`api/*.php`**: API endpoints

### 2. Core Layer (`app/Core/`)

- **`Database.php`**: SQLite connection and query builder
- **`Router.php`**: Simple routing (if implemented)
- **`Request.php`**: HTTP request handling

### 3. Service Layer (`app/Services/`)

- **`AIMatchingService.php`**: AI-powered supplier/bank matching
- **`LetterService.php`**: Letter generation (إفراج/تمديد/تخفيض)
- **`StatisticsService.php`**: Advanced analytics
- **`ExcelImportService.php`**: Excel file processing

### 4. Support Layer (`app/Support/`)

- **`Settings.php`**: Application configuration
- **`DateTime.php`**: Date utilities
- **`Helpers.php`**: Common helper functions

---

## 🗄️ Database

### Technology

- **SQLite 3**: Lightweight, file-based database
- **Location**: `database.db`

### Main Tables

1. **`batches`**: Import batches
2. **`guarantees`**: Individual guarantees
3. **`banks`**: Bank entities
4. **`suppliers`**: Supplier entities
5. **`ai_learning_events`**: ML training data
6. **`guarantee_events`**: History/audit log

See [Database Schema](Database-Schema) for details.

---

## 🎨 Frontend

### Stack

- **HTML**: Semantic markup
- **CSS**: Custom design system (no framework)
- **JavaScript**: Vanilla JS (no jQuery/React)

### Design System

- **`public/css/design-system.css`**: Variables + base styles
- **`public/css/components.css`**: Reusable components
- **`public/css/layout.css`**: Page layout
- **`public/css/batch-detail.css`**: Page-specific styles

See [Design System](Design-System) for details.

---

## 🔄 Data Flow

### Import Flow

```
Excel Upload → Parse → Validate → AI Match → Store → Review → Print
```

### AI Matching Flow

```
Raw Supplier Name → Similarity Check → Confidence Score → Suggest or Auto-match
                                                        ↓
                                                  User Confirms/Rejects
                                                        ↓
                                                   Learning Event
```

---

## 🔒 Security

- SQL injection protection via parameterized queries
- File upload validation (Excel only)
- No sensitive data in Git (`.env` for secrets)

---

## 📈 Scalability Considerations

**Current:** Single-user, local development
**Future:**

- Multi-tenant support
- MySQL/PostgreSQL migration
- API authentication

---

*For implementation details, see individual wiki pages.*
