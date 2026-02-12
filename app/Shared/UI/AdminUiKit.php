<?php
/**
 * Admin UI Kit - Reusable Component Library
 *
 * Provides a set of reusable PHP components for building consistent admin pages.
 * Built on AppStack + Bootstrap 4, adds minimal custom CSS.
 *
 * Usage:
 *   require 'admin-ui-kit.php';
 *   echo admin_table($data, $columns, $options);
 *   echo admin_filter($filters);
 *   echo admin_badge('Published', 'success');
 *
 * @package Mowology CRM
 * @subpackage Admin UI Kit
 *
 * Migrated from: public/crm/includes/admin-ui-kit.php
 */

declare(strict_types=1);

// ============================================================================
// TABLE COMPONENT
// ============================================================================

/**
 * Render a data table with sorting, row selection, and actions
 *
 * @param array $rows Array of data rows
 * @param array $columns Column definitions: ['field' => ['label' => '...', 'sortable' => true, 'align' => 'left']]
 * @param array $options Table options: ['row_actions' => [...], 'empty_text' => '...']
 * @return string HTML table markup
 */
function admin_table(array $rows, array $columns, array $options = []): string
{
    $options = array_merge([
        'row_actions' => [],
        'batch_actions' => [],
        'empty_text' => 'No records found',
        'striped' => true,
        'hover' => true,
    ], $options);

    if (empty($rows)) {
        return sprintf(
            '<div class="alert alert-info text-center py-5"><p>%s</p></div>',
            h($options['empty_text'])
        );
    }

    $html = '<table class="table' . ($options['striped'] ? ' table-striped' : '') . ($options['hover'] ? ' table-hover' : '') . '">';
    $html .= '<thead><tr>';

    // Header with select checkbox if has row actions
    if (!empty($options['row_actions'])) {
        $html .= '<th style="width: 40px;"><input type="checkbox" class="aui-select-all" title="Select all"></th>';
    }

    // Column headers
    foreach ($columns as $field => $col) {
        $label = $col['label'] ?? $field;
        $width = isset($col['width']) ? 'style="width: ' . h($col['width']) . ';"' : '';
        $align = isset($col['align']) ? ' text-' . h($col['align']) : '';

        if ($col['sortable'] ?? false) {
            $html .= sprintf('<th %s class="%s"><a href="#" class="aui-sortable" data-field="%s">%s</a></th>', $width, $align, h($field), h($label));
        } else {
            $html .= sprintf('<th %s class="%s">%s</th>', $width, $align, h($label));
        }
    }

    // Actions header
    if (!empty($options['row_actions'])) {
        $html .= '<th style="width: 150px; text-align: center;">Actions</th>';
    }

    $html .= '</tr></thead><tbody>';

    // Rows
    foreach ($rows as $row) {
        $html .= '<tr data-id="' . h($row['id'] ?? '') . '">';

        // Select checkbox
        if (!empty($options['row_actions'])) {
            $html .= sprintf('<td><input type="checkbox" class="aui-row-select" data-id="%s"></td>', h($row['id'] ?? ''));
        }

        // Data cells
        foreach ($columns as $field => $col) {
            $value = $row[$field] ?? '';
            $align = isset($col['align']) ? ' text-' . h($col['align']) : '';

            // Handle badge columns
            if ($col['badge'] ?? false) {
                $variant = $col['badge_variant'] ?? 'secondary';
                if (is_array($col['badge_variant'] ?? null)) {
                    // Map values to variants
                    $variants = $col['badge_variant'];
                    $variant = $variants[$value] ?? 'secondary';
                }
                $value = sprintf('<span class="badge badge-%s">%s</span>', h($variant), h($value));
            } elseif ($col['format'] ?? null) {
                // Custom format function
                $format = $col['format'];
                if (is_callable($format)) {
                    $value = call_user_func($format, $value, $row);
                }
            } else {
                $value = h($value);
            }

            $html .= sprintf('<td class="%s">%s</td>', $align, $value);
        }

        // Row actions
        if (!empty($options['row_actions'])) {
            $html .= '<td class="aui-row-actions text-center">';

            foreach ($options['row_actions'] as $action) {
                $url = $action['href'] ?? '';
                $label = $action['label'] ?? '';
                $icon = $action['icon'] ?? '';
                $target = $action['target'] ?? '_self';
                $confirm = $action['confirm'] ?? false;

                // Replace {{id}} with actual ID
                if (isset($row['id'])) {
                    $url = str_replace('{{id}}', (string)$row['id'], $url);
                    foreach ($row as $key => $val) {
                        $url = str_replace('{{' . $key . '}}', urlencode((string)$val), $url);
                    }
                }

                if (isset($action['action'])) {
                    // AJAX action button
                    $confirm_attr = $confirm ? ' data-confirm="true"' : '';
                    $html .= sprintf(
                        '<button class="btn btn-sm btn-link aui-action" data-action="%s"%s title="%s">',
                        h($action['action']),
                        $confirm_attr,
                        h($label)
                    );
                } else {
                    // Link
                    $html .= sprintf(
                        '<a href="%s" class="btn btn-sm btn-link" target="%s" title="%s">',
                        h($url),
                        h($target),
                        h($label)
                    );
                }

                if ($icon) {
                    $html .= sprintf('<i data-feather="%s"></i>', h($icon));
                } else {
                    $html .= h($label);
                }

                if (isset($action['action'])) {
                    $html .= '</button>';
                } else {
                    $html .= '</a>';
                }
            }

            $html .= '</td>';
        }

        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    // Batch actions (if applicable)
    if (!empty($options['batch_actions']) && !empty($options['row_actions'])) {
        $html .= '<div class="aui-batch-actions mt-3 hidden">';
        foreach ($options['batch_actions'] as $action) {
            $html .= sprintf(
                '<button class="btn btn-sm btn-%s" data-batch-action="%s">%s</button>',
                h($action['class'] ?? 'secondary'),
                h($action['action'] ?? ''),
                h($action['label'] ?? '')
            );
        }
        $html .= '</div>';
    }

    return $html;
}

// ============================================================================
// FILTER COMPONENT
// ============================================================================

/**
 * Render filter controls (search, dropdowns, date ranges)
 *
 * @param array $filters Filter field definitions
 * @param array $active Active filter values
 * @return string HTML form markup
 */
function admin_filter(array $filters, array $active = []): string
{
    $html = '<div class="aui-filter-bar card card-body mb-4">';
    $html .= '<form method="GET" class="form-inline" data-ui-filter>';

    foreach ($filters as $name => $filter) {
        $type = $filter['type'] ?? 'text';
        $label = $filter['label'] ?? '';
        $value = $active[$name] ?? '';

        $html .= '<div class="form-group mr-3 mb-2">';

        if ($label) {
            $html .= sprintf('<label class="mr-2">%s</label>', h($label));
        }

        switch ($type) {
            case 'text':
                $html .= sprintf(
                    '<input type="text" name="%s" class="form-control form-control-sm" placeholder="%s" value="%s">',
                    h($name),
                    h($filter['placeholder'] ?? ''),
                    h($value)
                );
                break;

            case 'select':
                $html .= sprintf('<select name="%s" class="form-control form-control-sm">', h($name));
                $html .= '<option value="">All</option>';
                foreach ($filter['options'] ?? [] as $optVal => $optLabel) {
                    $selected = ($value === (string)$optVal) ? ' selected' : '';
                    $html .= sprintf('<option value="%s"%s>%s</option>', h($optVal), $selected, h($optLabel));
                }
                $html .= '</select>';
                break;

            case 'date':
                $html .= sprintf(
                    '<input type="date" name="%s" class="form-control form-control-sm" value="%s">',
                    h($name),
                    h($value)
                );
                break;
        }

        $html .= '</div>';
    }

    $html .= '<button type="submit" class="btn btn-sm btn-primary mr-2">Filter</button>';
    $html .= '<a href="?" class="btn btn-sm btn-secondary" data-filter-clear>Clear</a>';
    $html .= '</form></div>';

    return $html;
}

// ============================================================================
// BADGE COMPONENT
// ============================================================================

/**
 * Render a status badge
 *
 * @param string $text Badge text
 * @param string $variant Badge variant (success, danger, warning, info, secondary, primary)
 * @param string|null $icon Optional Feather icon
 * @return string HTML badge markup
 */
function admin_badge(string $text, string $variant = 'secondary', ?string $icon = null): string
{
    $html = sprintf('<span class="badge badge-%s">', h($variant));

    if ($icon) {
        $html .= sprintf('<i data-feather="%s" style="width: 1em; height: 1em; margin-right: 0.25rem;"></i> ', h($icon));
    }

    $html .= h($text) . '</span>';

    return $html;
}

// ============================================================================
// CARD COMPONENT
// ============================================================================

/**
 * Render a card container
 *
 * @param string $title Card title
 * @param string $content Card body content (HTML)
 * @param string|null $footer Optional footer content
 * @param array $options Card options
 * @return string HTML card markup
 */
function admin_card(string $title, string $content, ?string $footer = null, array $options = []): string
{
    $html = '<div class="card">';

    if ($title) {
        $html .= sprintf('<div class="card-header"><h5 class="mb-0">%s</h5></div>', h($title));
    }

    $html .= sprintf('<div class="card-body">%s</div>', $content);

    if ($footer) {
        $html .= sprintf('<div class="card-footer">%s</div>', $footer);
    }

    $html .= '</div>';

    return $html;
}

// ============================================================================
// ALERT COMPONENT
// ============================================================================

/**
 * Render an alert message
 *
 * @param string $message Alert message
 * @param string $type Alert type (success, danger, warning, info)
 * @param bool $dismissible Whether alert is dismissible
 * @return string HTML alert markup
 */
function admin_alert(string $message, string $type = 'info', bool $dismissible = true): string
{
    $class = 'alert alert-' . h($type);
    if ($dismissible) {
        $class .= ' alert-dismissible fade show';
    }

    $html = sprintf('<div class="%s" role="alert">%s', $class, h($message));

    if ($dismissible) {
        $html .= '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
        $html .= '<span aria-hidden="true">&times;</span></button>';
    }

    $html .= '</div>';

    return $html;
}

// ============================================================================
// EMPTY STATE COMPONENT
// ============================================================================

/**
 * Render empty state (when no data)
 *
 * @param string $title Empty state title
 * @param string $description Description
 * @param array|null $cta Optional call-to-action button
 * @return string HTML empty state markup
 */
function admin_empty_state(string $title, string $description = '', ?array $cta = null): string
{
    $html = '<div class="aui-empty-state text-center py-5">';
    $html .= '<div style="font-size: 3rem; color: #ddd; margin-bottom: 1rem;">';
    $html .= '<i data-feather="inbox"></i></div>';
    $html .= sprintf('<h5>%s</h5>', h($title));

    if ($description) {
        $html .= sprintf('<p class="text-muted">%s</p>', h($description));
    }

    if ($cta) {
        $html .= sprintf(
            '<a href="%s" class="btn btn-primary">%s</a>',
            h($cta['url'] ?? '#'),
            h($cta['label'] ?? 'Create')
        );
    }

    $html .= '</div>';

    return $html;
}

// ============================================================================
// BREADCRUMB COMPONENT
// ============================================================================

/**
 * Render breadcrumb navigation
 *
 * @param array $items Breadcrumb items: [['label' => '...', 'url' => '...'], ...]
 * @return string HTML breadcrumb markup
 */
function admin_breadcrumbs(array $items): string
{
    $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';

    foreach ($items as $index => $item) {
        $isActive = ($index === count($items) - 1);

        if ($isActive) {
            $html .= sprintf('<li class="breadcrumb-item active">%s</li>', h($item['label'] ?? ''));
        } else {
            $html .= sprintf(
                '<li class="breadcrumb-item"><a href="%s">%s</a></li>',
                h($item['url'] ?? '#'),
                h($item['label'] ?? '')
            );
        }
    }

    $html .= '</ol></nav>';

    return $html;
}

// ============================================================================
// STAT CARDS COMPONENT
// ============================================================================

/**
 * Render stat cards (dashboard stats)
 *
 * @param array $stats Array of stat items
 * @return string HTML stat cards markup
 */
function admin_stats(array $stats): string
{
    $html = '<div class="row">';

    foreach ($stats as $stat) {
        $value = $stat['value'] ?? 0;
        $label = $stat['label'] ?? '';
        $color = $stat['color'] ?? 'primary';
        $icon = $stat['icon'] ?? '';

        $html .= '<div class="col-md-3 mb-4">';
        $html .= sprintf('<div class="card border-left border-%s">', h($color));
        $html .= '<div class="card-body">';
        $html .= sprintf('<div style="font-size: 2rem; font-weight: bold; color: %s;">%s</div>',
            $this->getColorValue($color),
            h((string)$value)
        );
        $html .= sprintf('<small class="text-muted">%s</small>', h($label));
        $html .= '</div></div></div>';
    }

    $html .= '</div>';

    return $html;
}

// Helper function
function getColorValue(string $color): string
{
    $colors = [
        'primary' => '#007bff',
        'success' => '#28a745',
        'danger' => '#dc3545',
        'warning' => '#ffc107',
        'info' => '#17a2b8',
    ];
    return $colors[$color] ?? '#6c757d';
}

// ============================================================================
// MODAL COMPONENT
// ============================================================================

/**
 * Render a modal dialog
 *
 * @param string $id Modal ID
 * @param string $title Modal title
 * @param string $content Modal body content
 * @param array|null $footer Modal footer buttons
 * @return string HTML modal markup
 */
function admin_modal(string $id, string $title, string $content, ?array $footer = null): string
{
    $html = sprintf('<div class="modal fade" id="%s" tabindex="-1" role="dialog" aria-hidden="true">', h($id));
    $html .= '<div class="modal-dialog" role="document"><div class="modal-content">';

    // Header
    $html .= '<div class="modal-header">';
    $html .= sprintf('<h5 class="modal-title">%s</h5>', h($title));
    $html .= '<button type="button" class="close" data-dismiss="modal" aria-label="Close">';
    $html .= '<span aria-hidden="true">&times;</span></button></div>';

    // Body
    $html .= sprintf('<div class="modal-body">%s</div>', $content);

    // Footer
    if ($footer) {
        $html .= '<div class="modal-footer">';
        foreach ($footer as $button) {
            $class = $button['class'] ?? 'btn-secondary';
            $label = $button['label'] ?? '';
            $dismiss = isset($button['dismiss']) && $button['dismiss'] ? ' data-dismiss="modal"' : '';

            $html .= sprintf(
                '<button type="button" class="btn %s"%s>%s</button>',
                h($class),
                $dismiss,
                h($label)
            );
        }
        $html .= '</div>';
    }

    $html .= '</div></div></div>';

    return $html;
}

// ============================================================================
// PAGINATION COMPONENT
// ============================================================================

/**
 * Render pagination controls
 *
 * @param int $current Current page number
 * @param int $total Total pages
 * @param string $baseUrl Base URL for pagination links
 * @return string HTML pagination markup
 */
function admin_pagination(int $current, int $total, string $baseUrl): string
{
    if ($total <= 1) {
        return '';
    }

    $html = '<nav aria-label="Page navigation"><ul class="pagination">';

    // Previous button
    if ($current > 1) {
        $html .= sprintf('<li class="page-item"><a class="page-link" href="%s&page=%d">Previous</a></li>',
            h($baseUrl), $current - 1);
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }

    // Page numbers
    $start = max(1, $current - 2);
    $end = min($total, $current + 2);

    if ($start > 1) {
        $html .= sprintf('<li class="page-item"><a class="page-link" href="%s&page=1">1</a></li>', h($baseUrl));
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = ($i === $current) ? ' active' : '';
        $html .= sprintf('<li class="page-item%s"><a class="page-link" href="%s&page=%d">%d</a></li>',
            $active, h($baseUrl), $i, $i);
    }

    if ($end < $total) {
        if ($end < $total - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= sprintf('<li class="page-item"><a class="page-link" href="%s&page=%d">%d</a></li>',
            h($baseUrl), $total, $total);
    }

    // Next button
    if ($current < $total) {
        $html .= sprintf('<li class="page-item"><a class="page-link" href="%s&page=%d">Next</a></li>',
            h($baseUrl), $current + 1);
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }

    $html .= '</ul></nav>';

    return $html;
}
