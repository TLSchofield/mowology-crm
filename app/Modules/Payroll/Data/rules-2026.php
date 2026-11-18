<?php
/**
 * Canadian payroll rules for 2026 — federal and British Columbia (Penny Payroll, step 1, 2026-10-21).
 *
 * Every value is copied from the CRA's own T4127 files (CSV tables + formulas pages), not from a summary:
 *   122nd edition, effective 1 January 2026 — t4127-jan; 123rd edition, effective 1 July 2026 — t4127-jul.
 * An edition applies by PAY DATE (CRA: "commencing with the first payroll in July"), never by work period.
 * These are national/provincial facts shared by every tenant (the universal layer); a tenant's own choices
 * (pay calendar, remitter type) live in the database. To add a year or an edition: a new file / entry with
 * its source and dates — PayrollRulesTest checks sources, dates, overlaps and the published maxima.
 *
 * Quebec is out of scope (QPP, QPIP, Revenu Québec).
 */
return [
    // ── Federal (and CPP / EI, which are national) ───────────────────────────
    [
        'jurisdiction'   => 'CA',
        'edition'        => 'T4127 122nd edition',
        'effective_from' => '2026-01-01',
        'effective_to'   => '2026-12-31',
        'source'         => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas/t4127-jan.html',
        // Table 8.1: annual taxable income thresholds (A), rates (R), constants (K).
        'brackets' => [
            ['from' => 0,       'rate' => 0.14,  'k' => 0],
            ['from' => 58523,   'rate' => 0.205, 'k' => 3804],
            ['from' => 117045,  'rate' => 0.26,  'k' => 10241],
            ['from' => 181440,  'rate' => 0.29,  'k' => 15685],
            ['from' => 258482,  'rate' => 0.33,  'k' => 26024],
        ],
        'lowest_rate' => 0.14,              // used for K1, K2, K4
        // Chapter 2, BPAF: $16,452 up to net income $181,440, falling to $14,829 at $258,482.
        'bpa' => ['max' => 16452, 'min' => 14829, 'phase_from' => 181440, 'phase_to' => 258482],
        'cea' => 1501,                      // Canada employment amount (Table 8.2)
        'cpp' => [                          // Tables 8.3–8.6 (Canada except Quebec)
            'ympe' => 74600, 'basic_exemption' => 3500, 'ymce' => 71100,
            'rate' => 0.0595, 'max' => 4230.45,
            'base_rate' => 0.0495, 'base_max' => 3519.45,      // base part: tax credit (K2)
            'first_additional_rate' => 0.01, 'first_additional_max' => 711,   // deduction from income (F5)
            'yampe' => 85000, 'cpp2_rate' => 0.04, 'cpp2_max' => 416,
        ],
        'ei' => [                           // Table 8.7 (Canada except Quebec)
            'max_insurable' => 68900, 'rate' => 0.0163, 'max_premium' => 1123.07,
            'employer_rate' => 0.02282, 'employer_max' => 1572.30,   // 1.4 × employee
        ],
    ],

    // ── British Columbia, January–June ───────────────────────────────────────
    [
        'jurisdiction'   => 'BC',
        'edition'        => 'T4127 122nd edition',
        'effective_from' => '2026-01-01',
        'effective_to'   => '2026-06-30',
        'source'         => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas/t4127-jan.html',
        'brackets' => [
            ['from' => 0,       'rate' => 0.0506, 'k' => 0],
            ['from' => 50363,   'rate' => 0.077,  'k' => 1330],
            ['from' => 100728,  'rate' => 0.105,  'k' => 4150],
            ['from' => 115648,  'rate' => 0.1229, 'k' => 6220],
            ['from' => 140430,  'rate' => 0.147,  'k' => 9604],
            ['from' => 190405,  'rate' => 0.168,  'k' => 13603],
            ['from' => 265545,  'rate' => 0.205,  'k' => 23428],
        ],
        'lowest_rate' => 0.0506,            // used for K1P, K2P
        'bpa' => ['max' => 13216],          // BPABC (Table 8.2)
        // Chapter 4, Step 5, BC tax reduction S: lesser of T4 or $575, less 3.56% of A over $25,570; nil above $41,722.
        'tax_reduction' => ['basic' => 575, 'threshold' => 25570, 'rate' => 0.0356, 'ceiling' => 41722],
    ],

    // ── British Columbia, July–December (BC Budget 2026: lowest rate 5.06% → 5.60%, prorated) ──
    [
        'jurisdiction'   => 'BC',
        'edition'        => 'T4127 123rd edition',
        'effective_from' => '2026-07-01',
        'effective_to'   => '2026-12-31',
        'source'         => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas/t4127-jul/t4127-jul-payroll-deductions-formulas.html',
        'note'           => 'The 6.14% lowest rate and the $805 reduction are July–December catch-ups: BC raised the lowest rate to 5.60% and the basic reduction to $690 for the whole of 2026, and employers used 5.06% / $575 for the first six months (CRA).',
        'brackets' => [
            ['from' => 0,       'rate' => 0.0614, 'k' => 0],
            ['from' => 50363,   'rate' => 0.077,  'k' => 786],
            ['from' => 100728,  'rate' => 0.105,  'k' => 3606],
            ['from' => 115648,  'rate' => 0.1229, 'k' => 5676],
            ['from' => 140430,  'rate' => 0.147,  'k' => 9061],
            ['from' => 190405,  'rate' => 0.168,  'k' => 13059],
            ['from' => 265545,  'rate' => 0.205,  'k' => 22884],
        ],
        'lowest_rate' => 0.0614,
        'bpa' => ['max' => 13216],
        // S: lesser of T4 or $805, less 3.56% of A over $25,570; nil above $44,952 (the legislated maximum).
        'tax_reduction' => ['basic' => 805, 'threshold' => 25570, 'rate' => 0.0356, 'ceiling' => 44952],
    ],
];
