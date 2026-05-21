<?php
/**
 * One-off: copy Lorne Folick's existing Stripe card from his contact record
 * to the Folick Holdings company record.
 *
 * After this runs:
 *   - Folick Holdings company has the business card (autopay for company invoices)
 *   - Lorne's contact record is cleared so his personal card can be added separately
 *
 * Safe to re-run — checks current state before acting.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
session_write_close();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

// ── 1. Find Folick Holdings ────────────────────────────────────────────────
$company = $db->prepare("
    SELECT id, company_name, stripe_customer_id, stripe_payment_method_id,
           stripe_card_brand, stripe_card_last4, stripe_card_exp
    FROM companies
    WHERE company_name LIKE '%Folick%'
    LIMIT 1
");
$company->execute();
$co = $company->fetch(PDO::FETCH_ASSOC);

if (!$co) {
    echo "ERROR: No company matching 'Folick' found.\n";
    exit;
}

echo "Company found: [{$co['id']}] {$co['company_name']}\n";

if (!empty($co['stripe_payment_method_id'])) {
    echo "Company already has a card on file: {$co['stripe_card_brand']} ···· {$co['stripe_card_last4']}  exp {$co['stripe_card_exp']}\n";
    echo "Nothing to do — company card is already set.\n";
    exit;
}

// ── 2. Find Lorne Folick's contact record (linked to company) ─────────────
$contact = $db->prepare("
    SELECT ct.id, ct.first_name, ct.last_name,
           ct.stripe_customer_id, ct.stripe_payment_method_id,
           ct.stripe_card_brand, ct.stripe_card_last4, ct.stripe_card_exp,
           ct.autopay_enabled, ct.autopay_enrolled_at
    FROM contacts ct
    WHERE (ct.id = (SELECT primary_contact_id FROM companies WHERE id = ?)
           OR ct.id = (SELECT billing_contact_id FROM companies WHERE id = ?))
      AND ct.stripe_payment_method_id IS NOT NULL
    LIMIT 1
");
$contact->execute([$co['id'], $co['id']]);
$ct = $contact->fetch(PDO::FETCH_ASSOC);

if (!$ct) {
    // Fallback: any contact named Folick with a card
    $contact2 = $db->prepare("
        SELECT ct.id, ct.first_name, ct.last_name,
               ct.stripe_customer_id, ct.stripe_payment_method_id,
               ct.stripe_card_brand, ct.stripe_card_last4, ct.stripe_card_exp,
               ct.autopay_enabled, ct.autopay_enrolled_at
        FROM contacts ct
        WHERE ct.last_name LIKE '%Folick%'
          AND ct.stripe_payment_method_id IS NOT NULL
        LIMIT 1
    ");
    $contact2->execute();
    $ct = $contact2->fetch(PDO::FETCH_ASSOC);
}

if (!$ct) {
    echo "ERROR: No contact linked to Folick Holdings with a card on file.\n";
    echo "Check that the contact record has stripe_payment_method_id set.\n";
    exit;
}

echo "Contact found: [{$ct['id']}] {$ct['first_name']} {$ct['last_name']}\n";
echo "Card on contact: {$ct['stripe_card_brand']} ···· {$ct['stripe_card_last4']}  exp {$ct['stripe_card_exp']}\n\n";

// ── 3. Copy card from contact → company ───────────────────────────────────
$db->prepare("
    UPDATE companies
    SET stripe_customer_id       = ?,
        stripe_payment_method_id = ?,
        stripe_card_brand        = ?,
        stripe_card_last4        = ?,
        stripe_card_exp          = ?,
        autopay_enabled          = ?,
        autopay_enrolled_at      = ?,
        updated_at               = NOW()
    WHERE id = ?
")->execute([
    $ct['stripe_customer_id'],
    $ct['stripe_payment_method_id'],
    $ct['stripe_card_brand'],
    $ct['stripe_card_last4'],
    $ct['stripe_card_exp'],
    $ct['autopay_enabled'],
    $ct['autopay_enrolled_at'],
    $co['id'],
]);

echo "✓ Card copied to Folick Holdings company record.\n";

// ── 4. Clear card from contact record ─────────────────────────────────────
$db->prepare("
    UPDATE contacts
    SET stripe_customer_id       = NULL,
        stripe_payment_method_id = NULL,
        stripe_card_brand        = NULL,
        stripe_card_last4        = NULL,
        stripe_card_exp          = NULL,
        autopay_enabled          = 0,
        autopay_enrolled_at      = NULL,
        updated_at               = NOW()
    WHERE id = ?
")->execute([$ct['id']]);

echo "✓ Card cleared from contact record (ready for personal card).\n\n";
echo "Done.\n";
echo "Next step: add Lorne's personal card via the contact record in the CRM,\n";
echo "or have him pay a personal invoice via the customer portal to save his card.\n";
