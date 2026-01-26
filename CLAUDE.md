# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Catcon** is a Laravel 12 application for a freight transport company (Inversiones Catcon) that manages invoices, dispatch notes (guías de remisión), and integrates with Peru's SUNAT electronic invoicing system through Nubefact OSE.

## Common Commands

```bash
# Development server (runs Laravel server, queue, logs, and Vite concurrently)
composer dev

# Run all tests
composer test

# Run specific test file
php artisan test tests/Feature/ExampleTest.php

# Run specific test method
php artisan test --filter test_example

# Frontend build
npm run build
npm run dev

# Database operations
php artisan migrate
php artisan db:seed

# Filament Shield permissions
php artisan shield:generate --all

# Custom commands
php artisan check:vehicle-expirations  # Check vehicle document expirations
php artisan test:sunat-services        # Test SUNAT API connectivity
```

## Architecture

### Core Domain Models

- **Invoice** - Electronic invoices (facturas) sent to SUNAT via Nubefact
- **Despatch** - Transport dispatch notes (GRE Transportista) for SUNAT
- **Client** - Customers (destinatarios) and senders (remitentes)
- **Driver** - Transport drivers with license info (soft deletes enabled)
- **Vehicle** - Fleet vehicles with plate numbers
- **OperationalExpense** - Trip expenses (tolls, loading, travel allowances)
- **AccountReceivable** - Tracks invoice payments and installments
- **Company** - Company issuing invoices (remitente)
- **FrequentLocation** - Saved locations for quick address matching
- **Service** - Service catalog with descriptions and codes

### Key Service Layer (`app/Services/`)

**SUNAT/Nubefact Integration:**
- `InvoiceService` - Sends invoices to Nubefact OSE, handles CDR responses
- `SunatDespatchService` - Orchestrates GRE sending to SUNAT API
- `SunatXmlGenerator` - Generates UBL 2.1 XML for dispatch notes
- `SunatHttpClient` - Handles SUNAT API authentication and requests
- `SunatCdrService` - Processes CDR (Constancia de Recepción) responses

**Invoice Builders (`app/Services/Invoice/Builders/`):**
- Uses Builder pattern for different invoice types
- `NormalInvoiceBuilder` - Standard invoices
- `DetractionInvoiceBuilder` - Invoices with SUNAT detraction
- `CreditInvoiceBuilder` - Credit invoices
- `CreditDetractionInvoiceBuilder` - Credit invoices with detraction

**Despatch Import (`app/Services/DespatchImport/`):**
- `SunatXmlImportService` - Imports GRE from SUNAT XML files
- `SunatXmlParser` - Parses UBL XML structure
- `FrequentLocationMatcher` - Matches addresses to saved locations
- `DespatchCalculator` - Calculates operational expenses
- `DespatchFactory` - Creates Despatch models from parsed data
- `DespatchEntityManager` - Manages related entities (clients, vehicles, drivers)

**Additional Services:**
- `InvoicePdfService` - Generates PDF documents for invoices
- `NubefactService` - Handles Nubefact OSE API communication
- `SunatAuthService` - Manages SUNAT API authentication tokens
- `SunatZipService` - Handles ZIP compression/decompression for SUNAT
- `UbigeoService` - Peruvian geographic location code management
- `DespatchService` - Business logic for despatch operations

### Admin Panel

Built with **Filament 3** at `/admin`. Key resources:
- `InvoiceResource` - Create invoices linked to dispatch notes, send to SUNAT
- `DespatchResource` - Manage transport dispatch notes, import XML, send to SUNAT
- `OperationalExpenseResource` - Track trip expenses
- `AccountReceivableResource` - Manage invoice payments and receivables
- `ClientResource`, `DriverResource`, `VehicleResource` - Master data management
- `FrequentLocationResource` - Manage frequent shipping locations
- `ServiceResource` - Service catalog management

Uses **Filament Shield** for role-based permissions (policies in `app/Policies/`).

### Configuration

- `config/greenter.php` - SUNAT credentials, certificate path, API endpoints
- Uses Greenter library via `codersfree/laravel-greenter` package
- Nubefact OSE credentials in `.env` (NUBEFACT_OSE_USER, NUBEFACT_OSE_PASS)
- Digital certificate at `public/certs/CT2507128340.pem`
- SUNAT API modes: `beta` (test) or `prod` (production) via `GREENTER_MODE`
- Company credentials (RUC, SOL user/pass, client_id/secret) in `.env`
- Database: SQLite by default (can be configured for MySQL/PostgreSQL)
- Queue connection: database (requires `php artisan queue:listen` for background jobs)

### SUNAT Integration Flow

1. **Invoices:** Create → Link despatches → Calculate totals/detractions → Send to Nubefact → Process CDR response
2. **Despatches:** Create/Import XML → Generate signed XML → Send to SUNAT API → Poll ticket for status → Process CDR

### Detraction System

Transport services (code 027) are subject to 4% SUNAT detraction. The system:
- Calculates detraction per item based on reference values
- Computes net payable amount (total - detraction)
- Handles installment payments based on net amount
- Includes detraction info in SUNAT XML

### Key Relationships

- Invoice ↔ Despatch (many-to-many via `invoice_despatch`)
- Invoice → InvoiceItems, InvoiceInstallments, AccountReceivables
- Invoice → Client (belongs to)
- Despatch → Driver, Vehicle, Client (destinatario), SenderClient (remitente), Company
- Despatch → SecondaryVehicles, SecondaryDrivers (many-to-many)
- Despatch → DespatchItems, RelatedDocuments, OperationalExpenses
- OperationalExpense → ExpenseType, Despatch

### Model Observers

- `InvoiceObserver` - Handles invoice lifecycle events
- `DespatchObserver` - Handles despatch lifecycle events

### Excel Exports

Located in `app/Exports/`:
- `OperationalExpensesExport` - Exports operational expense reports
- `ProductionExport` - Exports production/trip reports

Uses `maatwebsite/excel` package for generating Excel files.
