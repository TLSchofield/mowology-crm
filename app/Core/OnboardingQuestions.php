<?php
/**
 * OnboardingQuestions — the business rules a new company answers once when it joins
 * (owner, 2026-10-10: "the question should be asked as part of onboarding a tenant").
 *
 * Each rule the heads need is a setting with a plain question and Mowology's answer as the
 * default. Until multi-company sign-up exists, the answers live in ops_settings; the onboarding
 * screen will ask these questions in this order and write the same keys.
 *
 * Add a question here whenever a head needs a business rule — never hard-code the answer.
 */
declare(strict_types=1);

final class OnboardingQuestions
{
    /** key => [question, default (Mowology's answer), type, help] */
    public const QUESTIONS = [
        'breaks_paid'                   => ['Do you pay your crew for their breaks?', '1', 'bool', 'Paid: breaks are recorded but pay never changes. Unpaid: they come off paid time.'],
        'cashflow_terms_days'           => ['How many days do clients have to pay an invoice?', '30', 'int', 'Used for invoice due dates and the cash-flow forecast.'],
        'cashflow_floor'                => ['What is the lowest bank balance you are comfortable with?', '2000', 'money', 'Penny warns you before the forecast dips under it.'],
        'cashflow_layoffs'              => ['Is anyone laid off for part of the year? (Name:MM-DD:MM-DD)', 'Nigel Casey:12-01:03-01', 'text', 'Their pay is left out of the forecast in that window.'],
        'cashflow_gst_instalment'       => ['Do you pay GST by fixed monthly instalments? How much?', '0', 'money', '0 = you pay when cash allows; Penny shows what each week could spare.'],
        'cashflow_rc_instalment'        => ['Do you pay income tax by fixed monthly instalments? How much?', '0', 'money', '0 = paid when cash allows.'],
        'plant_markup_min'              => ['What is your minimum markup on plants and materials (%)?', '30', 'int', 'Sam warns if a quote line is priced below it.'],
        'material_delivery_vendor'      => ['Who delivers your bulk material (mulch, soil, rock)?', 'Lawnboy', 'text', 'Otto compares their delivery with trailer runs.'],
        'material_delivery_cost'        => ['What does one delivery cost you?', '73', 'money', ''],
        'material_delivery_charge'      => ['What do you charge a client for a delivery?', '150', 'money', ''],
        'material_delivery_notice_days' => ["How many days' notice does your supplier need?", '3', 'int', ''],
        'trailer_capacity_yards'        => ['How many yards does your trailer carry?', '2', 'int', ''],
        'quote_line_decline_default'    => ['May clients untick lines on a quote before signing?', '1', 'bool', 'Never on contracts.'],
    ];

    public static function keys(): array
    {
        return array_keys(self::QUESTIONS);
    }
}
