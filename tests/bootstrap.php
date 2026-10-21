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
require_once __DIR__ . '/../app/Modules/Accounting/Services/CapitalItemRule.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/RulesEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/GstReportService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/TaxEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/AlertEngine.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankImportService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/AccountingService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/InvoiceReconciliationService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/EtransferInboxService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/EtransferClaimAlertService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/YardiEftInboxService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/ClientCreditService.php';
require_once __DIR__ . '/../app/Services/Messaging/EmailWrapper.php';

// Quotes
require_once __DIR__ . '/../app/Modules/Quotes/Services/QuoteService.php';
require_once __DIR__ . '/../app/Modules/Quotes/Services/QuoteLineChoiceService.php';
require_once __DIR__ . '/../app/Modules/Quotes/Services/QuoteRecipientService.php';

// Department heads — shared brain
require_once __DIR__ . '/../app/Services/HeadBrain.php';
require_once __DIR__ . '/../app/Services/BrainPageService.php';
// head_lessons (migration 1348): one learning record for every head; Penny's adapter
require_once __DIR__ . '/../app/Services/HeadLessons.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/PennyLessons.php';

// Sales — Sam, the sales head
require_once __DIR__ . '/../app/Modules/Sales/Services/SalesDeskService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamFollowupService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamQuestionService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamBadgeService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamBrainService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SalesInboxService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/QuoteApprovalService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/MaterialDeliveryService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/CashFlowForecastService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/BreakService.php';
// Otto's end-of-day missed-visit review (migration 1349)
require_once __DIR__ . '/../app/Modules/Operations/Services/MissedVisitRules.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/MissedVisitReviewService.php';
require_once __DIR__ . '/../app/Core/OnboardingQuestions.php';
require_once __DIR__ . '/../app/Core/ApiResponse.php';
require_once __DIR__ . '/../app/Core/Auth/AuthFreshness.php';
require_once __DIR__ . '/../app/Modules/Team/Services/PasswordChangeRules.php';
require_once __DIR__ . '/../app/Modules/Contacts/Services/PortalProfileAudit.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/TextBridgeService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/UnclaimedReplyService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/QuoteViewNotifier.php';

// Offline times from phones (clock in/out)
require_once __DIR__ . '/../app/Modules/Team/Services/OfflineTime.php';
require_once __DIR__ . '/../app/Modules/Team/Services/VisitTimerStopPolicy.php';

// Ada — head of Technology
require_once __DIR__ . '/../app/Modules/Technology/Services/AdaRules.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/AdaDeskService.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/AuditDiff.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/AuditTrail.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/AdaSecurityRules.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/AdaSecurityWatch.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/AuditExportService.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/SecurityPostureRules.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/SecurityPostureService.php';

// Yui — comms / client relations head
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiRules.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiDraftService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiDeskService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiBadgeService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/YuiBrainService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/ClueService.php';
// Tim's iCloud as a read-only source (mailbox config, read-only IMAP helpers, router, leads, vendor mail)
require_once __DIR__ . '/../app/Services/Mail/MailboxConfig.php';
require_once __DIR__ . '/../app/Services/Mail/ImapReader.php';
require_once __DIR__ . '/../app/Services/Mail/ImapClient.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/MailboxPollLock.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/EmailLeadService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/VendorMessageService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptInboxService.php';
require_once __DIR__ . '/../app/Modules/Comms/Services/IcloudInboxRouter.php';
// Consent ledger
require_once __DIR__ . '/../app/Modules/Consent/Services/ConsentLedgerService.php';
require_once __DIR__ . '/../app/Modules/Consent/Services/CaslRules.php';
require_once __DIR__ . '/../app/Modules/Referrals/Services/ReferralRules.php';

// Reviews
require_once __DIR__ . '/../app/Modules/Reviews/Services/ReviewRequestService.php';

// Mia — marketing & relationships head
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaWording.php';
require_once __DIR__ . '/../app/Modules/Marketing/Services/TrackingLinks.php';
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
require_once __DIR__ . '/../app/Modules/Marketing/Services/MiaThemeOutcomes.php';
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
require_once __DIR__ . '/../app/Modules/Operations/Services/EquipmentReceiptIntakeService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/TrainingRules.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/TrainingService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/PropertyReadinessService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/ParcelLotService.php';      // lot borders from ParcelMap BC (2026-10-18)
require_once __DIR__ . '/../app/Modules/Operations/Services/CityParcelService.php';     // a city's own lot map, by address (2026-10-19)
require_once __DIR__ . '/../app/Modules/Operations/Services/OttoAutoPlaceService.php';  // Otto's own pins + lot borders
require_once __DIR__ . '/../app/Modules/Comms/Services/BuildingManagerService.php';    // Yui: who manages a building
require_once __DIR__ . '/../app/Modules/Operations/Services/TripSegmentService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptFactsService.php';   // printed receipt facts (1227); StopEvidence uses its parser
require_once __DIR__ . '/../app/Modules/Operations/Services/StopEvidenceService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/TripCostService.php';
// Shared cost facts (migration 1218): Otto writes, Sam / Penny / Charlie read
require_once __DIR__ . '/../app/Modules/Operations/Services/CostFactsService.php';
// Special requests on arrival (migrations 1295–1299): matcher, service, crew gate
require_once __DIR__ . '/../app/Modules/Operations/Services/SpecialRequestMatcher.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/SpecialRequestService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/SpecialRequestGate.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/TripAttributionService.php';
// Supplier stops + Otto's job pick for material receipts (migration 1359)
require_once __DIR__ . '/../app/Modules/Operations/Services/SupplierStopService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/SupplierJobSuggestService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/TripLineSuggester.php';

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
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/HeadStateService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/PennyBriefAdapter.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/HouseBriefAdapter.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/HeadBriefReadyService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/DailyQuote.php';
require_once __DIR__ . '/../app/Services/Pdf/PdfFormFiller.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/DepositBackfillService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/CompanyProfileService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/CompanyDocumentService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/SupplierDirectoryService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/SupplierFormService.php';
// Supplier price lists + shared catalogue (migration 1331)
require_once __DIR__ . '/../app/Services/Spreadsheet/XlsxReader.php';
require_once __DIR__ . '/../app/Services/Spreadsheet/SheetReader.php';   // + XlsReader (old .xls)
require_once __DIR__ . '/../app/Services/Spreadsheet/PdfTableReader.php';   // supplier PDF price tables (2026-10-19)
require_once __DIR__ . '/../app/Modules/Operations/Services/PlantNameNormalizer.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/CatalogMatchers.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/SupplierListParser.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/CatalogService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/SupplierPriceListService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/SupplierSearchService.php';   // best price across supplier lists (2026-10-19)
require_once __DIR__ . '/../app/Modules/Operations/Services/MarketPriceService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/MulchPricingService.php';     // local market prices, shared regional layer (2026-10-19)
require_once __DIR__ . '/../app/Modules/Operations/Services/EquipmentPlateParser.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/EquipmentCatalogService.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/EquipmentImportService.php';
require_once __DIR__ . '/Unit/Operations/PriceListFixtures.php';
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
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/TeamCardService.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieFactAnswerer.php';
require_once __DIR__ . '/../app/Modules/ChiefOfStaff/Services/CharlieAskService.php';

require_once __DIR__ . '/../app/Modules/Invoices/Services/StatementService.php';

// Products
require_once __DIR__ . '/../app/Modules/Products/Services/FieldRecommendationService.php';
require_once __DIR__ . '/../app/Modules/Products/Services/FieldAskService.php';
// Label photo → product / machine; receipts → products; Otto's care view and manual reading (migration 1225)
require_once __DIR__ . '/../app/Modules/Products/Services/LabelReaderService.php';
require_once __DIR__ . '/../app/Modules/Products/Services/ProductProposalService.php';
require_once __DIR__ . '/../app/Modules/Products/Services/LabelCaptureService.php';
require_once __DIR__ . '/../app/Modules/Products/Services/ProductCareService.php';
require_once __DIR__ . '/../app/Modules/Products/Services/ProductFactAnswerer.php';
require_once __DIR__ . '/../app/Modules/Operations/Services/OttoManualService.php';

// Expenses
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptInboxService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptArchiveService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptIntakeService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptBookkeeperRules.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptBookkeeperService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/BookkeeperDeskService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennyReadService.php';   // Penny's read on the receipt screens (2026-10-13)
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptTrailService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennyQuestionService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennyBadgeService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennyBrainService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennySelfAuditService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankRuleLearning.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankDeskService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankLineReceipts.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankGuidanceService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/LedgerAccountMap.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/LedgerRepostService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankReceiptSweep.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/StatementCloseService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/StatementCoverageService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/EtransferDeskService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/PaymentClueService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankInvoiceMatchService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/RecurringBillService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankDuplicateCleanup.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/StripePayoutService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankLineMoveService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/IncomeCleanupService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/CreditCardPayableAuditService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankTransferDirectionRepair.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankAccountSplitService.php';
// Bank balance check + each invoice payment its own journal entry (migration 1236)
require_once __DIR__ . '/../app/Modules/Accounting/Services/InvoicePaymentPlanner.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BankBalanceCheckService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/MonthCloseService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/BooksWatchService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/PayerIdentityService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/MonthCloseListService.php';
// Wave payroll import + shareholder account (migration 1255)
require_once __DIR__ . '/../app/Modules/Accounting/Services/WavePayrollImportService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/ShareholderAccountService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/Fy2026OpeningService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/JobberCsv.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/JobberImportService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/JobberLedgerService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/DuplicateReceiptService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseApprovalService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseLookupService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseCreateGuard.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseLineItemService.php';
// One gate for every expense change + split a receipt by line (migration 1233)
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseSplitService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseGateHooks.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpenseGate.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ExpensePersonalService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptCatalogService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptReviewService.php';
require_once __DIR__ . '/Unit/Expenses/ExpenseGateTestKit.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptCaptureService.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/ReceiptImageLinks.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/RiskExplainer.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/PennyKnowledgeService.php';

// Sales (Sam) — the reply card the iOS app's sales-head-mobile.php builds
require_once __DIR__ . '/../app/Modules/Sales/Services/SalesDeskService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamFollowupService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamReplyCard.php';
// Line-item learning: parser quality gate, LLM tier validators, ReceiptLearning helpers
require_once __DIR__ . '/../app/Services/Receipts/ReceiptLineItemIntelligence.php';

// Contacts
require_once __DIR__ . '/../app/Modules/Contacts/Services/ContactService.php';
require_once __DIR__ . '/../app/Modules/Contacts/Services/OnsiteContactService.php';
require_once __DIR__ . '/../app/Modules/Contacts/Services/ContactTeamService.php';
require_once __DIR__ . '/../app/Modules/Contacts/Services/ContactTimelineService.php';
require_once __DIR__ . '/../app/Modules/Contacts/Services/CompanyDeleteGuard.php';

// Clients (Client/Account model — Phase 0)
require_once __DIR__ . '/../app/Modules/Clients/Services/BillToResolver.php';

// Contracts
require_once __DIR__ . '/../app/Modules/Contracts/Services/ContractService.php';
require_once __DIR__ . '/../app/Modules/Contracts/Services/ContractTermsService.php';
require_once __DIR__ . '/../app/Modules/Contracts/Services/SnowContractService.php';
require_once __DIR__ . '/../app/Modules/Contracts/Services/SnowWeeklyStatementService.php';

// Invoices
require_once __DIR__ . '/../app/Modules/Invoices/Services/InvoiceFromVisitService.php';
require_once __DIR__ . '/../app/Modules/Invoices/Services/InvoiceLineItems.php';
require_once __DIR__ . '/../app/Modules/Invoices/Services/InvoiceRouting.php';
require_once __DIR__ . '/../app/Modules/Invoices/Services/UnbilledWorkFinder.php';

// CMS
require_once __DIR__ . '/../app/Modules/CMS/Services/ArticleService.php';
require_once __DIR__ . '/../app/Modules/CMS/Services/CmsHtmlSanitizer.php';
require_once __DIR__ . '/../app/Modules/CMS/Services/CmsThemeFunctions.php';
require_once __DIR__ . '/../app/Modules/CMS/Services/CmsSiteFunctions.php';

// Privacy
require_once __DIR__ . '/../app/Modules/Privacy/Services/PrivacyService.php';

// Quiz / Certification
require_once __DIR__ . '/../app/Modules/Quiz/Services/CertificationService.php';
require_once __DIR__ . '/../app/Modules/Quiz/Services/VariantQuestionService.php';

// Team
require_once __DIR__ . '/../app/Modules/Team/Services/GeofenceService.php';
require_once __DIR__ . '/../app/Modules/Team/Services/TimesheetService.php';
require_once __DIR__ . '/../app/Modules/Team/Services/TimesheetApprovalService.php';
require_once __DIR__ . '/../app/Modules/Schedule/Services/CrewViewScope.php';
require_once __DIR__ . '/../app/Modules/Schedule/Services/AppointmentService.php';
require_once __DIR__ . '/../app/Modules/Schedule/Services/VisitNeedsService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/StatementBooksCheckService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/ReceiptBankCheck.php';
require_once __DIR__ . '/../app/Modules/Expenses/Services/VendorDetailsService.php';
require_once __DIR__ . '/../app/Modules/Quotes/Services/QuoteSendService.php';
require_once __DIR__ . '/../app/Modules/Sales/Services/SamAcceptanceService.php';
require_once __DIR__ . '/../app/Modules/Quotes/Services/QuoteAcceptService.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/HoldingRepostService.php';
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
require_once __DIR__ . '/../app/Services/Push/DeviceTokenService.php';

// Jobs (plan/visit engine — Phase 2 extraction; pure recurrence/profit math is unit-tested)
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitGenerationService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/PlanProfitabilityService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/PlanMaterialsService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/PlanHelpersService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitLifecycleService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/ClusterService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitWorkService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitPhotoService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/FieldJobService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/CalendarStopTidyService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/VisitPullForwardService.php';
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
require_once __DIR__ . '/../app/Modules/Social/Services/SocialAccountConnectService.php';

// Payments (autopay eligibility — pure rule, re-checked under the invoice lock)
require_once __DIR__ . '/../app/Services/Payments/AutopayEligibility.php';
require_once __DIR__ . '/../app/Services/Payments/StripeErrorRules.php';

// Integration test base class (needed when --testsuite Integration is run)
require_once __DIR__ . '/Integration/ApiTestCase.php';

// The Accountant's Ledger (2026-10-13): fence, GIFI, journal browser, lock exceptions, invite
require_once __DIR__ . '/../app/Core/Auth/RoleRules.php';
require_once __DIR__ . '/../app/Core/Auth/AccountantFence.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/GifiRules.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/GifiService.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/JournalBrowserService.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/LockExceptionService.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/AccountantInviteService.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/LedgerExport.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/FiscalYear.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/LedgerSite.php';
// Ledger step 2: comparative TB, GL, QBO journal, XLSX writer
require_once __DIR__ . '/../app/Services/Spreadsheet/XlsxWriter.php';
require_once __DIR__ . '/../app/Services/Spreadsheet/XlsxReader.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/Fy2026OpeningService.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/ComparativeTrialBalanceService.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/GeneralLedgerService.php';
require_once __DIR__ . '/../app/Modules/Ledger/Services/QboJournalExport.php';
// Pulled from production 2026-10-13 (vendor knowledge commons + SMS templates were live-only)
require_once __DIR__ . '/../app/Modules/Knowledge/Services/VendorIdentity.php';
require_once __DIR__ . '/../app/Modules/Knowledge/Services/CategoryTaxonomy.php';
require_once __DIR__ . '/../app/Modules/Knowledge/Services/LocalVendorKnowledgeStore.php';
require_once __DIR__ . '/../app/Modules/Knowledge/Services/CodeSuggestionGate.php';
require_once __DIR__ . '/../app/Services/Messaging/SmsTemplates.php';
require_once __DIR__ . '/../app/Services/Receipts/ReceiptThumb.php';
require_once __DIR__ . '/../app/Modules/Accounting/Services/PaymentOnTheWay.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/WeekStripService.php';
require_once __DIR__ . '/../app/Modules/Jobs/Services/StopCardText.php';
require_once __DIR__ . '/../app/Modules/Plants/Services/BedLocator.php';
require_once __DIR__ . '/../app/Modules/Plants/Services/PlantIdService.php';
require_once __DIR__ . '/../app/Modules/Plants/Services/PlantCareService.php';
require_once __DIR__ . '/../app/Modules/Quiz/Services/SitePlantQuizService.php';
require_once __DIR__ . '/../app/Modules/Technology/Services/AdaServerErrors.php';
// Penny onboarding step 1 (global namespace; production has no autoloader)
require_once __DIR__ . '/../app/Modules/Settings/Services/Registry/BusinessRegistryProvider.php';
require_once __DIR__ . '/../app/Modules/Settings/Services/Registry/OrgBookBcProvider.php';
require_once __DIR__ . '/../app/Modules/Settings/Services/Registry/FakeRegistryProvider.php';
require_once __DIR__ . '/../app/Modules/Settings/Api/BusinessIdentityRequest.php';
require_once __DIR__ . '/../app/Modules/Settings/Services/BusinessIdentityService.php';
require_once __DIR__ . '/../app/Modules/QuickBooks/Services/QboConnectionService.php';
require_once __DIR__ . '/../app/Modules/QuickBooks/Services/QboSyncService.php';
require_once __DIR__ . '/../app/Modules/QuickBooks/Services/QboExpenseSyncService.php';
require_once __DIR__ . '/../app/Modules/Onboarding/Services/OnboardingService.php';
require_once __DIR__ . '/../app/Modules/QuickBooks/Services/QboOnboardingService.php';
