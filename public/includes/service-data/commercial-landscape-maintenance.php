<?php
/**
 * Service Data: Commercial Landscape Maintenance
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'commercial-landscape-maintenance',
    'title' => 'Commercial Landscape Maintenance',

    'meta_title'       => 'Commercial Landscape Maintenance Vancouver | Mowology',
    'meta_description' => 'Professional commercial landscape maintenance for offices, retail centres, and business parks in Vancouver, Burnaby and Richmond. Reliable crews, full insurance, and photo-verified service.',
    'meta_keywords'    => 'commercial landscaping vancouver, commercial grounds maintenance, office landscaping burnaby, business park landscaping richmond, commercial lawn care',
    'og_image'         => '/assets/img/hero/hero-lawn-care-1920x1080.jpg',

    'hero' => [
        'headline'    => 'Commercial Landscaping That Reflects Your Business',
        'subheadline' => 'First impressions start at the curb. We keep your commercial property looking sharp with reliable, photo-verified grounds maintenance across Vancouver, Burnaby and Richmond.',
        'cta_text'    => 'Get Your Free Commercial Quote',
        'cta_url'     => '/quote?service=maintenance&property_type=commercial&src=commercial-landing',
        'image'       => '/assets/img/hero/optimized-services-lawn-care-800x600.jpg',
        'image_alt'   => 'Mowology crew maintaining commercial property grounds',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'Full-Service Commercial Grounds Care',
            'intro'   => 'Our commercial maintenance package keeps your property presentable to clients, tenants, and visitors year-round.',
            'items'   => [
                'Lawn mowing, edging, and trimming on a set schedule',
                'Hedge and shrub maintenance for clean sight lines',
                'Garden bed weeding, mulching, and seasonal planting',
                'Parking lot and walkway blowing and debris removal',
                'Entrance area detailing and litter pickup',
                'Irrigation monitoring and adjustment',
                'Seasonal colour rotations for high-visibility areas',
                'Photo documentation of every completed visit',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'Why Commercial Properties Choose Mowology',
            'items'   => [
                ['title' => 'Consistent Schedule', 'desc' => 'Your crew arrives on the same day, same time. Tenants and customers see a well-maintained property every week.'],
                ['title' => 'Full Insurance & WCB', 'desc' => '$5M commercial liability coverage and full WCB compliance. Certificates available for your property files.'],
                ['title' => 'Photo Proof of Service', 'desc' => 'Timestamped photos after every visit. Share with ownership, tenants, or building management as needed.'],
                ['title' => 'Scalable Service Plans', 'desc' => 'From single office buildings to multi-site portfolios, we scale our crews and equipment to match your needs.'],
                ['title' => 'Snow & Ice Management', 'desc' => 'Optional winter services including salting, sanding, and snow clearing for walkways and parking areas.'],
                ['title' => 'Single Point of Contact', 'desc' => 'Your dedicated account manager handles scheduling, service requests, and reporting. One call handles everything.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'Getting Started Is Straightforward',
            'steps'   => [
                ['title' => 'Request a Quote', 'desc' => 'Tell us about your property. We will schedule a free walkthrough within 48 hours.'],
                ['title' => 'Property Assessment', 'desc' => 'We measure your property, assess current conditions, and identify any immediate needs.'],
                ['title' => 'Custom Proposal', 'desc' => 'Receive a detailed proposal with scope of work, service frequency, pricing, and seasonal plan.'],
                ['title' => 'Reliable Service', 'desc' => 'Your dedicated crew begins service on the agreed schedule with photo verification from day one.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'What types of commercial properties do you service?', 'a' => 'We maintain office buildings, retail centres, medical clinics, business parks, restaurants, warehouses, and mixed-use developments. If it has outdoor space, we can maintain it.'],
        ['q' => 'Can you service multiple locations for our company?', 'a' => 'Yes. We manage multi-site portfolios with centralized reporting and billing. One account manager coordinates all locations.'],
        ['q' => 'Do you work around business hours?', 'a' => 'We schedule services to minimize disruption. Most commercial work is done early morning or during off-peak hours, depending on your preferences.'],
        ['q' => 'What happens during winter months?', 'a' => 'We transition to a winter maintenance plan that includes storm cleanup, leaf removal, and optional snow and ice management. Service frequency adjusts to seasonal needs.'],
        ['q' => 'How quickly can you start service?', 'a' => 'After the proposal is approved, we can typically begin service within one to two weeks, depending on the season.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Commercial Landscaping Quote',
        'subheadline'    => 'Free property assessment and detailed proposal. No obligation.',
        'primary_text'   => 'Request Free Quote',
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
