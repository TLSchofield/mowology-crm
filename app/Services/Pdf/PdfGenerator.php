<?php
/**
 * PdfGenerator — Creates branded PDF documents for quotes and invoices.
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';
 *   require_once APP_ROOT . '/Services/Pdf/PdfGenerator.php';
 *
 *   $gen = new PdfGenerator();
 *   $result = $gen->generateQuotePdf($quoteId);
 *   // $result = ['success' => true, 'path' => '...', 'version' => 2, 'filename' => 'QUO-2026-0001_v2.pdf']
 *
 * Migrated from: public/crm/includes/PdfGenerator.php
 */

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

class PdfGenerator
{
    private $db;
    private $storagePath;
    private $templatePath;

    private $projectRoot;

    public function __construct()
    {
        $this->db = getDB();
        $this->projectRoot = PROJECT_ROOT;
        $this->storagePath = STORAGE_ROOT . '/pdfs';
        $this->templatePath = CRM_ROOT . '/templates/pdf';

        // Ensure subdirectories exist
        if (!is_dir($this->storagePath . '/quotes')) {
            @mkdir($this->storagePath . '/quotes', 0755, true);
        }
        if (!is_dir($this->storagePath . '/invoices')) {
            @mkdir($this->storagePath . '/invoices', 0755, true);
        }
    }

    /**
     * Generate (or regenerate) a quote PDF.
     *
     * @return array{success: bool, path?: string, relative_path?: string, version?: int, filename?: string, error?: string}
     */
    public function generateQuotePdf(int $quoteId): array
    {
        try {
            // Fetch quote with related data
            $stmt = $this->db->prepare("
                SELECT
                    q.*,
                    p.address as property_address,
                    p.city as property_city,
                    p.postal_code as property_postal,
                    p.property_type,
                    c.company_name,
                    c.billing_email,
                    c.billing_phone,
                    c.billing_address,
                    c.billing_city,
                    c.billing_postal_code,
                    ct.first_name as contact_first,
                    ct.last_name as contact_last,
                    ct.email as contact_email,
                    ct.phone as contact_phone,
                    u.full_name as created_by_name
                FROM quotes q
                LEFT JOIN properties p ON q.property_id = p.id
                LEFT JOIN companies c ON q.company_id = c.id
                LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
                LEFT JOIN users u ON q.created_by = u.id
                WHERE q.id = ?
            ");
            $stmt->execute([$quoteId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$quote) {
                return ['success' => false, 'error' => 'Quote not found'];
            }

            // Fetch line items
            $stmt = $this->db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order");
            $stmt->execute([$quoteId]);
            $lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Render HTML template
            $html = $this->renderTemplate('quote.php', [
                'quote' => $quote,
                'lineItems' => $lineItems,
                'projectRoot' => $this->projectRoot,
            ]);

            // Generate PDF
            $mpdf = $this->createMpdf();
            $mpdf->WriteHTML($html);

            // Save to disk + update DB
            $quoteNumber = $quote['quote_number'] ?? 'QUOTE';
            return $this->savePdf($mpdf, 'quote', $quoteId, $quoteNumber);

        } catch (Exception $e) {
            error_log("PdfGenerator::generateQuotePdf error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate (or regenerate) an invoice PDF.
     *
     * @return array{success: bool, path?: string, relative_path?: string, version?: int, filename?: string, error?: string}
     */
    public function generateInvoicePdf(int $invoiceId): array
    {
        try {
            // Fetch invoice with related data.
            // Bill-to address falls back through:
            //   invoices.billing_*  ->  companies.billing_*
            //     ->  invoices.service_*        (the service address stored on this invoice)
            //     ->  properties.address/city/postal_code
            // so the Bill To section always has a usable address even when the company
            // record has no billing address on file.
            $stmt = $this->db->prepare("
                SELECT
                    i.*,
                    c.company_name,
                    c.payment_terms,
                    COALESCE(NULLIF(i.billing_address,''),     NULLIF(c.billing_address,''),     NULLIF(i.service_address,''),     NULLIF(p.address,''))     as billing_address,
                    COALESCE(NULLIF(i.billing_city,''),        NULLIF(c.billing_city,''),        NULLIF(i.service_city,''),        NULLIF(p.city,''))        as billing_city,
                    COALESCE(NULLIF(i.billing_province,''),    NULLIF(c.billing_province,''),    NULLIF(i.service_province,''))                              as billing_province,
                    COALESCE(NULLIF(i.billing_postal_code,''), NULLIF(c.billing_postal_code,''),NULLIF(i.service_postal_code,''), NULLIF(p.postal_code,''))   as billing_postal_code,
                    COALESCE(ct.first_name, dc.first_name) as contact_first,
                    COALESCE(ct.last_name,  dc.last_name)  as contact_last,
                    COALESCE(ct.email,      dc.email)      as contact_email,
                    COALESCE(ct.phone,      dc.phone)      as contact_phone,
                    p.property_name,
                    p.address as property_address,
                    p.city as property_city,
                    p.postal_code as property_postal,
                    u.full_name as created_by_name,
                    jp.plan_number, jp.title as job_title
                FROM invoices i
                LEFT JOIN companies c  ON i.company_id = c.id
                LEFT JOIN contacts ct  ON c.primary_contact_id = ct.id
                LEFT JOIN contacts dc  ON i.contact_id = dc.id
                LEFT JOIN properties p ON i.property_id = p.id
                LEFT JOIN users u      ON i.created_by = u.id
                LEFT JOIN job_plans jp ON i.plan_id = jp.id
                LEFT JOIN job_visits jv ON i.visit_id = jv.id
                WHERE i.id = ?
            ");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                return ['success' => false, 'error' => 'Invoice not found'];
            }

            // Fetch line items
            $stmt = $this->db->prepare("SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order");
            $stmt->execute([$invoiceId]);
            $lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Load our own business info (Mowology) — for GST number, address, etc. in the PDF header
            $business = [];
            try {
                // Pull everything the invoice template might need, including the
                // quirky slogan (company_tagline) and the four invoice_* messaging fields.
                // Use a defensive column list so the query still works on envs where
                // company_tagline hasn't been migrated yet.
                $hasTagline = false;
                try {
                    $hasTagline = (bool)$this->db->query(
                        "SHOW COLUMNS FROM business_settings LIKE 'company_tagline'"
                    )->fetch();
                } catch (Throwable $e) { /* ignore */ }

                $taglineCol = $hasTagline ? ', company_tagline' : '';
                $bizRow = $this->db->query(
                    "SELECT company_name, company_phone, company_email, company_website,
                            company_address, gst_registration, pst_registration, business_license,
                            invoice_message_header, invoice_terms_text,
                            invoice_payment_instructions, invoice_footer_text
                            {$taglineCol}
                     FROM business_settings WHERE id = 1"
                )->fetch(PDO::FETCH_ASSOC);
                if ($bizRow) {
                    $business = $bizRow;
                }
            } catch (Throwable $e) {
                // business_settings table may not exist on some envs — ignore
            }

            // Render HTML template
            $html = $this->renderTemplate('invoice.php', [
                'invoice' => $invoice,
                'lineItems' => $lineItems,
                'business' => $business,
                'projectRoot' => $this->projectRoot,
            ]);

            // Generate PDF
            $mpdf = $this->createMpdf();
            $mpdf->WriteHTML($html);

            // Save to disk + update DB
            $invoiceNumber = $invoice['invoice_number'] ?? 'INVOICE';
            return $this->savePdf($mpdf, 'invoice', $invoiceId, $invoiceNumber);

        } catch (Exception $e) {
            error_log("PdfGenerator::generateInvoicePdf error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get the filesystem path to a stored PDF.
     * Returns null if no cached PDF exists.
     */
    public function getPdfPath(string $type, int $id): ?string
    {
        $table = ($type === 'quote') ? 'quotes' : 'invoices';
        $stmt = $this->db->prepare("SELECT pdf_path FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['pdf_path'])) {
            return null;
        }

        $relPath = $row['pdf_path'];

        // Historically pdf_path was stored as "storage/pdfs/invoices/foo.pdf" relative
        // to project root, but files are actually written under APP_ROOT/Storage/pdfs.
        // Resolve both layouts so we can find the file in either location.
        $candidates = [];
        if (strpos($relPath, 'app/Storage/') === 0) {
            // New layout: already app/Storage-relative
            $candidates[] = PROJECT_ROOT . '/' . $relPath;
        } elseif (strpos($relPath, 'storage/') === 0) {
            // Legacy layout — the lowercase "storage/" prefix actually lives under APP_ROOT/Storage
            $candidates[] = APP_ROOT . '/' . ucfirst($relPath);           // APP_ROOT/Storage/pdfs/...
            $candidates[] = PROJECT_ROOT . '/' . $relPath;                 // PROJECT_ROOT/storage/pdfs/...
        } else {
            // Absolute or other form — try as-is
            $candidates[] = $relPath;
            $candidates[] = PROJECT_ROOT . '/' . $relPath;
        }

        foreach ($candidates as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Stream a PDF to the browser for download.
     */
    public function streamPdf(string $filePath, string $filename): void
    {
        // Validate file exists and is readable
        if (!file_exists($filePath)) {
            http_response_code(404);
            die('PDF file not found: ' . basename($filePath));
        }

        if (!is_readable($filePath)) {
            http_response_code(403);
            die('PDF file is not readable.');
        }

        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Stream file to browser
        if (!readfile($filePath)) {
            http_response_code(500);
            die('Error reading PDF file.');
        }
        exit;
    }

    /**
     * Create an mPDF instance with Mowology defaults.
     */
    private function createMpdf(): \Mpdf\Mpdf
    {
        $tmpDir = sys_get_temp_dir() . '/mpdf';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        return new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'Letter',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 20,
            'margin_header' => 10,
            'margin_footer' => 10,
            'default_font' => 'helvetica',
            'tempDir' => $tmpDir,
        ]);
    }

    /**
     * Render a PHP template file to HTML string.
     */
    private function renderTemplate(string $templateFile, array $data): string
    {
        extract($data);
        ob_start();
        include $this->templatePath . '/' . $templateFile;
        return ob_get_clean();
    }

    /**
     * Save PDF to storage and update the database record.
     */
    private function savePdf(\Mpdf\Mpdf $mpdf, string $type, int $id, string $number): array
    {
        $dir = $this->storagePath . '/' . $type . 's';

        // Ensure directory exists
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Determine next version number
        $table = ($type === 'quote') ? 'quotes' : 'invoices';
        $stmt = $this->db->prepare("SELECT pdf_version FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $newVersion = (int)($current['pdf_version'] ?? 0) + 1;

        // Clean filename
        $safeNumber = preg_replace('/[^A-Za-z0-9\-]/', '', $number);
        $filename = $safeNumber . '_v' . $newVersion . '.pdf';
        $fullPath = $dir . '/' . $filename;
        // Store as project-root-relative path that matches the real on-disk layout:
        // files live under APP_ROOT/Storage/pdfs/... which is app/Storage/pdfs/... from PROJECT_ROOT.
        $relativePath = 'app/Storage/pdfs/' . $type . 's/' . $filename;

        // Write PDF file
        $mpdf->Output($fullPath, \Mpdf\Output\Destination::FILE);

        // Update database
        $stmt = $this->db->prepare("
            UPDATE {$table}
            SET pdf_path = ?, pdf_version = ?, pdf_generated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$relativePath, $newVersion, $id]);

        return [
            'success' => true,
            'path' => $fullPath,
            'relative_path' => $relativePath,
            'version' => $newVersion,
            'filename' => $filename,
        ];
    }
}
