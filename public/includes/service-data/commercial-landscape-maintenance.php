<?php
/**
 * Service Data: Commercial Landscape Maintenance
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'commercial-landscape-maintenance',
    'title' => 'Commercial Landscape Maintenance',

    'meta_title'       => 'Commercial Lawn Care & Landscape Maintenance Vancouver | Mowology',
    'meta_description' => 'Commercial landscape maintenance for offices, retail centres and business parks in Vancouver, Burnaby and Richmond. Insured crews, photo-verified visits.',
    'meta_keywords'    => 'commercial landscaping vancouver, commercial grounds maintenance, office landscaping burnaby, business park landscaping richmond, commercial lawn care',
    'og_image'         => '/assets/img/hero/hero-lawn-care-1920x1080.jpg',

    'hero' => [
        'headline'    => 'Commercial Landscaping From a Small, Owner-Run Crew',
        'subheadline' => 'We mow, trim and tidy offices, retail centres and business parks in Vancouver, Burnaby and Richmond. You get a photo report after every visit.',
        'cta_text'    => 'Get a Free Commercial Quote',
        'cta_url'     => '/quote?service=maintenance&property_type=commercial&src=commercial-landing',
        'image'       => '/assets/img/hero/optimized-services-lawn-care-800x600.jpg',
        'image_alt'   => 'Mowology crew maintaining commercial property grounds',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'What We Do on a Commercial Property',
            'intro'   => 'The crew keeps the property tidy for your clients, tenants and visitors all year.',
            'items'   => [
                'Lawn mowed, edged and trimmed on a set schedule',
                'Hedges and shrubs kept neat, with clear sight lines',
                'Garden beds weeded, mulched and planted each season',
                'Parking lots and walkways blown clear of debris',
                'Entrance areas detailed and litter picked up',
                'Irrigation checked and adjusted',
                'Seasonal colour rotations in high-visibility areas',
                'Photos of every completed visit',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'How We Work',
            'items'   => [
                ['title' => 'Same day, same time', 'desc' => 'The crew arrives on the same day at the same time. Tenants and customers see a tidy property every week.'],
                ['title' => 'Insured', 'desc' => '$5M commercial liability insurance and WCB coverage. We give you certificates for your property files.'],
                ['title' => 'Photos after every visit', 'desc' => 'Timestamped photos you can pass to ownership, tenants or building management.'],
                ['title' => 'One building or several', 'desc' => 'We look after single office buildings and multi-site portfolios. We add crew and equipment as the work grows.'],
                ['title' => 'Snow and ice', 'desc' => 'Optional winter service: salting, sanding and snow clearing for walkways and parking areas.'],
                ['title' => 'One person to call', 'desc' => 'One person handles scheduling, requests and reporting. One call covers it.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'How It Starts',
            'steps'   => [
                ['title' => 'Request a Quote', 'desc' => 'Tell us about the property. We book a free walkthrough within 48 hours.'],
                ['title' => 'We Walk It', 'desc' => 'We measure the property, note its condition and flag anything that needs doing first.'],
                ['title' => 'Written Proposal', 'desc' => 'You get the scope, frequency, price and seasonal plan in writing.'],
                ['title' => 'Service Begins', 'desc' => 'The crew starts on the agreed schedule. Photo reports begin with the first visit.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'What types of commercial properties do you service?', 'a' => 'We maintain office buildings, retail centres, medical clinics, business parks, restaurants, warehouses and mixed-use sites. If it has outdoor space, we can look after it.'],
        ['q' => 'Can you service multiple locations for our company?', 'a' => 'Yes. We run multi-site portfolios with combined reporting and billing. One person coordinates all locations.'],
        ['q' => 'Do you work around business hours?', 'a' => 'We schedule visits to cause the least disruption. Most commercial work is early morning or off-peak, as you prefer. Crew hours are separate from our office hours (Mon-Fri 8:00-16:00), so a visit does not depend on someone being at the phone.'],
        ['q' => 'What happens during winter months?', 'a' => 'We switch to a winter plan: storm cleanup, leaf removal and optional snow and ice management. Visit frequency changes with the season.'],
        ['q' => 'How quickly can you start service?', 'a' => 'Usually one to two weeks after you approve the proposal, depending on the season.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Commercial Landscaping Quote',
        'subheadline'    => 'We walk the property and send a written proposal. Free, no obligation.',
        'primary_text'   => 'Book a Commercial Site Walk',
        'primary_url'    => '/quote?service=maintenance&property_type=commercial&src=commercial-landing',
        'secondary_text' => 'Call 778-846-9273',
        'secondary_url'  => 'tel:7788469273',
    ],

    'schema' => [
        'service_type' => 'Commercial Landscape Maintenance',
        'area_served'  => ['Vancouver', 'Burnaby', 'Richmond'],
    ],

    'form_presets' => [
        'service'       => 'maintenance',
        'property_type' => 'commercial',
    ],
];
