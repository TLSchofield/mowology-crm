<?php
/**
 * Service Data: Hedge Trimming & Shaping
 * CMS-ready: replace this file with a database read returning the same array shape.
 */
return [
    'slug'  => 'hedge-trimming',
    'title' => 'Hedge Trimming & Shaping',

    'meta_title'       => 'Hedge Trimming Vancouver & Burnaby | Mowology',
    'meta_description' => 'Hedge trimming and shaping in Vancouver, Burnaby and Richmond. Laurel, cedar, boxwood and more. Clean lines, proper timing, full cleanup included.',
    'meta_keywords'    => 'hedge trimming vancouver, hedge shaping burnaby, laurel hedge trimming, cedar hedge maintenance, hedge cutting richmond, professional hedge service',
    'og_image'         => '/assets/img/hero/hero-lawn-care-1920x1080.jpg',

    'hero' => [
        'headline'    => 'Hedge Trimming Done Properly, Cleaned Up Before We Leave',
        'subheadline' => 'We trim laurel, cedar, boxwood, privet and the other hedges common in the Lower Mainland. We cut them properly and haul the clippings away.',
        'cta_text'    => 'Get a Free Hedge Quote',
        'cta_url'     => '/quote?service=hedge_trimming&src=hedge-landing',
        'image'       => '/assets/img/hero/optimized-services-lawn-care-800x600.jpg',
        'image_alt'   => 'Mowology crew member trimming a tall laurel hedge on a ladder',
    ],

    'proof_sections' => [
        [
            'type'    => 'checklist',
            'heading' => 'Every Hedge Trim Includes',
            'intro'   => 'Every hedge trim follows the same steps.',
            'items'   => [
                'Hedge shaped to keep or restore clean lines',
                'Top, sides and face trimmed to the height and width you want',
                'Proper cuts that keep the hedge growing healthy',
                'Dead wood and debris cleared from inside where we can reach',
                'All clippings cleaned up and hauled away',
                'Walkways, driveways and beds left clean',
                'Photo of the finished hedge sent to you',
            ],
        ],
        [
            'type'    => 'benefits',
            'heading' => 'How We Do It',
            'items'   => [
                ['title' => 'Proper Technique', 'desc' => 'We cut the hedge slightly wider at the base than the top. Sunlight reaches all the leaves and the hedge stays thick.'],
                ['title' => 'Every common species', 'desc' => 'Laurel, cedar, boxwood, privet, yew, holly, photinia, Portuguese laurel. Each one gets cut the way it likes.'],
                ['title' => 'Tall hedges', 'desc' => 'We bring ladders, scaffolding and extended-reach tools for hedges up to 15 feet.'],
                ['title' => 'Cleanup included', 'desc' => 'We never leave clippings behind. We clean up, bag and haul them away as part of the job.'],
                ['title' => 'Your schedule', 'desc' => 'A one-time trim, a seasonal package or a regular schedule. Whatever suits the property.'],
                ['title' => 'Insured', 'desc' => '$5M liability coverage. The crew arrives on time and leaves the property cleaner than we found it.'],
            ],
        ],
        [
            'type'    => 'process',
            'heading' => 'How It Works',
            'steps'   => [
                ['title' => 'Tell Us About Your Hedges', 'desc' => 'Send a quote request with your address. Photos of the hedges help, but you do not need them.'],
                ['title' => 'We Provide a Quote', 'desc' => 'We price from hedge length, height, species and access. You get a clear price with no hidden fees.'],
                ['title' => 'We Do the Work', 'desc' => 'The crew arrives on the scheduled day, trims the hedges, cleans up and sends you a photo.'],
            ],
        ],
    ],

    'faq' => [
        ['q' => 'How often should hedges be trimmed?', 'a' => 'Most Lower Mainland hedges need two or three trims a year: late spring after the first growth, mid-summer, and sometimes early fall. Fast growers like laurel may need more.'],
        ['q' => 'Can you reduce the height of an overgrown hedge?', 'a' => 'Yes. Most species take a big height reduction. We look at your hedge first and tell you what is safe. Cedar does not regrow from bare wood, so we cut to the right point.'],
        ['q' => 'Do you remove the clippings?', 'a' => 'Yes, every time. Cleanup and haul-away are part of every trim.'],
        ['q' => 'What about hedges on a property line?', 'a' => 'We trim your side. If both neighbours want the whole hedge done, we will coordinate. Check with your neighbour before we book a shared hedge.'],
        ['q' => 'How much does hedge trimming cost?', 'a' => 'It depends on length, height, species and access. Most residential trims in Vancouver run $150 to $500. Ask for a quote and we will give you the exact price.'],
    ],

    'cta' => [
        'headline'       => 'Get a Free Hedge Trimming Quote',
        'subheadline'    => 'Tell us about the hedges and we will send a clear price. No obligation.',
        'primary_text'   => 'Get My Hedge Trimming Quote',
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
