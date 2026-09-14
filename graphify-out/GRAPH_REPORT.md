# Graph Report - sunfacturation-main  (2026-09-13)

## Corpus Check
- 192 files · ~45,146 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 23 file(s) not represented in the graph (top: (none) 17, .example 2, .podman 1)

## Summary
- 925 nodes · 1829 edges · 76 communities (44 shown, 8 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 45 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `76662c2a`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- McrDocument
- Illuminate\Http\Request
- PollSunatSubmissionJob.php
- Illuminate\Database\Migrations\Migration
- AppServiceProvider.php
- DocumentState
- DecimalAmount
- package.json
- DespatchPayloadNormalizer
- GreenterService
- NoteService
- Illuminate\Database\Eloquent\Model
- DespatchData
- Empresa
- RuntimeException
- Fase 2 — identidad, idempotencia y numeración
- Fase 5 — Resiliencia fiscal
- FacturaData
- SubmissionCheckpoint
- Fase 6 — Transactional Outbox y Webhooks
- NoteService.php
- Auditoría previa y plan de evolución — 2026-09-13
- User
- InvoicePdfService.php
- Fase 3 — notas de crédito 07 y notas de débito 08
- .configure
- require
- Nova Facturación — Pase a producción
- composer.json
- scripts
- Fase 1 — pipeline de ventas existente
- README.md
- UserFactory.php
- require-dev
- EmpresaRepository
- GreSendResult
- config
- Fase 4 — Guía de Remisión Electrónica 09/31
- GrePollResult
- Ejemplo: Boleta de Venta Electrónica (03)
- Ejemplo: Factura Electrónica (01)
- Ejemplo: Guía de Remisión Remitente (09)
- Ejemplo: Guía de Remisión Transportista (31)
- psr-4
- logging.php
- Pruebas automatizadas de facturación
- console.php
- autoload-dev
- extra
- Despliegue VPS con Podman
- Illuminate\Foundation\Testing\TestCase
- docker-entrypoint-podman.sh

## God Nodes (most connected - your core abstractions)
1. `Empresa` - 79 edges
2. `McrDocument` - 61 edges
3. `DocumentState` - 33 edges
4. `ProcessingResult` - 33 edges
5. `ProcessingCheckpoint` - 25 edges
6. `FailureCategory` - 23 edges
7. `DocumentLifecycle` - 23 edges
8. `GreenterService` - 22 edges
9. `ReconcileSunatSubmissionJob` - 21 edges
10. `DecimalAmount` - 20 edges

## Surprising Connections (you probably didn't know these)
- `persistedOriginal()` --references--> `McrDocument`  [EXTRACTED]
  tests/Support/PipelineDatabase.php → app/Models/McrDocument.php
- `RecoveryCountingTransport` --implements--> `GreTransport`  [EXTRACTED]
  tests/Feature/AdmissionConcurrencyPostgresTest.php → app/Services/Sunat/GreTransport.php
- `completedWebhookDocument()` --calls--> `DocumentState`  [EXTRACTED]
  tests/Feature/WebhookOutboxTest.php → app/Enums/DocumentState.php
- `pipelineContext()` --calls--> `AdmissionContext`  [EXTRACTED]
  tests/Support/PipelineDatabase.php → app/Services/Documents/AdmissionContext.php
- `admittedAwaitingGre()` --references--> `AdmissionResult`  [EXTRACTED]
  tests/Feature/DespatchPipelineTest.php → app/Services/Documents/AdmissionResult.php

## Import Cycles
- None detected.

## Communities (76 total, 8 thin omitted)

### Community 0 - "McrDocument"
Cohesion: 0.06
Nodes (30): DocumentType, Throwable, ProcessElectronicDocumentJob, McrApiClient, McrDocument, McrSeries, AdmissionContext, AdmissionResult (+22 more)

### Community 1 - "Illuminate\Http\Request"
Cohesion: 0.07
Nodes (21): DocumentIntegrationEvent, self, FacturacionController, WebhookSubscriptionController, Controller, ValidateBillingToken, ValidateSessionToken, McrWebhookSubscription (+13 more)

### Community 2 - "PollSunatSubmissionJob.php"
Cohesion: 0.07
Nodes (21): DispatchWebhooks, ReconcileBilling, RedeliverWebhook, ReplayWebhookEvent, ResetBetaBilling, WebhookStatus, DeliverWebhookJob, PollSunatSubmissionJob (+13 more)

### Community 3 - "Illuminate\Database\Migrations\Migration"
Cohesion: 0.05
Nodes (4): down(), Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 4 - "AppServiceProvider.php"
Cohesion: 0.05
Nodes (18): AppServiceProvider, SoapStatusConsultant, SunatSoapStatusConsultant, DnsResolver, HttpWebhookTransport, NativeDnsResolver, WebhookBackoffPolicy, WebhookDestinationPolicy (+10 more)

### Community 5 - "DocumentState"
Cohesion: 0.10
Nodes (17): DocumentState, self, FailureCategory, ProcessingCheckpoint, RecoveryAction, ClassifiedSubmissionException, self, self (+9 more)

### Community 6 - "DecimalAmount"
Cohesion: 0.07
Nodes (11): CreditNoteReason, DebitNoteReason, StoreFacturaRequest, NoteReferenceResolver, SalesPayloadNormalizer, DecimalAmount, DecimalMeasure, Illuminate\Foundation\Http\FormRequest (+3 more)

### Community 7 - "package.json"
Cohesion: 0.08
Nodes (25): dependencies, alpinejs, @fontsource/outfit, devDependencies, axios, concurrently, laravel-vite-plugin, tailwindcss (+17 more)

### Community 8 - "DespatchPayloadNormalizer"
Cohesion: 0.12
Nodes (12): DespatchReason, DespatchTransportMode, DespatchPayloadNormalizer, BoletaSummaryService, Note, VoidedDocumentService, Company, Greenter\Model\Company\Address (+4 more)

### Community 9 - "GreenterService"
Cohesion: 0.19
Nodes (11): ArtifactRecoveryService, AbstractDespatchProcessor, DespatchPdfService, DespatchService, ManagedFileService, CertificateService, GreenterService, GreTransport (+3 more)

### Community 10 - "NoteService"
Cohesion: 0.11
Nodes (7): ElectronicDocumentProcessor, CarrierDespatchProcessor, CreditNoteProcessor, DebitNoteProcessor, ReceiptProcessor, SenderDespatchProcessor, NoteService

### Community 11 - "Illuminate\Database\Eloquent\Model"
Cohesion: 0.14
Nodes (8): McrOutboxEvent, McrSunatSubmission, McrWebhookDelivery, Serie, TipoComprobante, SerieRepository, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 12 - "DespatchData"
Cohesion: 0.12
Nodes (6): DespatchData, self, Client, Despatch, Direction, Greenter\Model\Despatch\Direction

### Community 14 - "RuntimeException"
Cohesion: 0.16
Nodes (11): Client, SunatGreTransport, CpeApi, Greenter\Sunat\GRE\Api\AuthApi, Greenter\Sunat\GRE\Api\CpeApi, Greenter\Sunat\GRE\Configuration, Greenter\Sunat\GRE\Model\CpeDocument, Greenter\Sunat\GRE\Model\CpeDocumentArchivo (+3 more)

### Community 15 - "Fase 2 — identidad, idempotencia y numeración"
Cohesion: 0.11
Nodes (16): Archivos, Contexto HTTP transitorio, Fase 2 — identidad, idempotencia y numeración, Garantía arquitectónica y límites, Hashes canónicos, Idempotency-Key y external_reference, Migración y datos históricos, Problema y decisión (+8 more)

### Community 16 - "Fase 5 — Resiliencia fiscal"
Cohesion: 0.12
Nodes (16): Alcance, Artefactos, Auditoría del comportamiento anterior, Checkpoints, Estados y evidencia, Fase 5 — Resiliencia fiscal, GRE 09/31, Limitaciones pendientes (+8 more)

### Community 17 - "FacturaData"
Cohesion: 0.21
Nodes (6): FacturaData, self, InvoiceProcessor, FacturaService, Invoice, Greenter\Xml\Builder\InvoiceBuilder

### Community 18 - "SubmissionCheckpoint"
Cohesion: 0.20
Nodes (4): SubmissionCheckpoint, InvoicePdfService, Greenter\Model\Sale\Invoice, Greenter\Model\Sale\Note

### Community 19 - "Fase 6 — Transactional Outbox y Webhooks"
Cohesion: 0.14
Nodes (13): Administración y secrets, Alcance y auditoría previa, Atomicidad, versión y deduplicación, Catálogo, Dispatcher y operación, Entidades, Envelope versión 1, Fase 6 — Transactional Outbox y Webhooks (+5 more)

### Community 20 - "NoteService.php"
Cohesion: 0.21
Nodes (10): Greenter\Model\Client\Client, Greenter\Model\Company\Company, Greenter\Model\Despatch\AdditionalDoc, Greenter\Model\Despatch\DespatchDetail, Greenter\Model\Despatch\Driver, Greenter\Model\Despatch\Shipment, Greenter\Model\Despatch\Transportist, Greenter\Model\Despatch\Vehicle (+2 more)

### Community 21 - "Auditoría previa y plan de evolución — 2026-09-13"
Cohesion: 0.15
Nodes (12): 10. Fases y criterios de salida, 1. Estado real, 2. Arquitectura encontrada, 3. Problemas técnicos prioritarios, 4. Reutilización, 5. Modelo objetivo, 6. Migraciones necesarias, 7. Clases nuevas/modificadas (+4 more)

### Community 22 - "User"
Cohesion: 0.29
Nodes (7): User, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Seeder, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable

### Community 23 - "InvoicePdfService.php"
Cohesion: 0.24
Nodes (8): BaconQrCode\Renderer\Image\SvgImageBackEnd, BaconQrCode\Renderer\ImageRenderer, BaconQrCode\Renderer\RendererStyle\RendererStyle, BaconQrCode\Writer, Dompdf\Dompdf, Dompdf\Options, Greenter\Model\Despatch\Despatch, Greenter\Report\HtmlReport

### Community 24 - "Fase 3 — notas de crédito 07 y notas de débito 08"
Cohesion: 0.18
Nodes (10): API y errores, Arquitectura, Auditoría previa, Catálogos y reglas, Contrato, Evidencia y límites, Exactitud monetaria, Fase 3 — notas de crédito 07 y notas de débito 08 (+2 more)

### Community 26 - "require"
Cohesion: 0.20
Nodes (10): require, dompdf/dompdf, greenter/gre-api, greenter/greenter, greenter/report, greenter/ws, greenter/xml, laravel/framework (+2 more)

### Community 27 - "Nova Facturación — Pase a producción"
Cohesion: 0.20
Nodes (9): 1. Preparar el entorno, 2. Variables obligatorias del microservicio, 3. Certificado digital, 4. Base de datos y numeración, 5. Despliegue con Podman, 6. Configurar Nova, 7. Verificación posterior, 8. Orden recomendado de cambio (+1 more)

### Community 28 - "composer.json"
Cohesion: 0.22
Nodes (8): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type

### Community 29 - "scripts"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

### Community 30 - "Fase 1 — pipeline de ventas existente"
Cohesion: 0.22
Nodes (8): Alcance implementado, Archivos, Cierre del flujo legacy de factura/boleta, Decisiones, Fase 1 — pipeline de ventas existente, Migración, Operación y deuda pendiente, Pruebas y resultados

### Community 31 - "README.md"
Cohesion: 0.22
Nodes (8): About Laravel, Code of Conduct, Contributing, Laravel Sponsors, Learning Laravel, License, Premium Partners, Security Vulnerabilities

### Community 32 - "UserFactory.php"
Cohesion: 0.32
Nodes (4): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Support\Facades\Hash, static

### Community 33 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, mockery/mockery, nunomaduro/collision, pestphp/pest, pestphp/pest-plugin-laravel

### Community 36 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 37 - "Fase 4 — Guía de Remisión Electrónica 09/31"
Cohesion: 0.29
Nodes (6): Auditoría y arquitectura, Contrato, catálogos y reglas, Evidencia y límites, Fase 4 — Guía de Remisión Electrónica 09/31, Greenter y SUNAT, Persistencia, polling y seguridad

### Community 39 - "Ejemplo: Boleta de Venta Electrónica (03)"
Cohesion: 0.33
Nodes (5): Descripción de campos clave, Ejemplo: Boleta de Venta Electrónica (03), Endpoint, JSON Request Body, Respuesta Exitosa

### Community 40 - "Ejemplo: Factura Electrónica (01)"
Cohesion: 0.33
Nodes (5): Descripción de campos clave, Ejemplo: Factura Electrónica (01), Endpoint, JSON Request Body, Respuesta Exitosa

### Community 41 - "Ejemplo: Guía de Remisión Remitente (09)"
Cohesion: 0.33
Nodes (5): Descripción de campos clave, Ejemplo: Guía de Remisión Remitente (09), Endpoint, JSON Request Body, Respuesta Exitosa

### Community 42 - "Ejemplo: Guía de Remisión Transportista (31)"
Cohesion: 0.33
Nodes (5): Descripción de campos clave, Ejemplo: Guía de Remisión Transportista (31), Endpoint, JSON Request Body, Respuesta Exitosa

### Community 43 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 44 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 45 - "Pruebas automatizadas de facturación"
Cohesion: 0.40
Nodes (4): Escenarios, Flujo recomendado, Pruebas automatizadas de facturación, Variables de entorno

### Community 46 - "console.php"
Cohesion: 0.50
Nodes (3): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Schedule

### Community 47 - "autoload-dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 48 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **168 isolated node(s):** `$schema`, `name`, `type`, `description`, `keywords` (+163 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 401 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **8 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Empresa` connect `Empresa` to `McrDocument`, `Illuminate\Http\Request`, `PollSunatSubmissionJob.php`, `AppServiceProvider.php`, `DocumentState`, `DecimalAmount`, `DespatchPayloadNormalizer`, `GreenterService`, `NoteService`, `Illuminate\Database\Eloquent\Model`, `DespatchData`, `RuntimeException`, `FacturaData`, `SubmissionCheckpoint`, `NoteService.php`, `InvoicePdfService.php`, `.configure`, `EmpresaRepository`, `GreSendResult`, `GrePollResult`?**
  _High betweenness centrality (0.153) - this node is a cross-community bridge._
- **Why does `McrDocument` connect `McrDocument` to `PollSunatSubmissionJob.php`, `DocumentState`, `DecimalAmount`, `DespatchPayloadNormalizer`, `GreenterService`, `NoteService`, `Illuminate\Database\Eloquent\Model`, `FacturaData`, `NoteService.php`?**
  _High betweenness centrality (0.073) - this node is a cross-community bridge._
- **Why does `DocumentState` connect `DocumentState` to `McrDocument`, `Illuminate\Http\Request`, `PollSunatSubmissionJob.php`, `GreenterService`?**
  _High betweenness centrality (0.019) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _168 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `McrDocument` be split into smaller, more focused modules?**
  _Cohesion score 0.056150600454397924 - nodes in this community are weakly interconnected._
- **Should `Illuminate\Http\Request` be split into smaller, more focused modules?**
  _Cohesion score 0.06559356136820925 - nodes in this community are weakly interconnected._
- **Should `PollSunatSubmissionJob.php` be split into smaller, more focused modules?**
  _Cohesion score 0.0742447516641065 - nodes in this community are weakly interconnected._