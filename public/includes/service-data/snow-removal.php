<?php
/**
 * Service Data: Snow Removal & Salting (strata and commercial)
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'snow-removal',
    'title' => 'Snow Removal & Salting for Strata and Commercial Properties',
    'related_blurb' => 'Walkways, entrances and parking areas cleared and salted, with a time-stamped record of every visit.',

    'meta_title'       => 'Snow Removal & Salting Vancouver Strata | Mowology',
    'meta_description' => 'Snow removal and salting for strata, townhouse and commercial properties in Vancouver, Burnaby and Richmond. Every visit documented with times and photos.',
    'meta_keywords'    => 'snow removal vancouver strata, salting service burnaby, commercial snow removal richmond, ice control strata vancouver, winter property maintenance',
    'og_image'         => '/assets/img/hero/hero-strata-crew.jpg',

    'hero' => [
        'headline'    => 'Snow Removal & Salting Your Council Can Stand Behind',
        'subheadline' => 'Vancouver snow is rare, wet and heavy, and it arrives overnight. We clear and salt the walkways, entrances and parking areas people use first, and every visit is documented with times and photos.',
        'cta_text'    => 'Get a Free Winter Plan Quote',
        'cta_url'     => '/quote?service=snow_removal&src=snow-removal-landing',
        'image'       => '/assets/img/hero/hero-strata-crew.jpg',
        'image_alt'   => 'Mowology crew working on a strata property in Metro Vancouver',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'What a Winter Plan Includes',
            'intro'   => 'The priorities are set with you before the season, so the crew knows which walkway gets cleared first at 5 a.m.',
            'items'   => [
                'Walkways, stairs, building entrances and mailbox areas cleared and salted',
                'Parking areas, drive aisles and visitor stalls cleared as the plan specifies',
                'Salt or de-icer applied before and after snowfall to control ice, not just snow',
                'Repeat visits during a snow event, not one pass and gone',
                'Priority map agreed with the council or property manager before winter',
                'Time-stamped visit log and photos for every clearing and salting visit',
                'Site returned to normal grounds maintenance when the weather clears',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'Why Strata Councils and Property Managers Choose Us for Winter',
            'items'   => [
                ['title' => 'A Record for Every Visit', 'desc' => 'Time-stamped photos and logs of what was cleared and salted, and when. If a slip-and-fall question ever comes up, the council has the record.'],
                ['title' => 'Same Crew, Same Site', 'desc' => 'The crew that maintains the grounds all year knows where the drains, curbs and problem corners are before the snow covers them.'],
                ['title' => 'Ice Handled, Not Just Snow', 'desc' => 'Most Vancouver winter hazards are ice after a thaw and refreeze. Salting visits are scheduled for those days, not only for snowfall.'],
                ['title' => 'Priorities Set in Advance', 'desc' => 'Entrances and walkways first, then parking. Every plan is written down with the council before the season starts.'],
                ['title' => 'Strata & Commercial Scale', 'desc' => 'Townhouse complexes, apartment sites and commercial frontages across Vancouver, Burnaby and Richmond.'],
                ['title' => 'Insured & Reliable', 'desc' => '$5M liability coverage and crews that respond when the weather does.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'How It Works',
            'steps'   => [
                ['title' => 'Site Walk and Priority Map', 'desc' => 'We walk the property with you before winter and agree what gets cleared first, where salt goes and where snow gets piled.'],
                ['title' => 'Seasonal Plan and Price', 'desc' => 'One clear plan for the season with the response terms written down. No surprises when the first snow arrives.'],
                ['title' => 'We Respond and Document', 'desc' => 'Crews clear and salt to the plan, return during the event as needed, and every visit is logged with times and photos.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'Do you offer snow removal for single homes?', 'a' => 'Our winter plans are built for strata, townhouse and commercial properties where shared walkways and parking need to be cleared and documented. Homeowners on an existing maintenance plan can ask us about adding winter visits.'],
        ['q' => 'What is included in a salting visit?', 'a' => 'Salt or de-icer applied to the walkways, stairs, entrances and drive aisles in the priority map, before an expected freeze or after a thaw. Each visit is logged with times and photos.'],
        ['q' => 'How quickly do you respond to snowfall?', 'a' => 'Response terms are agreed in the seasonal plan for each property before winter, with entrances and walkways cleared first. During a longer snow event the crew returns rather than making a single pass.'],
        ['q' => 'Do you keep records for liability purposes?', 'a' => 'Yes. Every clearing and salting visit is time-stamped and photographed, and the record is available to the council or property manager.'],
        ['q' => 'Can winter service be part of our year-round maintenance contract?', 'a' => 'Yes, and most strata clients set it up that way. The same crew handles grounds maintenance in season and snow and ice in winter, under one agreement.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Winter Plan Quote',
        'subheadline'    => 'Tell us about the property and we will book a site walk and send a seasonal plan. No obligation.',
        'primary_text'   => 'Request Free Quote',
        'primary_url'    => '/quote?service=snow_removal&src=snow-removal-landing',
        'secondary_text' => 'Call 778-846-9273',
        'secondary_url'  => 'tel:7788469273',
    ],

    'schema' => [
        'service_type' => 'Snow Removal and Salting',
        'area_served'  => ['Vancouver', 'Burnaby', 'Richmond'],
    ],

    'form_presets' => [
        'service'       => 'snow_removal',
        'property_type' => 'strata',
    ],
];
