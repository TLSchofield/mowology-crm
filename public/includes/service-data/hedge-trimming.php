<?php
/**
 * Service Data: Hedge Trimming & Shaping
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'hedge-trimming',
    'title' => 'Hedge Trimming & Shaping',

    'meta_title'       => 'Hedge Trimming Vancouver | Professional Hedge Shaping | Mowology',
    'meta_description' => 'Professional hedge trimming and shaping in Vancouver, Burnaby and Richmond. Laurel, cedar, boxwood, and more. Clean lines, proper technique, full cleanup included.',
    'meta_keywords'    => 'hedge trimming vancouver, hedge shaping burnaby, laurel hedge trimming, cedar hedge maintenance, hedge cutting richmond, professional hedge service',
    'og_image'         => '/assets/img/hero/hero-lawn-care-1920x1080.jpg',

    'hero' => [
        'headline'    => 'Professional Hedge Trimming & Shaping',
        'subheadline' => 'Clean lines, proper pruning technique, and full cleanup. We handle laurel, cedar, boxwood, privet, and every hedge species common to the Lower Mainland.',
        'cta_text'    => 'Get a Free Hedge Quote',
        'cta_url'     => '/quote?service=hedge_trimming&src=hedge-landing',
        'image'       => '/assets/img/hero/optimized-services-lawn-care-800x600.jpg',
        'image_alt'   => 'Mowology crew member trimming a tall laurel hedge on a ladder',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'Every Hedge Trim Includes',
            'intro'   => 'No corners cut. Every hedge service follows the same thorough process.',
            'items'   => [
                'Full hedge shaping to maintain or restore clean lines',
                'Top, sides, and face trimmed to your preferred height and width',
                'Proper pruning cuts that promote healthy growth',
                'Interior dead wood and debris removal where accessible',
                'All clippings cleaned up and hauled away',
                'Walkways, driveways, and garden beds left clean',
                'Photo of the finished hedge sent to you',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'Why Homeowners and Property Managers Choose Us',
            'items'   => [
                ['title' => 'Proper Technique', 'desc' => 'We cut hedges correctly, slightly wider at the base than the top, so sunlight reaches all foliage and your hedge stays thick.'],
                ['title' => 'All Species Handled', 'desc' => 'Laurel, cedar, boxwood, privet, yew, holly, photinia, Portuguese laurel. We know the right approach for each species.'],
                ['title' => 'Tall Hedges No Problem', 'desc' => 'We have ladders, scaffolding, and extended-reach equipment for hedges up to 15 feet. No hedge is too tall.'],
                ['title' => 'Full Cleanup Included', 'desc' => 'We never leave clippings behind. All debris is cleaned up, bagged, and hauled away as part of the service.'],
                ['title' => 'Flexible Scheduling', 'desc' => 'One-time trims, seasonal packages, or regular maintenance schedules. Whatever fits your property needs.'],
                ['title' => 'Insured & Reliable', 'desc' => '$5M liability coverage. Crews arrive on time, do the work right, and leave your property cleaner than they found it.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'How It Works',
            'steps'   => [
                ['title' => 'Tell Us About Your Hedges', 'desc' => 'Submit a quote request with your address. Photos of your hedges are helpful but not required.'],
                ['title' => 'We Provide a Quote', 'desc' => 'Based on hedge length, height, species, and access, we send you a clear price with no hidden fees.'],
                ['title' => 'We Do the Work', 'desc' => 'Our crew arrives on the scheduled day, trims your hedges, cleans up all debris, and sends you a photo.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'How often should hedges be trimmed?', 'a' => 'Most hedges in the Lower Mainland benefit from two to three trims per year: once in late spring after the first growth flush, once in mid-summer, and optionally once in early fall. Fast-growing species like laurel may need more frequent attention.'],
        ['q' => 'Can you reduce the height of an overgrown hedge?', 'a' => 'Yes. Most hedge species tolerate significant height reduction. We will assess your specific hedge and recommend the best approach. Some species like cedar do not grow back from bare wood, so we take care to cut to the right point.'],
        ['q' => 'Do you remove the clippings?', 'a' => 'Yes, always. Full cleanup and debris removal is included in every hedge trimming service. We leave your property clean.'],
        ['q' => 'What about hedges on a property line?', 'a' => 'We trim your side of the hedge. If both neighbours want the full hedge trimmed, we are happy to coordinate. We recommend checking with your neighbour before scheduling work on a shared hedge.'],
        ['q' => 'How much does hedge trimming cost?', 'a' => 'Pricing depends on hedge length, height, species, and access. Most residential hedge trims in Vancouver range from $150 to $500. Request a quote for an exact price for your property.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Hedge Trimming Quote',
        'subheadline'    => 'Tell us about your hedges and we will send you a clear price. No obligation.',
        'primary_text'   => 'Request Free Quote',
        'primary_url'    => '/quote?service=hedge_trimming&src=hedge-landing',
        'secondary_text' => 'Call 778-846-9273',
        'secondary_url'  => 'tel:7788469273',
    ],

    'schema' => [
        'service_type' => 'Hedge Trimming and Shaping',
        'area_served'  => ['Vancouver', 'Burnaby', 'Richmond'],
    ],

    'form_presets' => [
        'service'       => 'hedge_trimming',
        'property_type' => '',
    ],
];
