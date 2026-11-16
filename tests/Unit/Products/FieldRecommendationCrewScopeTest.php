<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * H5 (Fable audit 2026-11): a crew login may record a field recommendation only against its own
 * visit, and the property, contact and photos must belong to that visit. Office (admin / manager)
 * keeps its wider reach. FieldRecommendationService::crewScopeProblem() is the rule.
 */
class FieldRecommendationCrewScopeTest extends TestCase
{
    /** @return ?string null when allowed */
    private function problem(array $over = []): ?string
    {
        $a = array_merge([
            'visitId'           => 42,
            'visitPropertyId'   => 7,
            'payloadPropertyId' => 0,
            'payloadContactId'  => 0,
            'allowedContacts'   => [3, 4],
            'mediaIds'          => [881],
            'visitMediaIds'     => [881],
        ], $over);
        return FieldRecommendationService::crewScopeProblem(
            $a['visitId'], $a['visitPropertyId'], $a['payloadPropertyId'], $a['payloadContactId'],
            $a['allowedContacts'], $a['mediaIds'], $a['visitMediaIds']
        );
    }

    /** @test */
    public function no_visit_is_refused_so_crew_cannot_record_against_any_property(): void
    {
        // The old controller returned early on visit_id 0, letting a crew login name any property.
        $this->assertNotNull($this->problem(['visitId' => 0]));
    }

    /** @test */
    public function a_visit_with_no_property_on_file_is_refused(): void
    {
        $this->assertNotNull($this->problem(['visitPropertyId' => 0]));
    }

    /** @test */
    public function a_property_other_than_the_visit_property_is_refused(): void
    {
        $this->assertNotNull($this->problem(['payloadPropertyId' => 99]));
    }

    /** @test */
    public function the_visit_property_itself_is_allowed(): void
    {
        $this->assertNull($this->problem(['payloadPropertyId' => 7]));
    }

    /** @test */
    public function a_contact_not_tied_to_the_property_is_refused(): void
    {
        $this->assertNotNull($this->problem(['payloadContactId' => 555]));
    }

    /** @test */
    public function a_contact_tied_to_the_property_is_allowed(): void
    {
        $this->assertNull($this->problem(['payloadContactId' => 4]));
    }

    /** @test */
    public function a_photo_not_uploaded_for_this_visit_is_refused(): void
    {
        $this->assertNotNull($this->problem(['mediaIds' => [881, 902], 'visitMediaIds' => [881]]));
    }

    /** @test */
    public function photos_from_this_visit_are_allowed(): void
    {
        $this->assertNull($this->problem(['mediaIds' => [881, 882], 'visitMediaIds' => [882, 881]]));
    }

    /** @test */
    public function a_recommendation_with_no_optional_fields_is_allowed(): void
    {
        $this->assertNull($this->problem(['mediaIds' => [], 'visitMediaIds' => []]));
    }
}
