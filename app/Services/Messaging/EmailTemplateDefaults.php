<?php
/**
 * app/Services/Messaging/EmailTemplateDefaults.php
 *
 * The ONE copy of the default text for the editable customer email templates
 * (email_templates table). Used by:
 *   - loadEmailTemplate() in MessagingService.php   (fallback when the DB row is missing)
 *   - the Settings → Email templates API             (list, "Reset to default", validation, preview)
 *   - public/crm/api/run-migration-email-templates.php (seed)
 *
 * Before 2026-09-30 those three places each carried their own slightly different
 * copy, so a "Reset" or a DB outage could resurrect wording nobody had approved.
 *
 * Voice: .agents/product-marketing-context.md → Brand voice card. No exclamation
 * marks, one button per email labelled verb + what the reader gets, the fact first.
 */

if (!function_exists('emailTemplateDefaults')) {
    /**
     * @return array<string, array{name:string, subject:string, body:string, cta:string, vars:string[]}>
     */
    function emailTemplateDefaults(): array
    {
        $common = ['{{customer_first_name}}', '{{customer_name}}', '{{company_name}}', '{{company_phone}}'];

        return [
            'quote_sent' => [
                'name'    => 'Quote Sent',
                'subject' => 'Your quote from Mowology: {{quote_number}}',
                'cta'     => 'Read the quote',
                'body'    => "Hi {{customer_first_name}},\n\n"
                    . "Your quote is ready: {{quote_amount}}, and it holds until {{quote_valid_until}}.\n\n"
                    . "Open it below and read the scope line by line. If anything is missing, or you'd like it split up differently, reply to this email and we'll adjust it.\n\n"
                    . "Once it reads right, you can accept it online and we'll book the first visit.\n\n"
                    . "{{company_name}}\n{{company_phone}}",
                'vars'    => array_merge($common, ['{{quote_number}}', '{{quote_amount}}', '{{quote_valid_until}}']),
            ],
            'quote_followup' => [
                'name'    => 'Quote Follow-Up',
                'subject' => 'Your quote {{quote_number}}: anything you\'d change?',
                'cta'     => 'Open the quote',
                'body'    => "Hi {{customer_first_name}},\n\n"
                    . "We sent your quote ({{quote_number}}, {{quote_amount}}) a few days ago and haven't heard back. That usually means one of two things: it's sitting in a folder, or something in it isn't right yet.\n\n"
                    . "If it's the second, tell me what. Scope, timing and how the price is split can all change before you sign anything.\n\n"
                    . "The quote holds until {{quote_valid_until}}. After that we'd need to walk the property again before we could book you in.\n\n"
                    . "Tim\n{{company_name}}\n{{company_phone}}",
                'vars'    => array_merge($common, ['{{quote_number}}', '{{quote_amount}}', '{{quote_valid_until}}']),
            ],
            'invoice_sent' => [
                'name'    => 'Invoice Sent',
                'subject' => 'Invoice {{invoice_number}} from Mowology: {{amount_due}} due {{due_date}}',
                'cta'     => 'Pay the invoice',
                'body'    => "Hi {{customer_first_name}},\n\n"
                    . "Here's your invoice for the recent work: {{invoice_number}}, {{amount_due}}, due {{due_date}}.\n\n"
                    . "You can pay by card from the button below, or by any of the methods listed under it.\n\n"
                    . "If a line on it doesn't look right, reply here before the due date and we'll sort it out.\n\n"
                    . "{{company_name}}\n{{company_phone}}"
                    . "\n\nP.S. If the property needs anything else, say a hedge trim, a fall cleanup or aeration before winter, reply here and we'll price it on the next visit.",
                'vars'    => array_merge($common, ['{{invoice_number}}', '{{amount_due}}', '{{due_date}}']),
            ],
            'receipt_sent' => [
                'name'    => 'Payment Receipt',
                'subject' => 'Payment received for {{invoice_number}}',
                'cta'     => 'Get the receipt',
                'body'    => "Hi {{customer_first_name}},\n\n"
                    . "We received your payment of {{amount_paid}} on {{payment_date}} for invoice {{invoice_number}}. It's now marked paid.\n\n"
                    . "Your receipt is at the button below if you need it for your records.\n\n"
                    . "Thank you for the payment.\n\n"
                    . "{{company_name}}\n{{company_phone}}"
                    . "\n\nP.S. If the property needs anything else, say a hedge trim, a fall cleanup or aeration before winter, reply here and we'll price it on the next visit.",
                'vars'    => array_merge($common, ['{{invoice_number}}', '{{amount_paid}}', '{{payment_date}}']),
            ],
            'job_complete' => [
                'name'    => 'Service Complete',
                'subject' => '{{service_type}} done at {{property_address}}, {{job_date}}',
                'cta'     => 'See the photos',
                'body'    => "Hi {{customer_first_name}},\n\n"
                    . "Our crew finished the {{service_type}} at {{property_address}} today.\n\n"
                    . "The report below has the photos they took before they left, what was done, and any notes for you.\n\n"
                    . "If something in the photos isn't how you'd want it, reply to this email and we'll come back to it.\n\n"
                    . "{{company_name}}\n{{company_phone}}"
                    . "\n\nP.S. If the property needs anything else, say a hedge trim, a fall cleanup or aeration before winter, reply here and we'll price it on the next visit.",
                'vars'    => array_merge($common, ['{{service_type}}', '{{job_date}}', '{{property_address}}']),
            ],
        ];
    }
}
