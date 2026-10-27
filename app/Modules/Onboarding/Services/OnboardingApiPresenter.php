<?php
declare(strict_types=1);

final class OnboardingApiPresenter
{
    /** Build the phone map without leaking web-only row fields. */
    public static function present(array $map, ?string $next, bool $readyToFinish): array
    {
        $steps = [];
        foreach ($map as $row) {
            $steps[] = [
                'key' => (string)($row['key'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'state' => (string)($row['state'] ?? 'todo'),
                'head' => (string)($row['owner'] ?? ''),
                'skippable' => (bool)($row['skippable'] ?? false),
            ];
        }
        return ['ok' => true, 'steps' => $steps, 'next' => $next, 'ready_to_finish' => $readyToFinish];
    }
}
