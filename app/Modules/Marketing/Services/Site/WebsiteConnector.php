<?php
/**
 * WebsiteConnector — the one way Mia changes a website (Phase 2.1, docs/mia/MIA-ARCHITECTURE.md item 7).
 *
 * A connector knows how to find the page a URL belongs to, change the few fields Mia is allowed to change, and put
 * them back. It never decides WHETHER to change anything: MiaPublishService checks the owner's approval first.
 * Global namespace, no autoloader: require_once this file before implementing it.
 */
interface WebsiteConnector
{
    /** Short stable name stored with every execution: 'cms', later 'wordpress'. */
    public function name(): string;

    /** What this connector can do, e.g. ['seo_meta' => true, 'rollback' => true, 'body' => false]. */
    public function capabilities(): array;

    /**
     * Find the page for a public URL on this host.
     * @return array{ok:bool, ref?:string, current?:array{meta_title:?string,meta_description:?string}, code?:string, reason?:string}
     *         ok=false carries a plain-English reason. code 'manual' means the page is built from a code file Mia cannot edit.
     */
    public function locate(string $pageUrl): array;

    /**
     * Change the page's title tag and description, only if it still holds $expected (compare-and-set).
     * @param array{meta_title:?string,meta_description:?string} $expected values the page must hold right now
     * @param array{meta_title:?string,meta_description:?string} $new      values to write
     * @return array{ok:bool, error?:string}
     */
    public function applySeoMeta(string $ref, array $expected, array $new, int $userId): array;

    /** Called after the change is saved, outside any transaction (clear page caches). Must not throw. */
    public function afterChange(string $ref): void;
}
