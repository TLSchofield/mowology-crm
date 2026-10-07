<?php
declare(strict_types=1);

// Composer autoloader (PHPUnit + smalot/pdfparser)
require_once __DIR__ . '/../vendor/autoload.php';

// Load service classes under test
// No namespace — plain PHP classes, loaded directly

// Accounting
require_once __DIR__ . '/../app/Modules/Accounting/Services/LedgerService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/ReportingService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/OwnerFreedomService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/LedgerSyncService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/RulesEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/GstReportService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/TaxEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/AlertEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankImportService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/AccountingService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/InvoiceReconciliationService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/EtransferInboxService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/YardiEftInboxService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/ClientCreditService.php';
require_once __DIR__ . '/../app/Services/Messaging/EmailWrapper.php';

// Quotes
require_once __DIR__ . '/../app/Modules/Quotes/Services/QuoteService.php';
require_once __DIR__ . '/../app/Modules/Quotes/Services/QuoteRecipientService.php';

// Department heads — shared brain
require_once __DIR__ . '/../app/Services/HeadBrain.php';

// Sales — Sam, the sales head
require_once __DIR__ . '/../app/Modules/Sales/Services/SalesDeskService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamFollowupService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamQuestionService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamBadgeService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamBrainService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SalesInboxService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/TextBridgeService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/UnclaimedReplyService.php';

// Yui — comms / client relations head
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiRules.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiDraftService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiDeskService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiBadgeService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiBrainService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/ClueService.php';
// Consent ledger
require_once __DIR__ . '/../app/Modules/Consent/Services/ConsentLedgerService.php';

// Reviews
require_once __DIR__ . '/../app/Modules/Reviews/Services/ReviewRequestService.php';

// Mia — marketing & relationships head
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaWording.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaFinder.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaBadgeService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaQuestionService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaBrainService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaDeskService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaCampaignService.php';
// Mia — channels (GBP, social, website watch, listings) and the media library
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaSeasonThemes.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/ReviewReplyDrafter.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/WebsiteWatchRules.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/WebsiteWatchService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/ListingsService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MediaTagRules.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MediaTagService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MediaPickerService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaSocialService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaChannelsService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaCalendar.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/WaterRestrictionService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/SendTimeService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaSequenceService.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaEmailHubService.php';

// Otto — operations head
require_once __DIR__ . '/../app/Modules/Operations/Services/OttoRules.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/OpsDeskService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/OttoActionService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/OttoQuestionService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/OttoBadgeService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/OttoBrainService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/DispatchRules.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/MunicipalRuleService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/EquipmentService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/TrainingRules.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/TrainingService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/PropertyReadinessService.php';

// Sales — Sam the Closer (rate card + pricing)
require_once __DIR__ . '/../app/Modules/Sales/Services/CloserPricing.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SiteMinutesModel.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/DriveMinutesAdded.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/CloserRateCard.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/CloserService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/CloserSettingsService.php';

// Charlie — Chief of Staff
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieRankService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieVoice.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieBriefService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/PennyBriefAdapter.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/HouseBriefAdapter.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieBadgeService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieBriefEmail.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/DeadlineRules.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/DeadlineService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/AccountantPackService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/ConflictRules.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieInboxService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieUrgentService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieForemanService.php';

require_once __DIR__ . '/../app/Modules/Invoices/Services/StatementService.php';

// Products
require_once __DIR__ . '/../app/Modules/Products/Services/FieldRecommendationService.php';
require_once __DIR__ . '/../app/Modules/Products/Services/FieldAskService.php';

// Expenses
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptInboxService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptArchiveService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptIntakeService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptBookkeeperRules.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptBookkeeperService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/BookkeeperDeskService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptTrailService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennyQuestionService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennyBadgeService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennyBrainService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennySelfAuditService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankRuleLearning.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankDeskService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/LedgerAccountMap.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/LedgerRepostService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankReceiptSweep.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/StatementCloseService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/EtransferDeskService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankInvoiceMatchService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/RecurringBillService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankDuplicateCleanup.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/StripePayoutService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/DuplicateReceiptService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseApprovalService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseLookupService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseLineItemService.php';
// Line-item learning: parser quality gate, LLM tier validators, ReceiptLearning helpers
require_once __DIR__ . '/../app/Services/Receipts/ReceiptLineItemIntelligence.php';

// Contacts
require_once __DIR__ . '/../app/Modules/Contacts/Services/ContactService.php';
require_once __DIR__ . '/../app/Modules/Contacts/Services/OnsiteContactService.php';

// Clients (Client/Account model — Phase 0)
require_once __DIR__ . '/../app/Modules/Clients/Services/BillToResolver.php';

// Contracts
require_once __DIR__ . '/../app/Modules/Contracts/Services/ContractService.php';
require_once __DIR__ . '/../app/Modules/Contracts/Services/ContractTermsService.php';

// Invoices
require_once __DIR__ . '/../app/Modules/Invoices/Services/InvoiceFromVisitService.php';
require_once __DIR__ . '/../app/Modules/Invoices/Services/InvoiceLineItems.php';
require_once __DIR__ . '/../app/Modules/Invoices/Services/InvoiceRouting.php';

// CMS
require_once __DIR__ . '/../app/Modules/CMS/Services/ArticleService.php';

// Privacy
require_once __DIR__ . '/../app/Modules/Privacy/Services/PrivacyService.php';

// Quiz / Certification
require_once __DIR__ . '/../app/Modules/Quiz/Services/CertificationService.php';
require_once __DIR__ . '/../app/Modules/Quiz/Services/VariantQuestionService.php';

// Team
require_once __DIR__ . '/../app/Modules/Team/Services/GeofenceService.php';
require_once __DIR__ . '/../app/Modules/Team/Services/TimesheetService.php';
require_once __DIR__ . '/../app/Modules/Team/Services/ProximityAutoStartService.php';
require_once __DIR__ . '/../app/Modules/Team/Services/TrackingIngestService.php';
require_once __DIR__ . '/../app/Modules/Team/Services/TrackingConsentService.php';
require_once __DIR__ . '/../app/Modules/Team/Services/TrackingHealthService.php';
require_once __DIR__ . '/../app/Modules/Team/Services/TrackingSetupGate.php';
require_once __DIR__ . '/../app/Modules/Team/Services/DepartureAutoStopService.php';

// Driver (commercial vehicle trip inspections)
require_once __DIR__ . '/../app/Modules/Driver/Services/TripReportService.php';

// Tracking (Trackimo truck GPS)
require_once __DIR__ . '/../app/Modules/Tracking/Services/TrackimoService.php';
require_once __DIR__ . '/../app/Modules/Tracking/Services/TruckLocationService.php';

// Push notifications
require_once __DIR__ . '/../app/Services/Push/FcmService.php';

// Jobs (plan/visit engine — Phase 2 extraction; pure recurrence/profit math is unit-tested)
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitGenerationService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/PlanProfitabilityService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/PlanMaterialsService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/PlanHelpersService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitLifecycleService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitWorkService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitPhotoService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/FieldJobService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/ServiceHistoryService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/FieldSearchService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/ClientVisibilityService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/SeasonalOutlookService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/SeasonalOutlookRefreshService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/CrewAssignmentService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/StopRescheduleService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitEndorsementService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/WeatherGuardSummary.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/WeatherActionGuard.php';
require_once __DIR__ . '/../app/Modules/Portfolio/Services/BeforeAfterService.php';

// Social (Meta/GBP publishing — credential contract + connection health)
require_once __DIR__ . '/../app/Modules/Social/Services/SocialEncryption.php';
require_once __DIR__ . '/../app/Modules/Social/Services/MetaService.php';
require_once __DIR__ . '/../app/Modules/Social/Services/SocialAccountHealth.php';
require_once __DIR__ . '/../app/Modules/Social/Services/SocialConnectionState.php';
require_once __DIR__ . '/../app/Modules/Social/Services/GbpService.php';

// Integration test base class (needed when --testsuite Integration is run)
require_once __DIR__ . '/Integration/ApiTestCase.php';
