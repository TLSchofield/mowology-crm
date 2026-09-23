<?php
/**
 * Service Data: Fall Cleanup & Leaf Removal
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'fall-cleanup',
    'title' => 'Fall Cleanup & Leaf Removal',
    'related_blurb' => 'Leaves cleared from lawns, beds, drains and walkways, and the property closed up properly for winter.',

    'meta_title'       => 'Fall Cleanup & Leaf Removal Vancouver | Mowology',
    'meta_description' => 'Fall cleanup and leaf removal for strata, commercial and residential properties in Vancouver, Burnaby and Richmond. Drains cleared, photo-verified.',
    'meta_keywords'    => 'fall cleanup vancouver, leaf removal burnaby, fall yard cleanup richmond, strata fall cleanup, leaf cleanup service vancouver',
    'og_image'         => '/assets/img/hero/optimized-leaf-cleanup-800x600.jpg',

    'hero' => [
        'headline'    => 'Fall Cleanup & Leaf Removal Before the Rain Turns It to Mulch',
        'subheadline' => 'Wet leaves smother lawns, block catch basins and become a slip hazard at every entrance. We clear them, close the beds down and leave the property ready for winter.',
        'cta_text'    => 'Get a Free Fall Cleanup Quote',
        'cta_url'     => '/quote?service=cleanup&src=fall-cleanup-landing',
        'image'       => '/assets/img/hero/optimized-leaf-cleanup-800x600.jpg',
        'image_alt'   => 'Piles of raked autumn leaves ready for removal on a Vancouver property',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'What a Fall Cleanup Includes',
            'intro'   => 'Leaves are the visible part. The parts that protect the property over winter are on the list too.',
            'items'   => [
                'Leaves raked and blown from lawns, garden beds, walkways, parking areas and entrances',
                'Surface catch basins and drain grates cleared of leaves and debris',
                'Perennials cut back and spent annuals removed; beds tidied and edged',
                'Final mow and edge of the season',
                'Dead, damaged or hazardous small branches removed where accessible',
                'Hard surfaces blown clean to reduce slip hazards',
                'All leaves and green waste hauled away; photos sent when the crew is done',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'Why Properties Book Their Fall Cleanup With Us',
            'items'   => [
                ['title' => 'Repeat Visits When You Need Them', 'desc' => 'Leaves do not fall on one day. For heavily treed sites we schedule two or three passes from October into December instead of one visit that is too early or too late.'],
                ['title' => 'Drains Actually Cleared', 'desc' => 'A blocked surface drain in November means water against a foundation or across a walkway. Clearing grates and basins is on every checklist.'],
                ['title' => 'Photo-Verified', 'desc' => 'Before-and-after photos of every visit, so councils and property managers can see the work without a site visit.'],
                ['title' => 'Strata & Commercial Scale', 'desc' => 'Multi-building sites with shared courtyards, visitor parking and long entrance drives are our daily work.'],
                ['title' => 'Everything Hauled Away', 'desc' => 'No leaf piles left at the curb. Disposal is included.'],
                ['title' => 'Insured & Reliable', 'desc' => '$5M liability coverage and crews that show up on the scheduled day, whatever the weather.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'How It Works',
            'steps'   => [
                ['title' => 'Tell Us About the Property', 'desc' => 'Address, rough size and how many large trees. Photos help us plan the number of visits.'],
                ['title' => 'Get a Clear Quote', 'desc' => 'Single visit or a fall program with repeat passes. We recommend the option that fits how treed the site is.'],
                ['title' => 'We Clear It', 'desc' => 'The crew works the checklist, clears the drains, hauls the leaves away and sends the photos.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'When should a fall cleanup be done in Vancouver?', 'a' => 'Most properties need the main cleanup in late October or November, after the bulk of the leaves have dropped. Heavily treed sites do better with two or three passes between mid-October and early December.'],
        ['q' => 'Do you clear the drains and catch basins?', 'a' => 'Yes. Surface catch basins and drain grates are cleared of leaves and debris on every fall cleanup. Underground drainage work is not part of the service.'],
        ['q' => 'Can you do repeat leaf-removal visits?', 'a' => 'Yes. Ask for a fall program and we will schedule the passes for you rather than one visit that is too early or too late.'],
        ['q' => 'What happens to the leaves?', 'a' => 'We haul everything away. Nothing is left at the curb and disposal is included in the quote.'],
        ['q' => 'Do you also handle snow and salting afterwards?', 'a' => 'Yes. Many strata and commercial clients pair the fall cleanup with a winter snow removal and salting plan so the same crew looks after the site all year.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Fall Cleanup Quote',
        'subheadline'    => 'Send us the address and we will come back with a clear price. No obligation.',
        'primary_text'   => 'Request Free Quote',
        'primary_url'    => '/quote?service=cleanup&src=fall-cleanup-landing',
        'secondary_text' => 'Call 778-846-9273',
        'secondary_url'  => 'tel:7788469273',
    ],

    'schema' => [
        'service_type' => 'Fall Cleanup and Leaf Removal',
        'area_served'  => ['Vancouver', 'Burnaby', 'Richmond'],
    ],

    'form_presets' => [
        'service'       => 'cleanup',
        'property_type' => '',
    ],
];
