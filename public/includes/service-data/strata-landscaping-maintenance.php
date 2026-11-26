<?php
/**
 * Service Data: Strata Landscaping & Maintenance
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'strata-landscaping-maintenance',
    'title' => 'Strata Landscaping & Maintenance',

    'meta_title'       => 'Strata Grounds Maintenance Vancouver | Reliable Landscaping for Multi-Unit Properties | Mowology',
    'meta_description' => 'Strata grounds maintenance in Vancouver, Burnaby and Richmond from a small, owner-run crew. Photo report every visit, one person to call, $5M insurance.',
    'meta_keywords'    => 'strata landscaping vancouver, strata maintenance burnaby, property management landscaping, grounds maintenance richmond, strata council landscaping',
    'og_image'         => '/assets/img/hero/hero-lawn-care-1920x1080.jpg',

    'hero' => [
        'headline'    => 'Strata Landscaping From a Small Crew That Shows Up',
        'subheadline' => 'We are a small, owner-run crew. You get a photo report after every visit and one person to call. We work for strata councils in Vancouver, Burnaby and Richmond.',
        'cta_text'    => 'Get Your Free Strata Quote',
        'cta_url'     => '/quote?service=maintenance&property_type=strata&src=strata-landing',
        'image'       => '/assets/img/hero/optimized-services-lawn-care-800x600.jpg',
        'image_alt'   => 'Mowology crew maintaining strata property grounds',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'What Your Strata Gets Every Visit',
            'intro'   => 'This is what the crew does on each weekly visit.',
            'items'   => [
                'Lawn mowed, with striping',
                'Edges trimmed along walkways, driveways and garden beds',
                'Hedges trimmed and shaped',
                'Garden beds weeded and cultivated',
                'Litter and debris cleared from common areas',
                'Paths and sidewalks blown clear',
                'Timestamped photo report emailed after every visit',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'How We Work With Councils',
            'items'   => [
                ['title' => 'Photos of every visit', 'desc' => 'We take timestamped photos on every visit and email them to you. Your council has proof for its records and the AGM.'],
                ['title' => 'One person to call', 'desc' => 'One person knows your property, joins the site walks and answers within the hour.'],
                ['title' => 'Insured', 'desc' => '$5M commercial liability insurance and WCB coverage. We send certificates on request.'],
                ['title' => 'Fixed monthly price', 'desc' => 'No surprise fees. An annual agreement lets the council budget the year.'],
                ['title' => 'Storm damage', 'desc' => 'A fallen branch across a walkway is a liability until it is gone. Call us and we respond within 24 hours.'],
                ['title' => 'Extras quoted first', 'desc' => 'Work outside the weekly scope, like one-off planting or a major cleanup, is quoted first. It needs council approval before we begin.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'How It Starts',
            'steps'   => [
                ['title' => 'Request a Quote', 'desc' => 'Fill out the form or call us. We book a free site walk within 48 hours.'],
                ['title' => 'Site Walk & Proposal', 'desc' => 'We walk the property ourselves. Then we send a proposal with scope, schedule and price.'],
                ['title' => 'Council Approval', 'desc' => 'Take the proposal to your next council meeting. We will come and answer questions if that helps.'],
                ['title' => 'Service Begins', 'desc' => 'The crew starts on the agreed schedule. You get a photo report from the first visit.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'How often will your crew visit our strata property?', 'a' => 'Most strata properties are serviced weekly from March to October and bi-weekly in winter. We set the schedule around your property and budget.'],
        ['q' => 'Can you attend our AGM or council meeting?', 'a' => 'Yes. We will come, present the proposal and answer council questions. We can also give you references from other strata properties we maintain.'],
        ['q' => 'Do you handle snow and ice removal?', 'a' => 'Yes, as an add-on. We salt walkways, clear entrances and clear snow from parking areas.'],
        ['q' => 'What is NOT included in the weekly package?', 'a' => 'Snow and ice management is a separate add-on. Extra work outside the weekly scope, like a one-off planting or a major cleanup, is quoted first and your council approves it before we start. We do not take on tree work above 4 metres. We refer you to a certified arborist rather than guess.'],
        ['q' => 'What areas do you serve?', 'a' => 'Strata properties across Vancouver, Burnaby, Richmond and nearby Lower Mainland communities.'],
        ['q' => 'How do you handle resident complaints or special requests?', 'a' => 'Residents report issues through your property manager. You deal with one person at our end, and we address the issue within one business day.'],
    ],

    'cta' => [
        'headline'       => 'Book a Free Strata Site Walk',
        'subheadline'    => 'No obligation. We walk the property ourselves and send a proposal your council can act on.',
        'primary_text'   => 'Book a Free Strata Site Walk',
        'primary_url'    => '/quote?service=maintenance&property_type=strata&src=strata-landing',
        'secondary_text' => 'Call 778-846-9273',
        'secondary_url'  => 'tel:7788469273',
    ],

    'schema' => [
        'service_type' => 'Strata Landscaping and Grounds Maintenance',
        'area_served'  => ['Vancouver', 'Burnaby', 'Richmond'],
    ],

    'form_presets' => [
        'service'       => 'maintenance',
        'property_type' => 'strata',
    ],
];
