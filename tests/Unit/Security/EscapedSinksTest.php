<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * H6, H7, M6, M7, M8 (Fable audit 2026-11): user-entered text reached inline handlers or innerHTML
 * unescaped. These are guards on the page sources: the old shapes must not come back, and the
 * escaped shape must be present. (The pages are not executed here; the browser check is in the
 * deploy checklist.)
 */
class EscapedSinksTest extends TestCase
{
    private function src(string $rel): string
    {
        $s = file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        $this->assertNotFalse($s, $rel . ' must be readable');
        return (string)$s;
    }

    /** H6: property Edit / Remove buttons passed addslashes(h()) inside onclick, which HTML decodes first. */
    public function test_clients_property_handlers_read_values_from_data_attributes(): void
    {
        $s = $this->src('public/crm/clients_appstack.php');
        $this->assertDoesNotMatchRegularExpression(
            '/onclick="(showEditProperty|showUnlinkProperty|showUnlinkCompanyProperty)\([^"]*addslashes/',
            $s,
            'property handlers must not splice addslashes(h()) values into onclick'
        );
        $this->assertStringContainsString('this.dataset.address', $s);
        $this->assertStringContainsString('this.dataset.propName', $s);
    }

    /** H6/M7: vendor and catalogue names went into innerHTML and into onclick JS strings unescaped. */
    public function test_tasks_vendor_and_catalogue_names_are_escaped(): void
    {
        $s = $this->src('public/crm/tasks_appstack.php');
        $this->assertStringNotContainsString("'<strong>' + v.name", $s);
        $this->assertStringNotContainsString('v.name.replace(', $s);
        $this->assertStringNotContainsString("'<span>' + p.name", $s);
        $this->assertStringNotContainsString('p.name.replace(', $s);
        $this->assertStringContainsString('mwEscHtml(v.name)', $s);
        $this->assertStringContainsString('mwEscHtml(p.name)', $s);
    }

    /** H7: the purchase-task InfoWindow spliced vendor, location and assignee names into HTML. */
    public function test_day_map_purchase_info_window_escapes_task_text(): void
    {
        $s = $this->src('public/crm/js/schedule-day-map.js');
        $this->assertStringNotContainsString("'<strong>' + (task.vendor_name || '')", $s);
        $this->assertStringNotContainsString("'<br><span style=\"color:#666\">' + task.location_label", $s);
        $this->assertStringNotContainsString('👤 \' + task.assigned_to_name', $s);
        $this->assertStringContainsString('infoEsc(task.vendor_name', $s);
        $this->assertStringContainsString('infoEsc(task.location_label)', $s);
    }

    /** M6: route-conflict fields (visit number, address, message) reached innerHTML unescaped. */
    public function test_timesheet_route_conflicts_are_escaped(): void
    {
        $s = $this->src('public/crm/timeclock/timesheet-detail.php');
        $this->assertStringNotContainsString("'<strong>' + (c.visit_number || '')", $s);
        $this->assertStringNotContainsString("'<div>' + (c.message || '')", $s);
        $this->assertStringContainsString('reconEsc(c.visit_number', $s);
        $this->assertStringContainsString('reconEsc(c.message', $s);
    }

    /** M8: the vendor delete button built an onclick JS string from an HTML-escaped name. */
    public function test_expenses_vendor_delete_passes_name_through_data_attribute(): void
    {
        $s = $this->src('public/crm/expenses_appstack.php');
        $this->assertStringNotContainsString("esc(v.name).replace(/'/g", $s);
        $this->assertStringContainsString('this.dataset.vendorName', $s);
    }
}
