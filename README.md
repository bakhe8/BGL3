# 🏦 BGL3 - Bank Guarantee Lifecycle System

![Version](https://img.shields.io/badge/version-1.0.1-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)
![SQLite](https://img.shields.io/badge/SQLite-Data-003B57.svg)
![Tailwind](https://img.shields.io/badge/Tailwind-CSS-06B6D4.svg)

**BGL3** is a state-of-the-art Bank Guarantee Management System designed to streamline the tracking, management, and lifecycle analysis of bank guarantees. It empowers organizations to move from manual tracking to a fully automated, intelligence-driven workflow.

---

## ✨ Features

- **🔄 Full Lifecycle Management**: Track guarantees from initial issuance (Bid Bond) to Final Performance, Advance Payment, and Release.
- **🧠 Smart Intelligence**:
    - **Auto-Matching**: Machine learning-inspired algorithms to match imported data with existing records.
    - **Predictive Suggestions**: Smart autocomplete for banks and suppliers based on historical data.
- **📊 Interactive Timeline**: Visual history of every action taken on a guarantee (extensions, reductions, claims).
- **📥 Universal Import**: Seamlessly import data from Excel/CSV with intelligent parsing and "Paste-to-Import" capabilities.
- **📈 Advanced Analytics**: Real-time dashboard showing status distribution, expiring guarantees, and financial exposure.

---

## 🚀 Quick Start

This application is designed to be a standalone desktop-like web application.

### ▶️ Running the Application
Double-click the **`start.bat`** file in the root directory.
> This will verify the environment, start the local PHP server on port `8000`, and open your default browser.

### ⏹️ Stopping the Server
To safely stop the server, use one of the following:
*   **`stop.bat`**: Runs in the terminal to kill the server process.
*   **`close.vbs`**: Runs silently (suitable for a Desktop Shortcut) to close the server without opening windows.

---

## 📚 Documentation

The technical documentation is located in the `docs/` directory.

### 🏗️ Architecture & Design
*   [**System Architecture**](docs/system-architecture.md): Overview of the Layered Architecture, Services, and Repositories.
*   [**Database Schema**](docs/database-schema.md): Complete relationship diagram and table definitions.
*   [**Database Model**](docs/database-model.md): Details on the SQLite implementation.
*   [**Authority Model**](docs/authority-model.md): Explanation of the "Authority" entity concept.

### 💻 Developer Guides
*   [**API Contracts**](docs/api-contracts.md): Specifications for the internal REST API endpoints.
*   [**UI & Behavior**](docs/ui-behavior-contract.md): Rules governing frontend interactions and state.
*   [**JavaScript Constraints**](docs/javascript-constraints.md): Coding standards for the Vanilla JS frontend.

---

## 🛠️ Technology Stack

*   **Backend**: PHP 8.0+ (Native Standalone Server)
*   **Database**: SQLite 3
*   **Frontend**: HTML5, Tailwind CSS, Vanilla JavaScript (ES6+)
*   **Architecture**: Server-Driven UI with Repository Pattern

---

## 📝 License

Private Proprietary Software.
Copyright © 2026. All Rights Reserved.
