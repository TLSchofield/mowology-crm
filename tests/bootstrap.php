<?php
declare(strict_types=1);

// Composer autoloader (PHPUnit + smalot/pdfparser)
require_once __DIR__ . '/../vendor/autoload.php';

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

// Clients (Client/Account model — Phase 0)
require_once __DIR__ . '/../app/Modules/Clients/Services/BillToResolver.php';

// Contracts
require_once __DIR__ . '/../app/Modules/Contracts/Services/ContractService.php';

// Privacy
require_once __DIR__ . '/../app/Modules/Privacy/Services/PrivacyService.php';

// Quiz / Certification
require_once __DIR__ . '/../app/Modules/Quiz/Services/CertificationService.php';
require_once __DIR__ . '/../app/Modules/Quiz/Services/VariantQuestionService.php';

// Team
require_once __DIR__ . '/../app/Modules/Team/Services/GeofenceService.php';

// Integration test base class (needed when --testsuite Integration is run)
require_once __DIR__ . '/Integration/ApiTestCase.php';
