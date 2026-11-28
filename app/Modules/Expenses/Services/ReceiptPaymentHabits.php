<?php
declare(strict_types=1);

/**
 * ReceiptPaymentHabits — what the owner said about how they pay, applied to a receipt as it is read.
 * (Plan: docs/plans/2026-11-28-payment-method-question-plan.md.)
 *
 * The owner's answer lives in two ops_settings keys, no new column:
 *   payment_methods_used   comma list of EXPENSE_PAYMENT_METHODS codes
 *   payment_method_usual   one code, one of the above
 * Never a card or bank number. The last four digits are read from the receipt only, never asked or kept here.
 *
 * decide() is pure:
 *   - nothing said by the owner            → nothing changes
 *   - the reader found no method           → suggest the usual one (source 'usual'), never overwrites a read
 *   - the reader found a method they use   → keep it, no question
 *   - the reader found one they did not name → keep what was read, ask once (ask = true)
 * Cards are one family: a printed credit card matches either "credit card" or "company card".
 * Whether the question is raised belongs to the Penny question item kind (not built here).
 * No namespace / no autoloader in production: require_once and `new`.
 */
class ReceiptPaymentHabits
{
    public const KEY_USED = 'payment_methods_used';
    public const KEY_USUAL = 'payment_method_usual';

    private const METHODS = ['credit_card', 'debit', 'cash', 'etransfer', 'company_card', 'cheque', 'owner_paid'];
    private const CARD_FAMILY = ['credit_card', 'company_card'];

    /** The methods named in a stored list, valid and in order, without repeats. Pure. */
    public static function parseUsed(?string $stored): array
    {
        $out = [];
        foreach (explode(',', strtolower((string)$stored)) as $m) {
            $m = trim($m);
            if (in_array($m, self::METHODS, true) && !in_array($m, $out, true)) $out[] = $m;
        }
        return $out;
    }

    /** True when a read method is one the owner named (card kinds count as one family). Pure. */
    public static function isNamed(string $read, array $used): bool
    {
        if (in_array($read, $used, true)) return true;
        return in_array($read, self::CARD_FAMILY, true) && array_intersect(self::CARD_FAMILY, $used) !== [];
    }

    /**
     * @return array{payment_method:?string, source:?string, ask:bool}
     * source: 'read' (the reader's), 'usual' (the owner's usual, none was read) or null.
     */
    public static function decide(array $used, ?string $usual, ?string $read): array
    {
        $read = ($read !== null && trim($read) !== '') ? strtolower(trim($read)) : null;   // an unknown code is still "read"
        if ($used === []) return ['payment_method' => $read, 'source' => $read !== null ? 'read' : null, 'ask' => false];
        if ($read === null) {
            $u = ($usual !== null && in_array($usual, $used, true)) ? $usual : null;
            return ['payment_method' => $u, 'source' => $u !== null ? 'usual' : null, 'ask' => false];
        }
        return ['payment_method' => $read, 'source' => 'read', 'ask' => !self::isNamed($read, $used)];
    }

    /** What the owner said, or empty. */
    public static function load(PDO $db): array
    {
        $get = static function (string $k) use ($db): ?string {
            try {
                $s = $db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
                $s->execute([$k]);
                $v = $s->fetchColumn();
                return $v === false ? null : (string)$v;
            } catch (Throwable $e) {
                return null;   // table or key not there yet
            }
        };
        $used = self::parseUsed($get(self::KEY_USED));
        $usual = trim((string)$get(self::KEY_USUAL));
        return ['used' => $used, 'usual' => in_array($usual, $used, true) ? $usual : null];
    }

    /** The first answer: the methods named. A single one is the usual; with several the owner is asked which. */
    public static function saveUsed(PDO $db, array $used): array
    {
        $list = self::parseUsed(implode(',', $used));
        return self::save($db, $list, count($list) === 1 ? $list[0] : '');
    }

    /** The second answer: the usual one, which must be among those named. */
    public static function saveUsual(PDO $db, string $usual): array
    {
        $h = self::load($db);
        return in_array($usual, $h['used'], true) ? self::save($db, $h['used'], $usual) : $h;
    }

    /** Save the owner's answer. Invalid codes are dropped; the usual one must be among those named. */
    public static function save(PDO $db, array $used, ?string $usual): array
    {
        $list = self::parseUsed(implode(',', $used));
        $usual = $usual === '' ? null : (($usual !== null && in_array($usual, $list, true)) ? $usual : ($list[0] ?? null));
        $put = static function (string $k, ?string $v, string $desc) use ($db): void {
            $u = $db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
            $u->execute([$v, $k]);
            $e = $db->prepare('SELECT COUNT(*) FROM ops_settings WHERE setting_key = ?');
            $e->execute([$k]);
            if ((int)$e->fetchColumn() === 0) {
                $db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')->execute([$k, $v, $desc]);
            }
        };
        $db->beginTransaction();
        try {
            $put(self::KEY_USED, implode(',', $list), 'How the owner pays (method codes, comma list)');
            $put(self::KEY_USUAL, $usual, 'The owner\'s usual way of paying');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['used' => $list, 'usual' => $usual];
    }

    /**
     * Apply to one parsed receipt. Fills payment_method only when the reader found none.
     * @return array{parsed:array, habit:?array}  habit = ['source'=>..., 'ask'=>bool] or null when nothing was said.
     */
    public static function apply(PDO $db, array $parsed): array
    {
        $h = self::load($db);
        if ($h['used'] === []) return ['parsed' => $parsed, 'habit' => null];
        $d = self::decide($h['used'], $h['usual'], isset($parsed['payment_method']) ? (string)$parsed['payment_method'] : null);
        if ($d['source'] === 'usual') $parsed['payment_method'] = $d['payment_method'];
        return ['parsed' => $parsed, 'habit' => ['source' => $d['source'], 'ask' => $d['ask']]];
    }
}
