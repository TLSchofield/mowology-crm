<?php
/**
 * DailyQuote — one short line for the foot of Charlie's morning brief (owner, 2026-10-11).
 *
 * Only quotes whose wording and author are well established (mostly public domain: Stoics,
 * Franklin's Poor Richard, Thoreau, Voltaire) — many famous "quotes" are misattributed, so
 * none of those. Proverbs are credited as proverbs. Same quote for everyone on a given day.
 * Plain voice, no hype — matches the brand.
 *
 * Global-namespace class: require_once the file. Pure + tested.
 */
declare(strict_types=1);

final class DailyQuote
{
    /** [text, who, where] */
    public const QUOTES = [
        ['We must cultivate our garden.', 'Voltaire', 'Candide'],
        ['Make hay while the sun shines.', 'English proverb', ''],
        ['Well done is better than well said.', 'Benjamin Franklin', "Poor Richard's Almanack"],
        ['Diligence is the mother of good luck.', 'Benjamin Franklin', "Poor Richard's Almanack"],
        ['Lost time is never found again.', 'Benjamin Franklin', "Poor Richard's Almanack"],
        ["Never leave that till tomorrow which you can do today.", 'Benjamin Franklin', "Poor Richard's Almanack"],
        ['A journey of a thousand miles begins with a single step.', 'Lao Tzu', 'Tao Te Ching'],
        ['Fall seven times, stand up eight.', 'Japanese proverb', ''],
        ['Many hands make light work.', 'English proverb', ''],
        ['Slow and steady wins the race.', 'Aesop', 'The Tortoise and the Hare'],
        ['Waste no more time arguing about what a good man should be. Be one.', 'Marcus Aurelius', 'Meditations'],
        ['The impediment to action advances action. What stands in the way becomes the way.', 'Marcus Aurelius', 'Meditations'],
        ['Very little is needed to make a happy life.', 'Marcus Aurelius', 'Meditations'],
        ['It is not that we have a short time to live, but that we waste a lot of it.', 'Seneca', 'On the Shortness of Life'],
        ['While we wait for life, life passes.', 'Seneca', 'Letters'],
        ['Begin at once to live.', 'Seneca', 'Letters'],
        ['First say to yourself what you would be; and then do what you have to do.', 'Epictetus', 'Discourses'],
        ['Our life is frittered away by detail. Simplify, simplify.', 'Henry David Thoreau', 'Walden'],
        ['Heaven is under our feet as well as over our heads.', 'Henry David Thoreau', 'Walden'],
        ['The creation of a thousand forests is in one acorn.', 'Ralph Waldo Emerson', 'History'],
        ['Do what you can, with what you have, where you are.', 'Squire Bill Widener', 'quoted by Theodore Roosevelt'],
        ['We are what we repeatedly do. Excellence, then, is not an act, but a habit.', 'Will Durant', 'on Aristotle'],
        ['The best time to plant a tree was twenty years ago. The second best time is now.', 'Proverb', ''],
        ['One time, one meeting — treat each as the only one.', 'Japanese saying (ichi-go ichi-e)', ''],
    ];

    /** The quote for a day (Y-m-d): steps through the list one per day, the same for everyone. */
    public static function forDate(string $date): array
    {
        $ts = strtotime($date) ?: time();
        $day = (int)floor($ts / 86400);
        [$text, $who, $where] = self::QUOTES[$day % count(self::QUOTES)];
        return ['text' => $text, 'by' => $who . ($where !== '' ? ', ' . $where : '')];
    }
}
