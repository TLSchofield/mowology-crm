<?php
/**
 * Expense module constants — single source of truth for category lists.
 *
 * Used by vendors.php, expense-meta.php, and ReceiptSmartMatch.php
 * so the iOS app, web CRM, and OCR engine all use the same categories.
 */
declare(strict_types=1);

const EXPENSE_ACCOUNTING_CATEGORIES = [
    'Materials',
    'Fuel',
    'Tools/Equipment',
    'Repairs/Maintenance',
    'Disposal/Dump',
    'Licenses/Permits',
    'Subcontractors',
    'Marketing',
    'Office/Admin',
    'Overhead',
    'Meals',
    'Vehicle',
    'Safety',
    'Other',
];

const EXPENSE_PAYMENT_METHODS = [
    'credit_card',
    'debit',
    'cash',
    'etransfer',
    'company_card',
    'cheque',
];

const EXPENSE_GBP_CATEGORIES = [
    'Garden center/nursery',
    'Hardware store',
    'Building materials',
    'Equipment rental',
    'Gas station',
    'Waste disposal/landfill',
    'Restaurant/food',
    'Office supply',
    'Auto parts',
    'Wholesale store',
    'Government/municipal',
    'Other',
];
