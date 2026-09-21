-- =============================================================================
-- NovaFact Bootstrap Minimal Data Seed Script
-- Target Database: nova_structural_test (or nova_core production DB)
-- Environment: Local / Demo / Staging
-- Secrets Policy: NO real secrets, passwords, tokens, or private keys.
-- All credentials (McrSolUser, McrSolPassword, McrCertificatePassword,
-- McrClientId, McrClientSecret, McrEncryptedSecret) MUST remain NULL.
-- =============================================================================

\set ON_ERROR_STOP on

BEGIN;

-- 1. McrCompanyConfig (1 record - Audited Non-Sensitive Source Data)
INSERT INTO "McrCompanyConfig" (
    "McrCompanyConfigID",
    "McrRuc",
    "McrBusinessName",
    "McrTradeName",
    "McrUbigeo",
    "McrDepartment",
    "McrProvince",
    "McrDistrict",
    "McrUrbanization",
    "McrAddress",
    "McrSolUser",
    "McrSolPassword",
    "McrCertificateName",
    "McrCertificatePassword",
    "McrClientId",
    "McrClientSecret",
    "McrEnvironment",
    "McrLogoPath",
    "McrCurrencyCode",
    "McrIgvRate",
    "McrIpmRate",
    "McrTotalTaxRate",
    "McrSpecialTaxRegime",
    "McrPhone",
    "McrEmail",
    "McrIsActive",
    "SecStatus",
    "CreateUserId",
    "CreateDate"
) VALUES (
    1,
    '20123456789',
    'Munay S.A.C.',
    'Munay',
    '150122',
    'Lima',
    'Lima',
    'Miraflores',
    'S/N',
    'Costa Verde',
    NULL,
    NULL,
    'llama-demo-10711109728.pfx',
    NULL,
    NULL,
    NULL,
    'beta',
    'facturacion/logo/company-logo.jpg',
    'PEN',
    16.0,
    2.0,
    18.0,
    false,
    '999 888 777',
    'nova@restaurant.com',
    true,
    true,
    1,
    CURRENT_TIMESTAMP
);

-- 2. McrEstablishment (1 record - DEFAULT Establishment)
INSERT INTO "McrEstablishment" (
    "McrEstablishmentID",
    "McrCompanyConfigID",
    "McrExternalCode",
    "McrSunatCode",
    "McrName",
    "McrTradeName",
    "McrAddress",
    "McrAddressReference",
    "McrUbigeo",
    "McrDepartment",
    "McrProvince",
    "McrDistrict",
    "McrCountryCode",
    "McrPhone",
    "McrEmail",
    "McrIsDefault",
    "McrIsActive",
    "SecStatus",
    "CreateUserId",
    "CreateDate"
) VALUES (
    1,
    1,
    'DEFAULT',
    '0000',
    'Sede Principal',
    'Munay',
    'Av. Principal 123',
    NULL,
    '200110',
    'Piura',
    'Piura',
    'La Unión',
    'PE',
    '987 654 321',
    'sede@restaurant.com',
    true,
    true,
    true,
    1,
    CURRENT_TIMESTAMP
);

-- 3. McrSeries (2 records - B001 and F001)
INSERT INTO "McrSeries" (
    "McrSeriesID",
    "McrCompanyConfigID",
    "McrEstablishmentID",
    "McrDocumentType",
    "McrSeriesCode",
    "McrNextCorrelative",
    "McrIsActive",
    "SecStatus",
    "CreateUserId",
    "CreateDate"
) VALUES 
(
    1,
    1,
    1,
    '03',
    'B001',
    1,
    true,
    true,
    1,
    CURRENT_TIMESTAMP
),
(
    2,
    1,
    1,
    '01',
    'F001',
    1,
    true,
    true,
    1,
    CURRENT_TIMESTAMP
);

-- 4. McrApiClient (1 record - nova-restaurant)
INSERT INTO "McrApiClient" (
    "McrApiClientID",
    "McrCode",
    "McrName",
    "McrIsActive",
    "SecStatus",
    "CreateUserId",
    "CreateDate"
) VALUES (
    1,
    'nova-restaurant',
    'Nova Restaurante demo',
    true,
    true,
    1,
    CURRENT_TIMESTAMP
);

-- 5. McrWebhookSubscription (1 record - exact 7 integration events, disabled until HMAC configured)
INSERT INTO "McrWebhookSubscription" (
    "McrWebhookSubscriptionID",
    "McrApiClientID",
    "McrCompanyConfigID",
    "McrUrl",
    "McrIsEnabled",
    "McrEncryptedSecret",
    "McrEventTypes",
    "McrCreatedAt",
    "McrUpdatedAt"
) VALUES (
    1,
    1,
    1,
    'http://127.0.0.1:8080/api/integrations/sunfacturation/webhook',
    false,
    NULL,
    '["document.awaiting_sunat", "document.accepted", "document.accepted_with_observations", "document.rejected", "document.reconciliation_pending", "document.manual_review", "document.failed"]'::jsonb,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
);

-- 6. Sequence Adjustments (Synchronize sequences after explicit ID inserts)
SELECT setval(pg_get_serial_sequence('"McrCompanyConfig"', 'McrCompanyConfigID'), (SELECT MAX("McrCompanyConfigID") FROM "McrCompanyConfig"));
SELECT setval(pg_get_serial_sequence('"McrEstablishment"', 'McrEstablishmentID'), (SELECT MAX("McrEstablishmentID") FROM "McrEstablishment"));
SELECT setval(pg_get_serial_sequence('"McrSeries"', 'McrSeriesID'), (SELECT MAX("McrSeriesID") FROM "McrSeries"));
SELECT setval(pg_get_serial_sequence('"McrApiClient"', 'McrApiClientID'), (SELECT MAX("McrApiClientID") FROM "McrApiClient"));
SELECT setval(pg_get_serial_sequence('"McrWebhookSubscription"', 'McrWebhookSubscriptionID'), (SELECT MAX("McrWebhookSubscriptionID") FROM "McrWebhookSubscription"));

COMMIT;
