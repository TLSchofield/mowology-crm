<?php
/**
 * Service Data: Spring Cleanup
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'spring-cleanup',
    'title' => 'Spring Cleanup',
    'related_blurb' => 'Beds cleared, lawns raked and edged, winter debris gone — the property ready for the growing season.',

    'meta_title'       => 'Spring Cleanup Vancouver & Burnaby | Mowology',
    'meta_description' => 'Spring garden and yard cleanup for strata, commercial and residential properties in Vancouver, Burnaby and Richmond. Beds, lawns, debris, photo-verified.',
    'meta_keywords'    => 'spring cleanup vancouver, spring yard cleanup burnaby, garden cleanup richmond, strata spring cleanup, spring lawn preparation vancouver',
    'og_image'         => '/assets/img/hero/optimized-services-lawn-care-800x600.jpg',

    'hero' => [
        'headline'    => 'Spring Cleanup That Gets the Whole Property Growing Again',
        'subheadline' => 'Winter leaves beds full of debris, lawns matted and edges lost. One spring visit resets all of it, and you get photos when it is done.',
        'cta_text'    => 'Get a Free Spring Cleanup Quote',
        'cta_url'     => '/quote?service=cleanup&src=spring-cleanup-landing',
        'image'       => '/assets/img/hero/optimized-services-lawn-care-800x600.jpg',
        'image_alt'   => 'Mowology crew clearing garden beds during a spring cleanup in Vancouver',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'What a Spring Cleanup Includes',
            'intro'   => 'Every spring visit follows the same list, so nothing gets skipped between the front entrance and the back fence.',
            'items'   => [
                'Winter debris, fallen branches and leftover leaves removed from lawns, beds and hard surfaces',
                'Garden beds weeded, cut back and re-edged for a clean line against the lawn',
                'Perennials and ornamental grasses cut back; dead growth removed from shrubs',
                'Lawn raked or de-thatched where winter has left it matted',
                'First mow and edge of the season, with clippings removed',
                'Walkways, parking areas and entrances blown clean',
                'All green waste hauled away; before-and-after photos sent to you',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'Why Strata Councils, Property Managers and Homeowners Book Us',
            'items'   => [
                ['title' => 'Photo-Verified', 'desc' => 'You get before-and-after photos of every area we worked on. No driving over to check, no guessing what was done.'],
                ['title' => 'Right Timing', 'desc' => 'We schedule spring cleanups from late February through April, once the Lower Mainland ground has drained enough to work beds without compacting them.'],
                ['title' => 'Strata-Ready Crews', 'desc' => 'Multi-building sites, shared courtyards, visitor parking and entrances are our everyday work. Crews arrive with a plan for the whole property.'],
                ['title' => 'Nothing Left Behind', 'desc' => 'Green waste, branches and bagged debris leave with us. Hard surfaces are blown clean before the crew drives away.'],
                ['title' => 'Insured & Reliable', 'desc' => '$5M liability coverage and a crew that shows up on the scheduled day, rain or shine.'],
                ['title' => 'A Head Start on the Season', 'desc' => 'Pair the cleanup with a weekly maintenance plan and the property never falls behind again.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'How It Works',
            'steps'   => [
                ['title' => 'Tell Us About the Property', 'desc' => 'Send the address and, if you like, a few photos. Strata councils and property managers can attach a site plan.'],
                ['title' => 'Get a Clear Quote', 'desc' => 'We price from the size of the lawns and beds, the amount of debris, and access. One number, no surprises.'],
                ['title' => 'We Clean It Up', 'desc' => 'The crew works through the whole checklist, hauls everything away and sends you the photos the same day.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'When is the best time for a spring cleanup in Vancouver?', 'a' => 'Late February to April. We wait until beds have drained enough to walk on without compacting the soil, then get in before the first strong growth flush so the lawn and beds start the season clean.'],
        ['q' => 'Do you cut back perennials and shrubs during the cleanup?', 'a' => 'Yes. We cut back last year\'s perennial growth and ornamental grasses and remove dead wood from shrubs. Major shrub or hedge shaping is a separate hedge trimming service, which we can schedule for the same visit.'],
        ['q' => 'Can you do a spring cleanup for a strata or commercial site?', 'a' => 'That is most of what we do. Multi-building strata, townhouse complexes and commercial properties across Vancouver, Burnaby and Richmond get a full-site cleanup with photo reports for the council or property manager.'],
        ['q' => 'Is the green waste removed?', 'a' => 'Always. Everything we cut, rake or collect leaves with us. Disposal is included in the quote.'],
        ['q' => 'Can the cleanup be the start of regular maintenance?', 'a' => 'Yes, and it is the best way to do it. A cleanup resets the property; a weekly or bi-weekly maintenance plan keeps it there. Ask for both in one quote.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Spring Cleanup Quote',
        'subheadline'    => 'Send us the address and we will come back with a clear price. No obligation.',
        'primary_text'   => 'Request Free Quote',
        'primary_url'    => '/quote?service=cleanup&src=spring-cleanup-landing',
        'secondary_text' => 'Call 778-846-9273',
        'secondary_url'  => 'tel:7788469273',
    ],

    'schema' => [
        'service_type' => 'Spring Yard and Garden Cleanup',
        'area_served'  => ['Vancouver', 'Burnaby', 'Richmond'],
    ],

    'form_presets' => [
        'service'       => 'cleanup',
        'property_type' => '',
    ],
];
