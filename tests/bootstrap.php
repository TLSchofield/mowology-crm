<?php
declare(strict_types=1);

// Composer autoloader (PHPUnit + smalot/pdfparser at root level)
require_once __DIR__ . '/../vendor/autoload.php';

// Production-only deps live under public/vendor (mpdf, stripe-php, etc.).
// Pull that autoload in too so service classes that typehint Stripe types can
// be loaded by tests. Loaded conditionally so a missing public/vendor doesn't
// hard-break the suite — tests that don't need Stripe still run.
if (file_exists(__DIR__ . '/../public/vendor/autoload.php')) {
    require_once __DIR__ . '/../public/vendor/autoload.php';
}

// Load service classes under test
// No namespace — plain PHP classes, loaded directly

// Accounting
require_once __DIR__ . '/../app/Modules/Accounting/Services/RulesEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/TaxEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/AlertEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankImportService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/AccountingService.php';

// Quotes
require_once __DIR__ . '/../app/Modules/Quotes/Services/QuoteService.php';

// Contacts
require_once __DIR__ . '/../app/Modules/Contacts/Services/ContactService.php';

// Contracts (file does not yet exist — placeholder require removed)
// require_once __DIR__ . '/../app/Modules/Contracts/Services/ContractService.php';

// Invoices
require_once __DIR__ . '/../app/Modules/Invoices/Services/InvoiceService.php';

// Privacy
require_once __DIR__ . '/../app/Modules/Privacy/Services/PrivacyService.php';

// Quiz / Certification
require_once __DIR__ . '/../app/Modules/Quiz/Services/CertificationService.php';
require_once __DIR__ . '/../app/Modules/Quiz/Services/VariantQuestionService.php';

// Integration test base class (needed when --testsuite Integration is run)
require_once __DIR__ . '/Integration/ApiTestCase.php';
