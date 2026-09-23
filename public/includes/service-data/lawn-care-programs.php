<?php
/**
 * Service Data: Lawn Care Programs (aeration, fertilizing, overseeding)
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'lawn-care-programs',
    'title' => 'Lawn Care Programs: Aeration, Fertilizing & Overseeding',
    'related_blurb' => 'Core aeration, seasonal fertilizing, lime and overseeding scheduled for the Lower Mainland climate.',

    'meta_title'       => 'Lawn Aeration & Fertilizing Vancouver | Mowology',
    'meta_description' => 'Lawn care programs for Vancouver, Burnaby and Richmond: core aeration, fertilizing, lime, overseeding and moss control timed for the coast.',
    'meta_keywords'    => 'lawn aeration vancouver, power raking vancouver, lawn fertilizing burnaby, overseeding richmond, moss control vancouver lawn, lawn care program strata',
    'og_image'         => '/assets/img/hero/optimized-lawn-cut-800.jpg',

    'hero' => [
        'headline'    => 'Lawn Care Programs Built for Wet Winters and Dry Summers',
        'subheadline' => 'Mowing keeps a lawn tidy. Aeration, fertilizing, lime and overseeding at the right times are what keep it thick, green and ahead of the moss. We schedule all of it for you.',
        'cta_text'    => 'Get a Free Lawn Care Quote',
        'cta_url'     => '/quote?service=lawn_care&src=lawn-care-programs-landing',
        'image'       => '/assets/img/hero/optimized-lawn-cut-800.jpg',
        'image_alt'   => 'Freshly cut and edged lawn maintained by Mowology in Vancouver',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'What a Lawn Care Program Can Include',
            'intro'   => 'Programs are built per property. These are the treatments we schedule across the year.',
            'items'   => [
                'Core aeration in spring or fall to relieve compaction and let water and nutrients reach the roots',
                'Seasonal fertilizing matched to the growth cycle, not a fixed calendar',
                'Lime application to counter the acidic soils common across the Lower Mainland',
                'Overseeding of thin or bare areas after aeration',
                'Moss control and power raking (de-thatching) where winter moisture has taken over',
                'Weed control in lawns and along edges',
                'Photo report after every treatment visit',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'Why Properties Put Their Lawns on a Program',
            'items'   => [
                ['title' => 'Timed for the Coast', 'desc' => 'Vancouver lawns face soggy winters, spring moss and summer drought. Treatments are scheduled around that, not around a generic calendar.'],
                ['title' => 'One Crew, Whole Year', 'desc' => 'The crew that mows the lawn also aerates, feeds and overseeds it, so nothing is missed and nothing is double-booked.'],
                ['title' => 'Photo-Verified', 'desc' => 'Every treatment visit ends with photos, so a strata council or homeowner can see it was done and when.'],
                ['title' => 'Less Moss, Fewer Bare Patches', 'desc' => 'Aeration, lime and overseeding tackle the causes of a thin coastal lawn instead of hiding the symptoms.'],
                ['title' => 'Strata & Commercial Scale', 'desc' => 'Large common-property lawns, boulevards and sports fields are scheduled with the equipment to match.'],
                ['title' => 'Insured & Reliable', 'desc' => '$5M liability coverage and visits that happen when the plan says they will.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'How It Works',
            'steps'   => [
                ['title' => 'Tell Us About the Lawn', 'desc' => 'Address, rough lawn area and what bothers you most: moss, thin patches, weeds or colour.'],
                ['title' => 'Get a Program and a Price', 'desc' => 'We recommend the treatments and timing that fit the lawn and send one clear price for the season or the year.'],
                ['title' => 'We Run the Calendar', 'desc' => 'Visits are scheduled and confirmed for you. After each one you get photos and a note on what was done.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'When should lawns be aerated in Vancouver?', 'a' => 'Spring (March to May) or early fall (September to October), when the grass is actively growing and can recover quickly. Fall aeration followed by overseeding is the strongest combination for thin coastal lawns.'],
        ['q' => 'Why does my lawn have so much moss?', 'a' => 'Moss thrives in the shade, moisture and acidic soil that most Lower Mainland lawns have. Power raking removes the moss and thatch; aeration, lime, correct fertilizing and overseeding then change the conditions so grass can outcompete it.'],
        ['q' => 'Do you fertilize on a fixed schedule?', 'a' => 'No. Applications follow the lawn\'s growth cycle and the season, with lime added where soil acidity calls for it. A property with heavy shade gets a different plan than a full-sun boulevard.'],
        ['q' => 'Can the program include weekly mowing?', 'a' => 'Yes. Most clients combine a lawn care program with weekly or bi-weekly mowing so one crew looks after the lawn all year. See our lawn mowing service for details.'],
        ['q' => 'Do you service strata and commercial lawns?', 'a' => 'Yes. Common-property lawns, boulevards and commercial frontages in Vancouver, Burnaby and Richmond are scheduled with the right equipment, and the council or property manager gets a photo report after every visit.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Lawn Care Quote',
        'subheadline'    => 'Tell us what the lawn is doing and we will recommend a program with one clear price. No obligation.',
        'primary_text'   => 'Request Free Quote',
        'primary_url'    => '/quote?service=lawn_care&src=lawn-care-programs-landing',
        'secondary_text' => 'Call 778-846-9273',
        'secondary_url'  => 'tel:7788469273',
    ],

    'schema' => [
        'service_type' => 'Lawn Aeration, Fertilizing and Overseeding',
        'area_served'  => ['Vancouver', 'Burnaby', 'Richmond'],
    ],

    'form_presets' => [
        'service'       => 'lawn_care',
        'property_type' => '',
    ],
];
