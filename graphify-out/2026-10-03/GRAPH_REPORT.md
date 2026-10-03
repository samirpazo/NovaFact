# Graph Report - sunfacturation-main  (2026-09-14)

## Corpus Check
- 200 files · ~51,128 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 23 file(s) not represented in the graph (top: (none) 17, .example 2, .podman 1)

## Summary
- 975 nodes · 1927 edges · 97 communities (47 shown, 12 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 41 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `67bb9046`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- PipelineDatabase.php
- Illuminate\Http\Request
- McrDocument
- Illuminate\Database\Schema\Blueprint
- WebhookTransport
- DocumentState
- DecimalAmount
- package.json
- DespatchPayloadNormalizer
- Fase 6.6A — Auditoría multisucursal / multiestablecimiento
- GreenterService
- Illuminate\Database\Eloquent\Model
- DespatchData
- Empresa
- RuntimeException
- Fase 2 — identidad, idempotencia y numeración
- Fase 5 — Resiliencia fiscal
- .handle
- AppServiceProvider.php
- Fase 6 — Transactional Outbox y Webhooks
- SoapStatusConsultant
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
- SunFacturation
- UserFactory.php
- require-dev
- EmpresaRepository
- GrePollResult
- config
- Fase 4 — Guía de Remisión Electrónica 09/31
- WebhookDestinationPolicy
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
- SunatGreTransport.php
- WebhookOutboxTest.php
- Illuminate\Support\Facades\Schema
- Illuminate\Database\Migrations\Migration
- Fase 6.6B — Multisucursal fiscal
- WebhookSignature
- 2026_09_13_000100_add_admission_identity.php

## God Nodes (most connected - your core abstractions)
1. `Empresa` - 84 edges
2. `McrDocument` - 64 edges
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
- `completedWebhookDocument()` --calls--> `DocumentState`  [EXTRACTED]
  tests/Feature/WebhookOutboxTest.php → app/Enums/DocumentState.php
- `secondEstablishment()` --calls--> `McrEstablishment`  [EXTRACTED]
  tests/Feature/MultibranchBillingTest.php → app/Models/McrEstablishment.php
- `pipelineContext()` --calls--> `AdmissionContext`  [EXTRACTED]
  tests/Support/PipelineDatabase.php → app/Services/Documents/AdmissionContext.php
- `completedWebhookDocument()` --calls--> `ProcessingResult`  [EXTRACTED]
  tests/Feature/WebhookOutboxTest.php → app/Services/Documents/ProcessingResult.php

## Import Cycles
- None detected.

## Communities (97 total, 12 thin omitted)

### Community 0 - "PipelineDatabase.php"
Cohesion: 0.20
Nodes (7): AdmissionResult, admittedAwaitingGre(), despatchPayload(), notePayload(), persistedOriginal(), pipelineContext(), pipelinePayload()

### Community 1 - "Illuminate\Http\Request"
Cohesion: 0.08
Nodes (16): DocumentIntegrationEvent, EstablishmentController, FacturacionController, WebhookSubscriptionController, Controller, McrWebhookSubscription, WebhookSubscriptionService, Illuminate\Foundation\Application (+8 more)

### Community 2 - "McrDocument"
Cohesion: 0.05
Nodes (40): DispatchWebhooks, ReconcileBilling, RedeliverWebhook, ReplayWebhookEvent, ResetBetaBilling, WebhookStatus, DeliverWebhookJob, PollSunatSubmissionJob (+32 more)

### Community 4 - "WebhookTransport"
Cohesion: 0.20
Nodes (4): WebhookBackoffPolicy, WebhookTransport, WebhookTransportResult, ConcurrentWebhookTransport

### Community 5 - "DocumentState"
Cohesion: 0.08
Nodes (19): self, DocumentState, self, FailureCategory, ProcessingCheckpoint, RecoveryAction, ClassifiedSubmissionException, self (+11 more)

### Community 6 - "DecimalAmount"
Cohesion: 0.07
Nodes (12): CreditNoteReason, DebitNoteReason, DocumentType, StoreFacturaRequest, NoteReferenceResolver, SalesPayloadNormalizer, DecimalAmount, Carbon\CarbonImmutable (+4 more)

### Community 7 - "package.json"
Cohesion: 0.08
Nodes (25): dependencies, alpinejs, @fontsource/outfit, devDependencies, axios, concurrently, laravel-vite-plugin, tailwindcss (+17 more)

### Community 8 - "DespatchPayloadNormalizer"
Cohesion: 0.11
Nodes (13): DespatchReason, DespatchTransportMode, DespatchPayloadNormalizer, BoletaSummaryService, VoidedDocumentService, DecimalMeasure, Company, Greenter\Model\Company\Address (+5 more)

### Community 9 - "Fase 6.6A — Auditoría multisucursal / multiestablecimiento"
Cohesion: 0.12
Nodes (16): Auditoría de Nova Restaurante, Auditoría de SunFacturation, Company actual, Conclusión de la auditoría, Dictamen ejecutivo, Documentos y snapshots, Fase 6.6A — Auditoría multisucursal / multiestablecimiento, FASE 6.6B — plan propuesto (no ejecutado) (+8 more)

### Community 10 - "GreenterService"
Cohesion: 0.07
Nodes (25): FacturaData, self, ArtifactRecoveryService, ElectronicDocumentProcessor, AbstractDespatchProcessor, CarrierDespatchProcessor, CreditNoteProcessor, DebitNoteProcessor (+17 more)

### Community 11 - "Illuminate\Database\Eloquent\Model"
Cohesion: 0.07
Nodes (14): McrApiClient, McrEstablishment, McrOutboxEvent, McrSunatSubmission, McrWebhookDelivery, Serie, TipoComprobante, SerieRepository (+6 more)

### Community 12 - "DespatchData"
Cohesion: 0.09
Nodes (13): DespatchData, self, Client, Despatch, Direction, Greenter\Model\Despatch\AdditionalDoc, Greenter\Model\Despatch\Despatch, Greenter\Model\Despatch\DespatchDetail (+5 more)

### Community 14 - "RuntimeException"
Cohesion: 0.29
Nodes (4): Client, SunatGreTransport, CpeApi, RuntimeException

### Community 15 - "Fase 2 — identidad, idempotencia y numeración"
Cohesion: 0.11
Nodes (17): Archivos, Contexto HTTP transitorio, Fase 2 — identidad, idempotencia y numeración, Garantía arquitectónica y límites, Hashes canónicos, Idempotency-Key y external_reference, Migración y datos históricos, Problema y decisión (+9 more)

### Community 16 - "Fase 5 — Resiliencia fiscal"
Cohesion: 0.12
Nodes (16): Alcance, Artefactos, Auditoría del comportamiento anterior, Checkpoints, Estados y evidencia, Fase 5 — Resiliencia fiscal, GRE 09/31, Limitaciones pendientes (+8 more)

### Community 17 - ".handle"
Cohesion: 0.26
Nodes (5): ValidateBillingToken, ValidateSessionToken, SessionTokenService, Closure, Symfony\Component\HttpFoundation\Response

### Community 18 - "AppServiceProvider.php"
Cohesion: 0.20
Nodes (4): AppServiceProvider, DnsResolver, NativeDnsResolver, Illuminate\Support\ServiceProvider

### Community 19 - "Fase 6 — Transactional Outbox y Webhooks"
Cohesion: 0.14
Nodes (13): Administración y secrets, Alcance y auditoría previa, Atomicidad, versión y deduplicación, Catálogo, Dispatcher y operación, Entidades, Envelope versión 1, Fase 6 — Transactional Outbox y Webhooks (+5 more)

### Community 20 - "SoapStatusConsultant"
Cohesion: 0.27
Nodes (6): SoapStatusConsultant, SunatSoapStatusConsultant, Greenter\Model\Response\StatusCdrResult, Greenter\Ws\Services\ConsultCdrService, Greenter\Ws\Services\SoapClient, Greenter\Ws\Services\SunatEndpoints

### Community 21 - "Auditoría previa y plan de evolución — 2026-09-13"
Cohesion: 0.15
Nodes (12): 10. Fases y criterios de salida, 1. Estado real, 2. Arquitectura encontrada, 3. Problemas técnicos prioritarios, 4. Reutilización, 5. Modelo objetivo, 6. Migraciones necesarias, 7. Clases nuevas/modificadas (+4 more)

### Community 22 - "User"
Cohesion: 0.29
Nodes (7): User, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Seeder, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable

### Community 23 - "InvoicePdfService.php"
Cohesion: 0.16
Nodes (10): Note, BaconQrCode\Renderer\Image\SvgImageBackEnd, BaconQrCode\Renderer\ImageRenderer, BaconQrCode\Renderer\RendererStyle\RendererStyle, BaconQrCode\Writer, Dompdf\Dompdf, Dompdf\Options, Greenter\Model\Sale\Invoice (+2 more)

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

### Community 31 - "SunFacturation"
Cohesion: 0.22
Nodes (8): Arquitectura operativa, Documentación adicional, Flujo de emisión, PostgreSQL, Pruebas, Servicios locales, SunFacturation, Verificación rápida

### Community 32 - "UserFactory.php"
Cohesion: 0.32
Nodes (4): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Support\Facades\Hash, static

### Community 33 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, mockery/mockery, nunomaduro/collision, pestphp/pest, pestphp/pest-plugin-laravel

### Community 35 - "GrePollResult"
Cohesion: 0.17
Nodes (3): GrePollResult, GreSendResult, RecoveryCountingTransport

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

### Community 76 - "SunatGreTransport.php"
Cohesion: 0.25
Nodes (7): Greenter\Sunat\GRE\Api\AuthApi, Greenter\Sunat\GRE\Api\CpeApi, Greenter\Sunat\GRE\Configuration, Greenter\Sunat\GRE\Model\CpeDocument, Greenter\Sunat\GRE\Model\CpeDocumentArchivo, GuzzleHttp\Client, Illuminate\Support\Facades\Cache

### Community 77 - "WebhookOutboxTest.php"
Cohesion: 0.33
Nodes (4): Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Http, completedWebhookDocument(), fanoutWebhookEvent()

### Community 80 - "Fase 6.6B — Multisucursal fiscal"
Cohesion: 0.33
Nodes (5): Documento y snapshot, Eventos, administración y compatibilidad, Fase 6.6B — Multisucursal fiscal, Modelo y propiedad, Series, numeración y admisión

## Knowledge Gaps
- **187 isolated node(s):** `$schema`, `name`, `type`, `description`, `keywords` (+182 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 427 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **12 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Empresa` connect `Empresa` to `Illuminate\Http\Request`, `McrDocument`, `EmpresaRepository`, `GrePollResult`, `DocumentState`, `DecimalAmount`, `DespatchPayloadNormalizer`, `GreenterService`, `Illuminate\Database\Eloquent\Model`, `DespatchData`, `SunatGreTransport.php`, `RuntimeException`, `SoapStatusConsultant`, `InvoicePdfService.php`, `.configure`?**
  _High betweenness centrality (0.153) - this node is a cross-community bridge._
- **Why does `McrDocument` connect `McrDocument` to `PipelineDatabase.php`, `DocumentState`, `DecimalAmount`, `DespatchPayloadNormalizer`, `GreenterService`, `Illuminate\Database\Eloquent\Model`, `WebhookOutboxTest.php`?**
  _High betweenness centrality (0.072) - this node is a cross-community bridge._
- **Why does `DocumentState` connect `DocumentState` to `McrDocument`, `GreenterService`, `WebhookOutboxTest.php`?**
  _High betweenness centrality (0.018) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _187 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Illuminate\Http\Request` be split into smaller, more focused modules?**
  _Cohesion score 0.0818452380952381 - nodes in this community are weakly interconnected._
- **Should `McrDocument` be split into smaller, more focused modules?**
  _Cohesion score 0.05123428039124359 - nodes in this community are weakly interconnected._
- **Should `DocumentState` be split into smaller, more focused modules?**
  _Cohesion score 0.07547169811320754 - nodes in this community are weakly interconnected._