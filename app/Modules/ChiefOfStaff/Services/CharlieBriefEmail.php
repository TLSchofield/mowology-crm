<?php
/**
 * CharlieBriefEmail — the 7 am brief as an email to the owner (pure render, unit tested).
 *
 * Built from the same morning-brief payload the dashboard keeps (CharlieDeskService::payload),
 * so the email and the card agree. Sent only to the owner, through sendEmail() — never
 * PHPMailer or mail() directly. Email clients ignore CSS variables, so the brand colours
 * are inline here, matching EmailWrapper.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/CharlieVoice.php';

class CharlieBriefEmail
{
    private const GREEN = '#2D8659';
    private const FOREST = '#0D3B2E';
    private const LIGHT = '#E8F3F0';
    private const MUTED = '#4a6b5d';
    private const FONT = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;";

    /**
     * @param array  $payload  ['one' => ?key, 'items' => [...], 'heads' => [head => [name, role, headline, waiting, items]]]
     * @param string $baseUrl  e.g. https://mowology.ca
     * @return array{subject: string, body: string}  body is the inner HTML (wrap with EmailWrapper)
     */
    public static function render(array $payload, string $name, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $one = null;
        foreach ((array)($payload['items'] ?? []) as $it) {
            if (($it['key'] ?? null) === ($payload['one'] ?? false)) { $one = $it; break; }
        }
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $link = static function (?string $url) use ($baseUrl): string {
            if (!$url) return $baseUrl . '/crm/dashboard_appstack.php';
            return preg_match('#^https?://#', $url) ? $url : $baseUrl . '/' . ltrim($url, '/');
        };
        $p = 'style="margin:0 0 14px;font-size:15px;line-height:1.5;color:' . self::FOREST . ';' . self::FONT . '"';

        $html = '<p ' . $p . '>' . $e(CharlieVoice::hey($name)) . ' here\'s your morning.</p>';
        if ($one) {
            $html .= '<p ' . $p . '>The one thing that needs you today:</p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;"><tr>'
                . '<td style="background:' . self::LIGHT . ';border-left:4px solid ' . self::GREEN . ';border-radius:8px;padding:14px 16px;' . self::FONT . '">'
                . '<div style="font-size:16px;font-weight:700;color:' . self::FOREST . ';line-height:1.4;">' . $e($one['text']) . '</div>'
                . '<a href="' . $e($link($one['url'] ?? null)) . '" style="display:inline-block;margin-top:10px;font-size:14px;font-weight:700;color:' . self::GREEN . ';">Open it &rarr;</a>'
                . '</td></tr></table>';
        } else {
            $html .= '<p ' . $p . '>Nothing needs you this morning. Everyone\'s on top of their list.</p>';
        }

        $heads = (array)($payload['heads'] ?? []);
        if ($heads) {
            $html .= '<p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . self::MUTED . ';' . self::FONT . '">From the team</p>';
            foreach ($heads as $h) {
                $items = array_values(array_filter((array)($h['items'] ?? []), fn($it) => ($it['key'] ?? null) !== ($one['key'] ?? false)));
                $html .= '<div style="margin:0 0 14px;' . self::FONT . '">'
                    . '<div style="font-size:15px;color:' . self::FOREST . ';"><strong>' . $e($h['name'] ?? '') . '</strong>'
                    . (!empty($h['role']) ? ' <span style="color:' . self::MUTED . ';font-size:13px;">· ' . $e($h['role']) . '</span>' : '') . '</div>'
                    . '<div style="font-size:14px;color:' . self::MUTED . ';margin:2px 0 4px;">' . $e($h['headline'] ?? '') . '</div>';
                if ($items) {
                    $html .= '<ul style="margin:0;padding-left:18px;font-size:14px;line-height:1.5;color:' . self::FOREST . ';">';
                    foreach ($items as $it) {
                        $html .= '<li><a href="' . $e($link($it['url'] ?? null)) . '" style="color:' . self::FOREST . ';">' . $e($it['text']) . '</a></li>';
                    }
                    $html .= '</ul>';
                    $more = (int)($h['waiting'] ?? 0) - count($h['items'] ?? []);
                    if ($more > 0) $html .= '<div style="font-size:13px;color:' . self::MUTED . ';">+ ' . $more . ' more on the dashboard</div>';
                }
                $html .= '</div>';
            }
        }
        $html .= '<p style="margin:18px 0 0;font-size:14px;color:' . self::MUTED . ';' . self::FONT . '">I\'ll keep checking through the day — the dashboard always has the latest.<br>— Charlie</p>';

        return ['subject' => CharlieVoice::subject($one), 'body' => $html];
    }
}
