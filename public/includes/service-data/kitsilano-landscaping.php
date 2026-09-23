<?php
/**
 * Service Data: Kitsilano Landscaping
 *
 * Built from Search Console: "kitsilano landscaping" sits at position 9.7 with no page of
 * its own (the existing Kitsilano page is lawn-care only). A neighbourhood page that
 * covers the full landscaping and maintenance offer is the shortest route to page one.
 * The crew's Kitsilano before/after pairs (portfolio queue) belong on this page.
 */
return [
    'slug'  => 'kitsilano-landscaping',
    'title' => 'Kitsilano Landscaping & Garden Maintenance',

    'meta_title'       => 'Kitsilano Landscaping & Garden Maintenance | Mowology',
    'meta_description' => 'Landscaping and garden maintenance in Kitsilano by a crew that is in the neighbourhood every week. Lawns, hedges, beds, cleanups and small installs for Kits homes, laneway houses and character lots.',
    'meta_keywords'    => 'kitsilano landscaping, kitsilano gardener, kitsilano lawn care, landscaping kits vancouver, garden maintenance kitsilano',
    'og_image'         => '/assets/img/hero/hero-hedge-trimming-point-grey.jpeg',

    'hero' => [
        'headline'    => 'Landscaping in <em>Kitsilano</em>, by the Crew Already on Your Street',
        'subheadline' => 'We run Kitsilano routes every week — West 1st to West 16th, Alma to Burrard. Garden maintenance, hedges, lawns, cleanups and small landscape installs for character homes, duplexes and laneway houses.',
        'cta_text'    => 'Get a Kitsilano Quote →',
        'cta_url'     => '/quote?service=maintenance&src=kitsilano-landscaping',
        'image'       => '/assets/img/hero/hero-hedge-trimming-point-grey.jpeg',
        'image_alt'   => 'Freshly trimmed laurel hedge on a Vancouver west side character home',
    ],

    'proof_sections' => [
        [
            'type'    => 'benefits',
            'heading' => 'Why Kits Homeowners Choose a Local Crew',
            'intro'   => 'Kitsilano lots are narrow, gardens are mature, and the neighbours notice. That shapes how we work here.',
            'items'   => [
                ['title' => 'We are here anyway', 'desc' => 'Kitsilano is one of our densest routes, so there is no travel premium and a same-week visit is usually possible.'],
                ['title' => 'Mature gardens, handled properly', 'desc' => 'Kits gardens are often 50 to 100 years old: established laurels, rhododendrons, Japanese maples and roses. They are pruned for the plant, not hacked to a shape.'],
                ['title' => 'Narrow lots, tight access', 'desc' => 'Side yards a wheelbarrow barely fits through, shared laneways, laneway houses at the back. We bring equipment sized for the lot.'],
                ['title' => 'Quiet, tidy, considerate', 'desc' => 'Electric equipment where it does the job, sensible hours on a residential street, and every path blown clean before we leave.'],
                ['title' => 'Photo report every visit', 'desc' => 'Before and after photos to your phone. Useful when the house is rented, when you are away, or just to see the hedge from the lane side.'],
                ['title' => 'One account for everything', 'desc' => 'Weekly maintenance, a spring cleanup, a new bed by the front steps, and fall leaf removal all on one account and one invoice.'],
            ],
        ],
        [
            'type'    => 'checklist',
            'heading' => 'Landscaping & Garden Services in Kitsilano',
            'intro'   => 'Most Kits clients start with maintenance and add projects as they come up.',
            'items'   => [
                'Garden maintenance — weekly or bi-weekly care of beds, borders and containers, with seasonal pruning and cutbacks.',
                'Lawn care — mowing and edging for the front lawn most Kits homes still have, plus moss control, aeration and overseeding for shaded west side lawns.',
                'Hedge trimming — laurel, cedar, boxwood and privet hedges shaped on rotation; overgrown hedges reduced in stages so they recover.',
                'Spring and fall cleanups — beds cut back, leaves cleared, gutters and paths blown, green waste removed.',
                'Small landscape installs — new beds, planting, sod, mulch, gravel paths and simple drainage fixes for water pooling by the foundation.',
                'Laneway and duplex properties — shared yards and strata-titled duplexes maintained under one agreement, with each unit\'s report kept separate if needed.',
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'Getting Started',
            'steps'   => [
                ['title' => 'Send the address',   'desc' => 'We probably know the block. Add a line about what you want kept up or changed.'],
                ['title' => 'Quick site visit',    'desc' => 'Often same week, since we are in Kits already. We measure and talk through the garden with you.'],
                ['title' => 'Fixed quote',         'desc' => 'Per visit or per month for maintenance; a fixed price for any project work.'],
                ['title' => 'On the route',        'desc' => 'Your property joins the Kitsilano route on a set day, with a photo report after each visit.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'Do you cover all of Kitsilano?', 'a' => 'Yes: from Burrard to Alma and from the beach up to West 16th, including the blocks around Kits Beach, Arbutus Ridge borders and the West Broadway corridor.'],
        ['q' => 'What does garden maintenance cost in Kitsilano?', 'a' => 'It depends on how much planted area and hedge a lot has, so we quote after seeing it. Lawn-only visits start at $45. A typical Kits lot with a front lawn, hedge and beds on a bi-weekly schedule is quoted as a fixed per-visit price before we start.'],
        ['q' => 'Can you deal with an overgrown laurel hedge?', 'a' => 'Yes. Old laurels are reduced in stages over one or two seasons so they regrow evenly, rather than cut hard once and left with bare wood.'],
        ['q' => 'My lawn is mostly moss. Is it worth keeping?', 'a' => 'Shaded west side lawns often are. Moss control, aeration, overseeding with a shade blend and adjusting the mowing height usually brings them back over a season. If not, we can talk about a planted alternative.'],
        ['q' => 'Do you work on rented or laneway properties?', 'a' => 'Often. Owners get the photo report, tenants get a crew that is considerate and predictable, and the invoice goes wherever you tell us.'],
        ['q' => 'Do you do design and installation too?', 'a' => 'Small installs, yes: new beds, planting plans, sod, mulch, gravel paths and minor drainage. Larger hardscape and full redesigns we refer to partners we trust.'],
    ],

    'cta' => [
        'headline'       => 'Want Us to Take a Look This Week?',
        'subheadline'    => 'We are in Kitsilano most days. Send the address and we will fit a visit into the route.',
        'primary_text'   => 'Request Your Free Quote →',
        'primary_url'    => '/quote?service=maintenance&src=kitsilano-landscaping',
        'secondary_text' => 'Call 778-846-9273',
        'secondary_url'  => 'tel:7788469273',
    ],

    'schema' => [
        'service_type' => 'Landscaping & Garden Maintenance',
        'area_served'  => ['Kitsilano', 'Vancouver'],
    ],

    'form_presets' => [
        'service'       => 'maintenance',
        'property_type' => 'residential',
    ],

    'marketing' => [
        'campaign_id'  => 'kitsilano-landscaping-gsc-2026',
        'source_tag'   => 'kitsilano-landscaping',
        'utm_source'   => 'organic',
        'utm_medium'   => 'search',
        'utm_campaign' => 'gsc-kitsilano-landscaping',
        'attribution'  => [
            'lead_source'  => 'Google Search Console - Organic Search',
            'channel'      => 'Organic Search',
            'medium'       => 'Search',
            'gsc_keywords' => ['kitsilano landscaping', 'kitsilano gardener', 'landscaping kits vancouver'],
        ],
    ],
];
