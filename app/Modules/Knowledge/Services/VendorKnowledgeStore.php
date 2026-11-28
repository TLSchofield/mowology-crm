<?php
/**
 * VendorKnowledgeStore — where the commons lives, from a tenant's point of view.
 *
 * LocalVendorKnowledgeStore  : this install IS the hub (Mowology) — tables in this DB.
 * RemoteVendorKnowledgeStore : another install — HTTPS to the hub's /api/knowledge/vendor.php,
 *                              per-tenant key, 24 h cache, fails soft (null / false).
 *
 * The shape returned by resolve():
 *   ['id' => int, 'display_name', 'name_key', 'gst_number', 'phone_digits', 'domain', 'kind',
 *    'status', 'tenant_count', 'facts' => [ fact => [ ['value' => mixed, 'status', 'tenants', 'confirmations'] ] ] ]
 * Only facts with status 'shared' are returned to remote tenants; the hub sees candidates too.
 */
declare(strict_types=1);

interface VendorKnowledgeStore
{
    /** @param array{gst_number?:?string,phone_digits?:?string,domain?:?string,name_key?:?string} $identity */
    public function resolve(array $identity): ?array;

    /**
     * Record what one tenant confirmed about a vendor. $lesson:
     *   ['identity' => [...as resolve()], 'display_name' => ?, 'facts' => [ ['fact' => 'sku', 'value' => [...]], ... ]]
     * Returns the commons vendor id (hub) or true (remote), false on failure. Never throws.
     */
    public function contribute(array $lesson, string $tenantKey);

    /**
     * Type-ahead over the commons: shared or curated vendors whose name contains $q, identity only
     * (id, display_name, kind, status). Never facts, never tenant data. Fails soft to [].
     * @return array<int, array{id:int, display_name:string, kind:?string, status:string}>
     */
    public function search(string $q, int $limit = 8): array;

    /** One shared/curated commons vendor by its commons id (shared facts only for a remote caller); null otherwise. */
    public function vendor(int $id): ?array;

    /** Is the commons reachable/usable right now? (tables exist / key configured) */
    public function ready(): bool;
}
