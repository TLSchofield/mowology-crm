<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fable audit 2026-11 (found while doing H5): GET recommendation.php?mode=recipients let a crew login
 * read the contact emails for any property_id when visit_id was 0 or the visit had no property.
 * FieldRecommendationService::crewRecipientsProblem() is the rule: crew get recipients only for a
 * visit they are on, and the property comes from that visit.
 */
class FieldRecommendationRecipientsScopeTest extends TestCase
{
    public function testNoVisitIsRefusedForCrew(): void
    {
        $this->assertNotNull(FieldRecommendationService::crewRecipientsProblem(0, 7, 7));
        $this->assertNotNull(FieldRecommendationService::crewRecipientsProblem(0, 0, 9));
    }

    public function testVisitWithoutPropertyIsRefusedEvenWhenPropertyIsNamed(): void
    {
        // The old code fell back to the caller's property_id here and returned that property's contacts.
        $this->assertSame(
            'This visit has no property on file',
            FieldRecommendationService::crewRecipientsProblem(42, 0, 9)
        );
    }

    public function testNamedPropertyNotOnVisitIsRefused(): void
    {
        $this->assertSame(
            'That property is not on this visit',
            FieldRecommendationService::crewRecipientsProblem(42, 7, 9)
        );
    }

    public function testVisitOwnPropertyIsAllowed(): void
    {
        $this->assertNull(FieldRecommendationService::crewRecipientsProblem(42, 7, 7));
        $this->assertNull(FieldRecommendationService::crewRecipientsProblem(42, 7, 0));
    }
}
