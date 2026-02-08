<?php
/**
 * Service Data: Strata Landscaping & Maintenance
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'strata-landscaping-maintenance',
    'title' => 'Strata Landscaping & Maintenance',

    'meta_title'       => 'Strata Landscaping & Maintenance Vancouver | Mowology',
    'meta_description' => 'Professional strata landscaping and grounds maintenance for Vancouver, Burnaby and Richmond. Photo-verified visits, dedicated account managers, and $5M insurance coverage.',
    'meta_keywords'    => 'strata landscaping vancouver, strata maintenance burnaby, property management landscaping, grounds maintenance richmond, strata council landscaping',
    'og_image'         => '/assets/img/hero/hero-lawn-care-1920x1080.jpg',

    'hero' => [
        'headline'    => 'Strata Landscaping Your Council Can Count On',
        'subheadline' => 'Photo-verified service visits, dedicated account managers, and predictable annual budgets. Trusted by strata councils across Vancouver, Burnaby and Richmond.',
        'cta_text'    => 'Get Your Free Strata Quote',
        'cta_url'     => '/quote?service=maintenance&property_type=strata&src=strata-landing',
        'image'       => '/assets/img/hero/optimized-services-lawn-care-800x600.jpg',
        'image_alt'   => 'Mowology crew maintaining strata property grounds',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'What Your Strata Gets Every Visit',
            'intro'   => 'Our weekly maintenance package covers everything your property needs to stay presentable and compliant.',
            'items'   => [
                'Lawn mowing with professional striping patterns',
                'All edges trimmed along walkways, driveways and garden beds',
                'Hedge trimming and shaping to maintain clean lines',
                'Garden bed weeding and cultivation',
                'Debris and litter removal from all common areas',
                'Pathway and sidewalk blowing',
                'Timestamped photo report emailed after every visit',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'Why Strata Councils Choose Mowology',
            'items'   => [
                ['title' => 'Photo Verification', 'desc' => 'Every service visit includes timestamped photos so your council has proof of completed work for records and AGMs.'],
                ['title' => 'Dedicated Account Manager', 'desc' => 'One point of contact who knows your property, attends site walks, and responds within the hour.'],
                ['title' => '$5M Liability Coverage', 'desc' => 'Full commercial liability insurance and WCB compliance. Certificates provided on request.'],
                ['title' => 'Predictable Annual Budgets', 'desc' => 'Fixed monthly pricing with no surprise fees. Annual service agreements make budgeting straightforward.'],
                ['title' => 'Emergency Response', 'desc' => 'Storm damage, fallen branches, or urgent cleanups. We respond within 24 hours for emergency calls.'],
                ['title' => 'Detailed Monthly Reports', 'desc' => 'Service summaries, seasonal recommendations, and upcoming work schedules sent to your property manager every month.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'Getting Started Is Simple',
            'steps'   => [
                ['title' => 'Request a Quote', 'desc' => 'Fill out our form or call us. We will schedule a free site walk within 48 hours.'],
                ['title' => 'Site Walk & Proposal', 'desc' => 'We inspect your property and deliver a detailed proposal with scope, schedule, and pricing.'],
                ['title' => 'Council Approval', 'desc' => 'Present our proposal at your next council meeting. We are happy to attend and answer questions.'],
                ['title' => 'Service Begins', 'desc' => 'Your dedicated crew starts on the agreed schedule. Photo reports begin from day one.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'How often will your crew visit our strata property?', 'a' => 'Most strata properties are serviced weekly during growing season (March-October) and bi-weekly during winter months. We customize the schedule based on your property needs and budget.'],
        ['q' => 'Can you attend our AGM or council meeting?', 'a' => 'Yes. We are happy to present our service proposal, answer questions from council members, and provide references from other strata properties we maintain.'],
        ['q' => 'Do you handle snow and ice removal?', 'a' => 'Yes. We offer snow and ice management as an add-on service for strata properties, including salting walkways, clearing entrances, and parking area snow removal.'],
        ['q' => 'What areas do you serve?', 'a' => 'We serve strata properties throughout Vancouver, Burnaby, Richmond, and surrounding Lower Mainland communities.'],
        ['q' => 'How do you handle resident complaints or special requests?', 'a' => 'Your dedicated account manager is the single point of contact. Residents can report issues through your property manager, and we address them within one business day.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Strata Landscaping Quote',
        'subheadline'    => 'No obligation. We will schedule a site walk and deliver a detailed proposal for your council.',
        'primary_text'   => 'Request Free Quote',
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
