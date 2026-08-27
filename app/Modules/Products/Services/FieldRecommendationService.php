<?php
declare(strict_types=1);

/**
 * FieldRecommendationService
 *
 * Crew spot sellable work while on site — a property needs a cleanup, a hedge is
 * overgrown — and that observation used to die in someone's head. This turns it
 * into a priced Quote the client can accept from their portal.
 *
 * Flow:
 *   1. Crew photographs the work and taps a service chip on the job card.
 *   2. create() records a field_observations row and links every photo.
 *   3. Fixed-price packages (products.field_auto_send) go straight out:
 *      buildQuote() + send(). Anything else waits in the admin review queue at
 *      /crm/products/recommendations.php.
 *
 * Deliberately builds a real Quote rather than a bespoke record, so the customer
 * portal, signature acceptance, decline, PDF and follow-up nagging all come free
 * from QuoteService.
 *
 * No namespace and no autoloader in production — callers require_once this file
 * and `new FieldRecommendationService($db)`.
 */
class FieldRecommendationService
{
    /** Refuse a second open recommendation for the same property+product inside this window. */
    public const DUPLICATE_WINDOW_DAYS = 30;

    /** Statuses that still count as "open" for duplicate suppression. */
    public const OPEN_STATUSES = ['pending', 'approved', 'email_sent', 'quote_created'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Catalogue
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The services the office has published to the field, in chip order.
     * Drives both the iOS chips and the web modal.
     */
    public function getFieldOptions(): array
    {
        // Migration 1114 adds the products.field_* columns. Return nothing
        // rather than erroring if the code has shipped ahead of the migration —
        // crew see "no services published" instead of a broken job card.
        if (!$this->hasFieldColumns()) {
            return [];
        }

        $sql = "
            SELECT p.id, p.name, p.field_label, p.base_price, p.description,
                   p.field_auto_send, p.taxable, p.gst_rate, p.unit_type_id,
                   r.pricing_model
            FROM products p
            LEFT JOIN product_pricing_rules r
                   ON r.product_id = p.id AND r.is_active = 1
            WHERE p.field_recommendable = 1
              AND p.active = 1
              AND p.is_archived = 0
            GROUP BY p.id
            ORDER BY p.field_sort_order ASC, p.name ASC
        ";

        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'product_id'   => (int)$row['id'],
                'label'        => self::resolveLabel($row),
                'description'  => (string)($row['description'] ?? ''),
                'price'        => (float)$row['base_price'],
                'auto_send'    => self::isAutoSendEligible($row, $row['pricing_model'] ?? null),
                'fixed_price'  => self::isFixedPrice($row['pricing_model'] ?? null),
            ];
        }

        return $options;
    }

    /** Has migration 1114 been run? Cached for the life of the request. */
    private function hasFieldColumns(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $has = $this->db->query("SHOW COLUMNS FROM products LIKE 'field_recommendable'")->rowCount() > 0;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /** Chip text: the short field label if the office set one, else the catalogue name. */
    public static function resolveLabel(array $product): string
    {
        $label = trim((string)($product['field_label'] ?? ''));
        return $label !== '' ? $label : trim((string)($product['name'] ?? 'Service'));
    }

    /**
     * A price is "fixed" when it does not depend on measuring the property.
     * No pricing rule at all means the product bills at its flat base_price.
     */
    public static function isFixedPrice(?string $pricingModel): bool
    {
        return $pricingModel === null || $pricingModel === '' || $pricingModel === 'flat';
    }

    /**
     * Auto-send is deliberately narrow: the office must have flagged the product,
     * the price must not depend on measurements, and there must be a real price.
     * Fails closed — anything uncertain routes to the review queue instead.
     */
    public static function isAutoSendEligible(array $product, ?string $pricingModel): bool
    {
        if ((int)($product['field_auto_send'] ?? 0) !== 1) {
            return false;
        }
        if (!self::isFixedPrice($pricingModel)) {
            return false;
        }
        return (float)($product['base_price'] ?? 0) > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Capture
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record a crew recommendation.
     *
     * @param array $input visit_id, product_id, note, media_ids[],
     *                     optional property_id/contact_id/observation_type
     * @return array {observation_id, status, duplicate, quote_id, auto_sent, message}
     */
    public function create(int $userId, array $input): array
    {
        $visitId   = isset($input['visit_id']) ? (int)$input['visit_id'] : 0;
        $productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
        $note      = trim((string)($input['note'] ?? $input['notes'] ?? ''));
        $mediaIds  = $this->normaliseMediaIds($input['media_ids'] ?? []);

        if ($productId <= 0) {
            throw new InvalidArgumentException('A service must be selected');
        }

        $product = $this->loadFieldProduct($productId);
        if (!$product) {
            throw new InvalidArgumentException('That service is not available for field recommendations');
        }

        [$propertyId, $contactId] = $this->resolveTarget($visitId, $input);

        // Duplicate suppression — crew re-snap the same cleanup every visit
        // otherwise, and the client gets the same quote over and over.
        $existing = $this->findRecentDuplicate($propertyId, $productId);
        if ($existing) {
            return [
                'observation_id' => (int)$existing['id'],
                'status'         => (string)$existing['status'],
                'duplicate'      => true,
                'quote_id'       => $existing['quote_id'] !== null ? (int)$existing['quote_id'] : null,
                'auto_sent'      => (bool)$existing['auto_sent'],
                'message'        => 'Already recommended for this property in the last '
                                    . self::DUPLICATE_WINDOW_DAYS . ' days',
            ];
        }

        $autoSend = self::isAutoSendEligible($product, $product['pricing_model'] ?? null);
        $price    = (float)$product['base_price'];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO field_observations (
                    visit_id, property_id, contact_id, observation_type, observation_value,
                    notes, photo_media_id, recommended_product_id, status, auto_send,
                    recommended_price, source, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, 'service', ?, NOW(), NOW())
            ");
            $stmt->execute([
                $visitId ?: null,
                $propertyId,
                $contactId,
                trim((string)($input['observation_type'] ?? 'other')),
                self::resolveLabel($product),
                $note,
                $mediaIds[0] ?? null,          // cover image — keeps the 605 templates working
                $productId,
                $autoSend ? 1 : 0,
                $price > 0 ? $price : null,
                $userId,
            ]);

            $obsId = (int)$this->db->lastInsertId();
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Link every photo to the observation. media_links already understands
        // context_type='field_observation', so no new table is needed. Photos stay
        // linked to the visit too, which is what the visit record wants anyway.
        $this->linkPhotos($obsId, $mediaIds, $userId);

        $result = [
            'observation_id' => $obsId,
            'status'         => 'pending',
            'duplicate'      => false,
            'quote_id'       => null,
            'auto_sent'      => false,
            'message'        => 'Sent to the office for review',
        ];

        if (!$autoSend) {
            return $result;
        }

        // Fixed-price package — quote and email the client immediately. A failure
        // here must not lose the observation: it simply stays pending for review.
        try {
            $quoteId = $this->buildQuote($obsId, $userId);
            $sent    = $this->send($obsId, $userId);

            $result['quote_id']  = $quoteId;
            $result['auto_sent'] = (bool)$sent['success'];
            $result['status']    = $sent['success'] ? 'email_sent' : 'quote_created';
            $result['message']   = $sent['success']
                ? 'Quote sent to the client'
                : 'Quote created — office will send it';
        } catch (Throwable $e) {
            error_log('[FieldRecommendationService] auto-send failed for observation '
                      . $obsId . ': ' . $e->getMessage());
            $result['message'] = 'Saved — office will review';
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Quote generation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Turn an observation into a draft Quote. Reuses QuoteService::create() so
     * quote numbering, access tokens and property/company resolution stay in one
     * place, and QuoteCalculator so a per-sqft service prices off the property's
     * real measurements instead of a flat guess.
     */
    public function buildQuote(int $obsId, int $userId, ?float $priceOverride = null): int
    {
        $obs = $this->getObservation($obsId);
        if (!$obs) {
            throw new RuntimeException('Recommendation not found');
        }
        if (!empty($obs['quote_id'])) {
            return (int)$obs['quote_id'];   // idempotent — never double-quote
        }
        if (empty($obs['recommended_product_id'])) {
            throw new RuntimeException('Recommendation has no service attached');
        }

        $product = $this->loadProduct((int)$obs['recommended_product_id']);
        if (!$product) {
            throw new RuntimeException('Service no longer exists in the catalogue');
        }

        $lineItem = $this->buildLineItem($product, (int)$obs['property_id'], $priceOverride);

        require_once APP_ROOT . '/Modules/Quotes/Services/QuoteService.php';
        $quotes = new QuoteService($this->db);

        $label       = self::resolveLabel($product);
        $crewNote    = trim((string)($obs['notes'] ?? ''));
        $description = $crewNote !== ''
            ? "Noted by our crew on site: " . $crewNote
            : "Our crew noticed this needs attention while on site.";

        $quoteId = $quotes->create([
            'property_id'    => (int)$obs['property_id'],
            'title'          => $label,
            'service_type'   => 'landscaping',
            'valid_until'    => null,
            'description'    => $description,
            'notes_customer' => $description,
            'notes_internal' => 'Generated from crew recommendation #' . $obsId,
            'terms'          => '',
        ], [$lineItem], $userId);

        // QuoteService::calculateTotals() delegates to the legacy
        // calculateQuoteTotals() when that happens to be loaded, and falls back to
        // its own implementation otherwise — and the two disagree about whether
        // tax_rate is a fraction or a percentage. quotes.tax_rate is DECIMAL(5,4),
        // i.e. a fraction, so restate the totals explicitly rather than depending
        // on which helper won.
        $subtotal  = round((float)$lineItem['line_total'], 2);
        $taxRate   = round((float)($lineItem['tax_rate'] ?? 0) / 100, 4);
        $taxAmount = round($subtotal * $taxRate, 2);
        $total     = round($subtotal + $taxAmount, 2);

        $this->db->prepare("
            UPDATE quotes
            SET subtotal = ?, tax_rate = ?, tax_amount = ?, amount = ?, total_amount = ?
            WHERE id = ?
        ")->execute([$subtotal, $taxRate, $taxAmount, $total, $total, $quoteId]);

        $this->db->prepare("
            UPDATE field_observations
            SET quote_id = ?, recommended_price = ?, status = 'quote_created', updated_at = NOW()
            WHERE id = ?
        ")->execute([$quoteId, $subtotal, $obsId]);

        return $quoteId;
    }

    /**
     * One quote line for the recommended service. Flat-priced products bill at
     * base_price; measurement-driven ones go through QuoteCalculator.
     */
    private function buildLineItem(array $product, int $propertyId, ?float $priceOverride = null): array
    {
        $taxRate = (int)($product['taxable'] ?? 1) === 1
            ? (float)($product['gst_rate'] ?? 5.00)
            : 0.0;

        $rule = $this->loadPricingRule((int)$product['id']);

        if ($priceOverride === null && $rule && !self::isFixedPrice($rule['pricing_model'] ?? null)) {
            require_once APP_ROOT . '/Services/QuoteCalculator.php';

            $totals     = getMeasurementTotalsForProperty($propertyId);
            $groupKey   = (string)($rule['group_key'] ?? '');
            $totalUnits = 0.0;

            if (isset($totals[$groupKey])) {
                $totalUnits = $groupKey === 'hedge_linear'
                    ? (float)($totals[$groupKey]['linear_ft'] ?? 0)
                    : (float)($totals[$groupKey]['sqft'] ?? 0);
            }

            // Only trust the calculator when the property has actually been
            // measured; otherwise fall through to base_price so the office sees a
            // sane number to correct rather than a zero.
            if ($totalUnits > 0) {
                $item = calculateLineItemFromRule($rule, $totalUnits, $product);
                $item['tax_rate'] = $taxRate;
                return $item;
            }
        }

        $price = $priceOverride !== null ? $priceOverride : (float)$product['base_price'];

        return [
            'product_id'   => (int)$product['id'],
            'service_type' => self::resolveLabel($product),
            'description'  => (string)($product['description'] ?? ''),
            'quantity'     => 1,
            'unit_type'    => 'each',
            'unit_price'   => $price,
            'line_total'   => $price,
            'tax_rate'     => $taxRate,
            'is_optional'  => 0,
            'is_upsell'    => 0,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sending
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Email the client their quote, with the crew's photos, and a portal link to
     * accept it. Recipient resolution is delegated entirely to QuoteService so
     * PM-managed and strata properties route to whoever authorises spend —
     * consistent with how invoices already behave.
     */
    public function send(int $obsId, int $userId): array
    {
        $obs = $this->getObservation($obsId);
        if (!$obs) {
            throw new RuntimeException('Recommendation not found');
        }

        $quoteId = (int)($obs['quote_id'] ?? 0);
        if ($quoteId <= 0) {
            $quoteId = $this->buildQuote($obsId, $userId);
        }

        require_once APP_ROOT . '/Modules/Quotes/Services/QuoteService.php';
        require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
        require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

        $quotes = new QuoteService($this->db);
        $quote  = $quotes->getWithContact($quoteId);
        if (!$quote) {
            throw new RuntimeException('Quote not found');
        }

        $contact = $quotes->resolveContact($quote);
        $email   = $contact['email'] ?? null;

        if (!$email) {
            return [
                'success' => false,
                'error'   => 'No email address on file for this property',
            ];
        }

        $token    = $quotes->ensureAccessToken($quoteId, $quote);
        $quoteUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'mowology.ca')
                  . '/customer/quote.php?token=' . urlencode($token);

        $company   = EmailWrapper::getCompanyInfo();
        $firstName = $contact['first_name'] ?: ($quotes->resolveDisplayName($quote) ?: 'there');
        $title     = (string)$quote['title'];
        $amount    = number_format((float)$quote['amount'], 2);
        $crewNote  = trim((string)($obs['notes'] ?? ''));

        $body  = '<p>Hi ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ',</p>';
        $body .= '<p>While our crew were on site we noticed something worth flagging: <strong>'
               . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
        if ($crewNote !== '') {
            $body .= '<p>' . htmlspecialchars($crewNote, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $body .= $this->photoHtml($obsId);
        $body .= '<p>We have put together a quote for you at <strong>$' . $amount
               . '</strong>. There is no obligation — you can review, accept or decline it online.</p>';

        $html = EmailWrapper::wrap($body, 'View & Accept Quote', $quoteUrl, $company);

        $result = sendEmail(
            $email,
            'A recommendation from your Mowology crew — ' . $title,
            $html,
            null,
            'Mowology Landscaping'
        );

        if (!empty($result['success'])) {
            $quotes->markSent($quoteId, 'email');
            $this->db->prepare("
                UPDATE field_observations
                SET status = 'email_sent', email_sent_at = NOW(), auto_sent = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([(int)($obs['auto_send'] ?? 0), $obsId]);
        }

        return [
            'success'  => (bool)($result['success'] ?? false),
            'quote_id' => $quoteId,
            'email'    => $email,
            'error'    => $result['error'] ?? null,
        ];
    }

    /** Inline thumbnails of what the crew photographed. */
    private function photoHtml(int $obsId): string
    {
        $photos = $this->getPhotos($obsId);
        if (!$photos) {
            return '';
        }

        $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'mowology.ca');
        $html = '<p>';
        foreach (array_slice($photos, 0, 4) as $photo) {
            $path = $photo['thumb_url'] ?: $photo['file_path'];
            if (!$path) {
                continue;
            }
            $html .= '<img src="' . htmlspecialchars($base . $path, ENT_QUOTES, 'UTF-8')
                   . '" alt="Site photo" width="260" '
                   . 'style="max-width:260px;border-radius:8px;margin:0 8px 8px 0;" />';
        }
        return $html . '</p>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Review queue
    // ─────────────────────────────────────────────────────────────────────────

    public function dismiss(int $obsId, string $reason): bool
    {
        return $this->db->prepare("
            UPDATE field_observations
            SET status = 'dismissed', dismissed_reason = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$reason !== '' ? $reason : null, $obsId]);
    }

    /** Every photo linked to a recommendation, cover image first. */
    public function getPhotos(int $obsId): array
    {
        $stmt = $this->db->prepare("
            SELECT ma.id, ma.file_path, mv.file_path AS thumb_url
            FROM media_links ml
            JOIN media_assets ma ON ma.id = ml.media_id AND ma.status <> 'deleted'
            LEFT JOIN media_variants mv
                   ON mv.media_id = ma.id AND mv.variant_type = 'thumb_square' AND mv.format = 'jpeg'
            WHERE ml.context_type = 'field_observation' AND ml.context_id = ?
            ORDER BY ml.sort_order ASC, ma.id ASC
        ");
        $stmt->execute([$obsId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    public function getObservation(int $obsId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM field_observations WHERE id = ? LIMIT 1");
        $stmt->execute([$obsId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loadProduct(int $productId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** A product the crew is actually allowed to offer. */
    private function loadFieldProduct(int $productId): ?array
    {
        if (!$this->hasFieldColumns()) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT p.*, r.pricing_model
            FROM products p
            LEFT JOIN product_pricing_rules r
                   ON r.product_id = p.id AND r.is_active = 1
            WHERE p.id = ?
              AND p.field_recommendable = 1
              AND p.active = 1
              AND p.is_archived = 0
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loadPricingRule(int $productId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, mg.group_key, mg.group_label, mg.unit
            FROM product_pricing_rules r
            LEFT JOIN measurement_groups mg ON mg.id = r.measurement_group_id
            WHERE r.product_id = ? AND r.is_active = 1
            ORDER BY r.priority DESC, r.id ASC
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Resolve the property and contact, preferring the visit the crew are standing on. */
    private function resolveTarget(int $visitId, array $input): array
    {
        $propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
        $contactId  = isset($input['contact_id']) ? (int)$input['contact_id'] : 0;

        if ($visitId > 0) {
            $stmt = $this->db->prepare("
                SELECT jp.property_id, p.site_contact_id AS contact_id
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                JOIN properties p ON p.id = jp.property_id
                WHERE jv.id = ?
                LIMIT 1
            ");
            $stmt->execute([$visitId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $propertyId = (int)$row['property_id'];
                $contactId  = (int)$row['contact_id'];
            }
        }

        if ($propertyId <= 0) {
            throw new InvalidArgumentException('Could not work out which property this is for');
        }
        if ($contactId <= 0) {
            throw new InvalidArgumentException('This property has no contact on file');
        }

        return [$propertyId, $contactId];
    }

    private function findRecentDuplicate(int $propertyId, int $productId): ?array
    {
        $placeholders = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        $stmt = $this->db->prepare("
            SELECT id, status, quote_id, auto_sent
            FROM field_observations
            WHERE property_id = ?
              AND recommended_product_id = ?
              AND status IN ({$placeholders})
              AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::DUPLICATE_WINDOW_DAYS . " DAY)
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(array_merge([$propertyId, $productId], self::OPEN_STATUSES));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return int[] */
    private function normaliseMediaIds($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function linkPhotos(int $obsId, array $mediaIds, int $userId): void
    {
        if (!$mediaIds) {
            return;
        }
        require_once APP_ROOT . '/Services/Media/MediaUploadService.php';

        foreach ($mediaIds as $mediaId) {
            try {
                // client_visible, never marketing_eligible — reusing a client's
                // property photos for marketing needs their explicit consent.
                createMediaLink($mediaId, 'field_observation', $obsId, 'recommendation', 'client_visible', $userId);
            } catch (Throwable $e) {
                error_log('[FieldRecommendationService] could not link media ' . $mediaId
                          . ' to observation ' . $obsId . ': ' . $e->getMessage());
            }
        }
    }
}
