<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BeforeAfterServiceTest extends TestCase
{
    public function testStrataCommercialAndManagedPropertiesNeedConsentResidentialDoesNot(): void
    {
        $this->assertFalse(BeforeAfterService::consentNeeded(['property_type' => 'single_family']));
        $this->assertFalse(BeforeAfterService::consentNeeded([]));
        $this->assertTrue(BeforeAfterService::consentNeeded(['property_type' => 'strata']));
        $this->assertTrue(BeforeAfterService::consentNeeded(['property_type' => 'Commercial']));
        $this->assertTrue(BeforeAfterService::consentNeeded(['property_type' => 'single_family', 'property_manager_id' => 4]));
        $this->assertTrue(BeforeAfterService::consentNeeded(['property_type' => '', 'company_id' => '9']));
    }

    public function testNeighbourhoodComesFromThePostalCodeThenTheCity(): void
    {
        $this->assertSame('Kitsilano', BeforeAfterService::neighbourhood('V6K 2G4', 'Vancouver'));
        $this->assertSame('Kerrisdale', BeforeAfterService::neighbourhood('v6m1a1', 'Vancouver'));
        $this->assertSame('Burnaby', BeforeAfterService::neighbourhood('V9Z 9Z9', 'Burnaby'));
        $this->assertNull(BeforeAfterService::neighbourhood(null, ''));
    }

    public function testServiceTypesMapToPublicNamesCategoriesAndLabels(): void
    {
        $this->assertSame('Lawn Care', BeforeAfterService::serviceName('lawn_care'));
        $this->assertSame('Lawn Cut', BeforeAfterService::serviceName('14 Day Lawn Cut Service'));
        $this->assertSame('Gardening', BeforeAfterService::serviceName('GARDENING'));
        $this->assertSame('Property Maintenance', BeforeAfterService::serviceName(null));

        $this->assertSame('lawn', BeforeAfterService::categoryFor('lawn_care'));
        $this->assertSame('garden', BeforeAfterService::categoryFor('Hedge Trimming'));
        $this->assertSame('cleanup', BeforeAfterService::categoryFor('Fall Cleanup'));
        $this->assertSame('general', BeforeAfterService::categoryFor('snow_removal'));
        $this->assertSame('strata', BeforeAfterService::categoryFor('lawn_care', true));

        $this->assertSame('Lawn Care — Kitsilano', BeforeAfterService::labelFor('lawn_care', 'Kitsilano'));
        $this->assertSame('Lawn Care', BeforeAfterService::labelFor('lawn_care', null));
        $this->assertSame('Lawn care in Kitsilano before Mowology', BeforeAfterService::altFor('before', 'lawn_care', 'Kitsilano'));
        $this->assertSame('Gardening in Metro Vancouver after Mowology', BeforeAfterService::altFor('after', 'GARDENING', null));
    }

    public function testPickPairTakesTheEarliestBeforeAndTheLatestAfter(): void
    {
        $links = [
            ['id' => 3, 'media_id' => 30, 'category' => 'after',  'created_at' => '2026-09-04 11:00:00'],
            ['id' => 1, 'media_id' => 10, 'category' => 'before', 'created_at' => '2026-09-04 09:00:00'],
            ['id' => 2, 'media_id' => 20, 'category' => 'before', 'created_at' => '2026-09-04 09:30:00'],
            ['id' => 4, 'media_id' => 40, 'category' => 'after',  'created_at' => '2026-09-04 11:30:00'],
            ['id' => 5, 'media_id' => 50, 'category' => 'additional', 'created_at' => '2026-09-04 12:00:00'],
        ];
        $this->assertSame(['before' => 10, 'after' => 40], BeforeAfterService::pickPair($links));
        $this->assertNull(BeforeAfterService::pickPair([$links[1]]));
        $this->assertNull(BeforeAfterService::pickPair([]));
    }

    public function testApprovalIsBlockedUntilNeededConsentIsRecorded(): void
    {
        $base = ['status' => 'pending', 'before_id' => 1, 'after_id' => 2];
        $this->assertTrue(BeforeAfterService::canApprove($base + ['consent_state' => 'not_needed'])['ok']);
        $this->assertTrue(BeforeAfterService::canApprove($base + ['consent_state' => 'recorded'])['ok']);

        $blocked = BeforeAfterService::canApprove($base + ['consent_state' => 'needed']);
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('agreement', $blocked['reason']);

        $this->assertFalse(BeforeAfterService::canApprove(['status' => 'approved', 'before_id' => 1, 'after_id' => 2, 'consent_state' => 'not_needed'])['ok']);
        $this->assertFalse(BeforeAfterService::canApprove(array_merge($base, ['after_id' => null, 'consent_state' => 'not_needed']))['ok']);
    }

    public function testWebPathPrefersTheWebWidthThenTheLargestModerateJpeg(): void
    {
        $variants = [
            ['format' => 'webp', 'width' => 1280, 'file_path' => '/v/a_1280w.webp'],
            ['format' => 'jpeg', 'width' => 640,  'file_path' => '/v/a_640w.jpg'],
            ['format' => 'jpeg', 'width' => 1920, 'file_path' => '/v/a_1920w.jpg'],
            ['format' => 'jpeg', 'width' => 1280, 'file_path' => '/v/a_1280w.jpg'],
        ];
        $this->assertSame('/v/a_1280w.jpg', BeforeAfterService::webPathFor($variants, '/o.jpg'));

        unset($variants[3]);
        $this->assertSame('/v/a_640w.jpg', BeforeAfterService::webPathFor(array_values($variants), '/o.jpg'));
        $this->assertSame('/o.jpg', BeforeAfterService::webPathFor([], '/o.jpg'));
    }

    public function testPublicShapeExposesOnlyPublicFieldsAndFallsBackToWebCopies(): void
    {
        $row = [
            'id' => 7, 'before_url' => '/_media/original/b.jpg', 'after_url' => '/_media/original/a.jpg',
            'web_before_path' => '/_media/variants/b_1280w.jpg', 'web_after_path' => null,
            'alt_before' => null, 'alt_after' => 'custom alt', 'label' => 'Lawn Care — Kitsilano',
            'service' => 'Lawn Care', 'category' => 'bogus', 'area' => 'Kitsilano',
            'approved_at' => '2026-09-22 10:00:00', 'created_at' => '2026-09-01 10:00:00',
            'address' => '123 Secret St', 'visit_id' => 1924, 'crew' => 'Nigel',
        ];
        $out = BeforeAfterService::publicShape($row);
        $this->assertSame('/_media/variants/b_1280w.jpg', $out['before_url']);
        $this->assertSame('/_media/original/a.jpg', $out['after_url']);
        $this->assertSame('Lawn care in Kitsilano before Mowology', $out['alt_before']);
        $this->assertSame('custom alt', $out['alt_after']);
        $this->assertSame('general', $out['category']);
        $this->assertSame('September 2026', $out['date']);
        foreach (['address', 'visit_id', 'crew', 'web_before_path'] as $private) {
            $this->assertArrayNotHasKey($private, $out);
        }
    }
}
