<?php
/**
 * Block Type Registry
 *
 * Defines every available section module for the CMS page editor.
 * Each block type has a label, description, icon, field schema, and CSS preview class.
 *
 * Field types: text, textarea, richtext, url, select, image, repeater
 */

return [

    // ── Hero Banner ──────────────────────────────────────────────────────────
    'hero' => [
        'label'       => 'Hero Banner',
        'description' => 'Full-width headline, subheadline, and CTA button',
        'icon'        => 'layout',
        'preview_css' => 'btp-hero',
        'fields'      => [
            'badge_text'  => ['type' => 'text',     'label' => 'Badge Text',    'placeholder' => 'Professional Service'],
            'headline'    => ['type' => 'text',     'label' => 'Headline',      'placeholder' => 'Premium Landscaping Services', 'required' => true],
            'subheadline' => ['type' => 'textarea', 'label' => 'Subheadline',   'placeholder' => 'Residential & commercial grounds maintenance'],
            'cta_text'    => ['type' => 'text',     'label' => 'Button Text',   'placeholder' => 'Get a Free Quote'],
            'cta_url'     => ['type' => 'url',      'label' => 'Button URL',    'placeholder' => '/contact'],
            'background'  => ['type' => 'select',   'label' => 'Background',
                'options' => ['dark' => 'Dark Forest', 'primary' => 'Brand Green', 'light' => 'Light Grey', 'white' => 'White']],
            'align'       => ['type' => 'select',   'label' => 'Text Alignment',
                'options' => ['left' => 'Left', 'center' => 'Center']],
        ],
        'defaults'    => [
            'badge_text' => '', 'headline' => '', 'subheadline' => '',
            'cta_text' => 'Get a Free Quote', 'cta_url' => '/contact',
            'background' => 'dark', 'align' => 'center',
        ],
    ],

    // ── Features Grid ────────────────────────────────────────────────────────
    'features' => [
        'label'       => 'Features Grid',
        'description' => 'Grid of feature cards with icon, title, and description',
        'icon'        => 'grid',
        'preview_css' => 'btp-features',
        'fields'      => [
            'headline'  => ['type' => 'text',     'label' => 'Section Headline', 'placeholder' => 'Why Choose Us'],
            'subtext'   => ['type' => 'textarea', 'label' => 'Section Subtext',  'placeholder' => 'What sets us apart'],
            'columns'   => ['type' => 'select',   'label' => 'Grid Columns',
                'options' => ['2' => '2 Columns', '3' => '3 Columns', '4' => '4 Columns']],
            'background'=> ['type' => 'select',   'label' => 'Background',
                'options' => ['white' => 'White', 'light' => 'Light Grey', 'tint' => 'Green Tint']],
            'items'     => [
                'type'  => 'repeater',
                'label' => 'Features',
                'max'   => 6,
                'item_fields' => [
                    'icon'  => ['type' => 'text',     'label' => 'Icon',        'placeholder' => 'check-circle'],
                    'title' => ['type' => 'text',     'label' => 'Title',       'placeholder' => 'Feature Name',    'required' => true],
                    'body'  => ['type' => 'textarea', 'label' => 'Description', 'placeholder' => 'Describe this feature'],
                ],
            ],
        ],
        'defaults' => [
            'headline' => '', 'subtext' => '', 'columns' => '3', 'background' => 'white',
            'items' => [
                ['icon' => 'check-circle', 'title' => '', 'body' => ''],
                ['icon' => 'check-circle', 'title' => '', 'body' => ''],
                ['icon' => 'check-circle', 'title' => '', 'body' => ''],
            ],
        ],
    ],

    // ── Text / Rich Content ──────────────────────────────────────────────────
    'text_block' => [
        'label'       => 'Text Block',
        'description' => 'One or two columns of rich text content',
        'icon'        => 'align-left',
        'preview_css' => 'btp-text',
        'fields'      => [
            'layout'    => ['type' => 'select', 'label' => 'Layout',
                'options' => ['full' => 'Full Width', 'two-col' => 'Two Columns']],
            'background'=> ['type' => 'select', 'label' => 'Background',
                'options' => ['white' => 'White', 'light' => 'Light Grey', 'tint' => 'Green Tint']],
            'content'   => ['type' => 'richtext', 'label' => 'Content (Left / Main)',  'required' => true],
            'content2'  => ['type' => 'richtext', 'label' => 'Content (Right — two-col only)'],
        ],
        'defaults' => ['layout' => 'full', 'background' => 'white', 'content' => '', 'content2' => ''],
    ],

    // ── CTA Banner ───────────────────────────────────────────────────────────
    'cta_banner' => [
        'label'       => 'CTA Banner',
        'description' => 'Full-width call-to-action strip with headline and button',
        'icon'        => 'zap',
        'preview_css' => 'btp-cta',
        'fields'      => [
            'headline'  => ['type' => 'text',     'label' => 'Headline',    'placeholder' => 'Ready to get started?', 'required' => true],
            'subtext'   => ['type' => 'textarea', 'label' => 'Subtext',     'placeholder' => 'Contact us for a free estimate'],
            'cta_text'  => ['type' => 'text',     'label' => 'Button Text', 'placeholder' => 'Get a Quote'],
            'cta_url'   => ['type' => 'url',      'label' => 'Button URL',  'placeholder' => '/contact'],
            'style'     => ['type' => 'select',   'label' => 'Style',
                'options' => ['primary' => 'Green (Primary)', 'dark' => 'Dark Forest', 'accent' => 'Orange Accent']],
        ],
        'defaults' => ['headline' => '', 'subtext' => '', 'cta_text' => 'Get a Quote', 'cta_url' => '/contact', 'style' => 'primary'],
    ],

    // ── Image + Text ─────────────────────────────────────────────────────────
    'image_text' => [
        'label'       => 'Image + Text',
        'description' => 'Side-by-side image and text, image left or right',
        'icon'        => 'image',
        'preview_css' => 'btp-imgtext',
        'fields'      => [
            'image_url'      => ['type' => 'image',    'label' => 'Image URL',         'placeholder' => '/assets/img/example.jpg'],
            'image_alt'      => ['type' => 'text',     'label' => 'Image Alt Text'],
            'image_position' => ['type' => 'select',   'label' => 'Image Position',
                'options' => ['left' => 'Image Left', 'right' => 'Image Right']],
            'badge_text'     => ['type' => 'text',     'label' => 'Badge Text',        'placeholder' => 'Our Story'],
            'headline'       => ['type' => 'text',     'label' => 'Headline',          'placeholder' => 'Built on trust', 'required' => true],
            'body'           => ['type' => 'richtext', 'label' => 'Body Text'],
            'cta_text'       => ['type' => 'text',     'label' => 'Button Text',       'placeholder' => 'Learn More'],
            'cta_url'        => ['type' => 'url',      'label' => 'Button URL',        'placeholder' => '/about'],
            'background'     => ['type' => 'select',   'label' => 'Background',
                'options' => ['white' => 'White', 'light' => 'Light Grey', 'tint' => 'Green Tint']],
        ],
        'defaults' => [
            'image_url' => '', 'image_alt' => '', 'image_position' => 'left',
            'badge_text' => '', 'headline' => '', 'body' => '',
            'cta_text' => '', 'cta_url' => '', 'background' => 'white',
        ],
    ],

    // ── Testimonials ─────────────────────────────────────────────────────────
    'testimonials' => [
        'label'       => 'Testimonials',
        'description' => 'Customer quotes displayed as cards',
        'icon'        => 'message-square',
        'preview_css' => 'btp-testimonials',
        'fields'      => [
            'headline'  => ['type' => 'text',   'label' => 'Section Headline', 'placeholder' => 'What Our Clients Say'],
            'background'=> ['type' => 'select', 'label' => 'Background',
                'options' => ['white' => 'White', 'light' => 'Light Grey', 'tint' => 'Green Tint', 'dark' => 'Dark']],
            'items'     => [
                'type'  => 'repeater',
                'label' => 'Testimonials',
                'max'   => 4,
                'item_fields' => [
                    'quote'  => ['type' => 'textarea', 'label' => 'Quote',       'required' => true],
                    'name'   => ['type' => 'text',     'label' => 'Client Name', 'required' => true],
                    'title'  => ['type' => 'text',     'label' => 'Title / City','placeholder' => 'Homeowner, Vancouver'],
                    'rating' => ['type' => 'select',   'label' => 'Star Rating',
                        'options' => ['5' => '★★★★★', '4' => '★★★★', '3' => '★★★']],
                ],
            ],
        ],
        'defaults' => [
            'headline' => 'What Our Clients Say', 'background' => 'light',
            'items' => [
                ['quote' => '', 'name' => '', 'title' => '', 'rating' => '5'],
            ],
        ],
    ],

];
