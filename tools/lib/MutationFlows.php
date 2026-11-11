<?php
declare(strict_types=1);

use Symfony\Component\Panther\Client as PantherClient;

/**
 * Sandboxed create -> verify -> cleanup flows, driven through the real CRM
 * UI (not raw SQL inserts) so form-level bugs actually get caught.
 *
 * Everything created here is tagged with QA_TAG so it's unambiguous, safe
 * to bulk-delete, and never confusable with real client data.
 *
 * Scope, deliberately: contact + property (simple form, all fields optional
 * except first/last name — low risk) and a best-effort quote draft chained
 * off that contact's property. The quote-create page drives a property
 * autofill widget and a JS service picker that could not be fully verified
 * without a live run against the site — if that step fails, it's reported
 * as a mutation failure (useful signal) rather than silently skipped, but
 * expect it may need a selector adjustment after the first real run.
 *
 * Explicitly excluded: invoices/payments (live Stripe keys), anything that
 * calls sendCrmEmail/sendSms, and users/settings/database/CMS admin pages.
 */
class MutationFlows
{
    public const QA_TAG = 'QA TEST — ignore';

    private PantherClient $client;
    private PDO $db;
    private string $baseUrl;
    /** @var array<int, array{table:string, id:int}> */
    private array $created = [];

    public function __construct(PantherClient $client, PDO $db, string $baseUrl)
    {
        $this->client = $client;
        $this->db = $db;
        $this->baseUrl = $baseUrl;
    }

    /** @return array<int, array{name:string,status:string,note:string}> */
    public function run(): array
    {
        $results = [];

        $contactId = null;
        try {
            $contactId = $this->createContact();
            $results[] = ['name' => 'contact: create + verify', 'status' => CrawlResult::PASS, 'note' => "id={$contactId}"];
        } catch (Throwable $e) {
            $results[] = ['name' => 'contact: create + verify', 'status' => CrawlResult::FAIL, 'note' => $e->getMessage()];
        }

        if ($contactId) {
            try {
                $quoteId = $this->createQuote($contactId);
                $results[] = ['name' => 'quote: create + verify', 'status' => CrawlResult::PASS, 'note' => "id={$quoteId}"];
            } catch (Throwable $e) {
                $results[] = ['name' => 'quote: create + verify', 'status' => CrawlResult::FAIL, 'note' => $e->getMessage()];
            }
        }

        // Cleanup runs regardless of pass/fail above — this is the primary
        // teardown path; finalSweep() below is the crash-safety net.
        try {
            $this->cleanupAll();
            $results[] = ['name' => 'cleanup', 'status' => CrawlResult::PASS, 'note' => count($this->created) . ' row(s) removed'];
        } catch (Throwable $e) {
            $results[] = ['name' => 'cleanup', 'status' => CrawlResult::FAIL, 'note' => $e->getMessage()];
        }

        return $results;
    }

    private function createContact(): int
    {
        $this->client->request('GET', $this->baseUrl . '/crm/clients_appstack.php?action=new');
        $this->client->waitFor('input[name="first_name"]', 10);

        $crawler = $this->client->getCrawler();
        $crawler->filter('input[name="first_name"]')->sendKeys(self::QA_TAG);
        $crawler->filter('input[name="last_name"]')->sendKeys('Automation');
        $crawler->filter('input[name="property_address"]')->sendKeys('123 QA Test Street');
        $crawler->filter('input[name="property_city"]')->sendKeys('Vancouver');
        $crawler->filter('input[name="property_postal_code"]')->sendKeys('V0V 0V0');
        $crawler->filter('button[type="submit"].btn-primary')->click();

        $this->client->waitFor('body', 10);

        $stmt = $this->db->prepare("SELECT id FROM contacts WHERE first_name = ? AND last_name = 'Automation' ORDER BY id DESC LIMIT 1");
        $stmt->execute([self::QA_TAG]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException('contact form submitted but no matching row found in contacts table');
        }
        $this->created[] = ['table' => 'contacts', 'id' => (int)$id];

        // The property, if the address field was accepted, links via site_contact_id.
        $propStmt = $this->db->prepare("SELECT id FROM properties WHERE site_contact_id = ? ORDER BY id DESC LIMIT 1");
        $propStmt->execute([$id]);
        $propId = $propStmt->fetchColumn();
        if ($propId) {
            $this->created[] = ['table' => 'properties', 'id' => (int)$propId];
        }

        return (int)$id;
    }

    private function createQuote(int $contactId): int
    {
        $this->client->request('GET', $this->baseUrl . '/crm/quotes/create.php?contact_id=' . $contactId);
        $this->client->waitFor('#propertySelect', 10);

        $crawler = $this->client->getCrawler();
        $propertyOptions = $crawler->filter('#propertySelect option[value!=""]');
        if ($propertyOptions->count() === 0) {
            throw new RuntimeException('no property option available on quote create form — property may not have saved correctly on the contact');
        }
        $firstValue = $propertyOptions->first()->attr('value');
        $this->client->executeScript(sprintf(
            'document.querySelector("#propertySelect").value = %s; document.querySelector("#propertySelect").dispatchEvent(new Event("change"));',
            json_encode($firstValue)
        ));

        $crawler = $this->client->getCrawler();
        $crawler->filter('input[name="title"]')->sendKeys(self::QA_TAG);

        $crawler->filter('#saveQuoteBtn')->click();
        $this->client->waitFor('body', 10);

        $stmt = $this->db->prepare("SELECT id FROM quotes WHERE title = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([self::QA_TAG]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException('quote form submitted but no matching row found in quotes table — the service-picker JS may require at least one line item before it will save');
        }
        $this->created[] = ['table' => 'quotes', 'id' => (int)$id];
        return (int)$id;
    }

    private function cleanupAll(): void
    {
        // Delete in dependency order: quote_line_items -> quotes -> properties -> contacts.
        foreach ($this->created as $row) {
            if ($row['table'] === 'quotes') {
                $this->db->prepare("DELETE FROM quote_line_items WHERE quote_id = ?")->execute([$row['id']]);
            }
        }
        // Reverse order so quotes are removed before the property/contact they reference.
        foreach (array_reverse($this->created) as $row) {
            $table = $row['table'];
            $id = $row['id'];
            if ($table === 'quotes') {
                $this->db->prepare("DELETE FROM quotes WHERE id = ? AND title = ?")->execute([$id, self::QA_TAG]);
            } elseif ($table === 'properties') {
                $this->db->prepare("DELETE FROM properties WHERE id = ? AND address LIKE 'QA Test Street%' OR (id = ? AND address = '123 QA Test Street')")->execute([$id, $id]);
            } elseif ($table === 'contacts') {
                $this->db->prepare("DELETE FROM contacts WHERE id = ? AND first_name = ?")->execute([$id, self::QA_TAG]);
            }
        }
    }

    /**
     * Crash-safety net: re-queries for anything still tagged QA_TAG at the
     * end of the run (e.g. the Chrome subprocess died mid-flow, skipping
     * the try/finally above) and removes it. Call this unconditionally
     * after run(), even if run() itself threw.
     */
    public function finalSweep(): int
    {
        $removed = 0;

        $quoteIds = $this->db->prepare("SELECT id FROM quotes WHERE title = ?");
        $quoteIds->execute([self::QA_TAG]);
        foreach ($quoteIds->fetchAll(PDO::FETCH_COLUMN) as $qid) {
            $this->db->prepare("DELETE FROM quote_line_items WHERE quote_id = ?")->execute([$qid]);
            $this->db->prepare("DELETE FROM quotes WHERE id = ?")->execute([$qid]);
            $removed++;
        }

        $contactIds = $this->db->prepare("SELECT id FROM contacts WHERE first_name = ?");
        $contactIds->execute([self::QA_TAG]);
        foreach ($contactIds->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $this->db->prepare("DELETE FROM properties WHERE site_contact_id = ?")->execute([$cid]);
            $this->db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$cid]);
            $removed++;
        }

        return $removed;
    }
}
