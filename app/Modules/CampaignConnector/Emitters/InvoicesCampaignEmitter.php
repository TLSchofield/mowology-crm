<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Contracts/CampaignAware.php';

class InvoicesCampaignEmitter implements CampaignAware
{
    public static function registeredEvents(): array
    {
        return ['invoice_paid', 'invoice_overdue'];
    }

    public static function eventSchema(string $eventName): array
    {
        switch ($eventName) {
            case 'invoice_paid':
                return [
                    'invoice_number' => 'string',
                    'amount'         => 'float — amount paid this transaction',
                    'payment_method' => 'string — e_transfer|cash|cheque|credit_card|other',
                    'transaction_ref'=> 'string|null — e-Transfer confirmation / cheque number',
                    'balance_due'    => 'float — remaining balance after this payment',
                ];
            case 'invoice_overdue':
                return [
                    'invoice_number' => 'string',
                    'days_overdue'   => 'int',
                    'amount_due'     => 'float',
                ];
            default:
                return [];
        }
    }
}
