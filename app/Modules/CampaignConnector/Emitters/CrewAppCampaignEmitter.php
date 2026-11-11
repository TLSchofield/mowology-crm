<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Contracts/CampaignAware.php';

class CrewAppCampaignEmitter implements CampaignAware
{
    public static function registeredEvents(): array
    {
        return ['photos_uploaded'];
    }

    public static function eventSchema(string $eventName): array
    {
        switch ($eventName) {
            case 'photos_uploaded':
                return [
                    'photo_id'    => 'int — visit_photos.id',
                    'photo_type'  => 'string — before|after|during',
                    'property_id' => 'int|null',
                    'service_type'=> 'string|null',
                    'filename'    => 'string — stored filename in /uploads/photos/',
                ];
            default:
                return [];
        }
    }
}
