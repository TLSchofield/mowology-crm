<?php
/**
 * Lawn Tip Widget Block Renderer — Seasonal tip + weather display
 *
 * Config: {
 *   title              : string
 *   subtitle           : string
 *   weather_location   : string
 *   weather_icon       : string  (emoji)
 *   weather_temp       : string  (number)
 *   weather_condition  : string
 *   weather_humidity   : string
 *   weather_rain       : string
 *   weather_sun        : string
 *   lawn_condition     : string  (e.g. "Good — growth rate moderate")
 *   lawn_condition_pct : int     (0-100 bar fill)
 *   tip_badge          : string  (e.g. "🌱 March Lawn Tip")
 *   tip_heading        : string
 *   tip_body           : string
 *   cta_primary_text   : string
 *   cta_primary_url    : string
 *   cta_secondary_text : string
 *   cta_secondary_url  : string
 * }
 */

$title    = $config['title']    ?? "This Week's <em>Lawn Tip</em>";
$subtitle = $config['subtitle'] ?? 'Timely advice for your local Vancouver climate';

// Current month for auto-defaulting the tip badge
$currentMonth = date('F');

$weatherLoc   = $config['weather_location']   ?? 'Metro Vancouver, BC';
$weatherIcon  = $config['weather_icon']       ?? '🌦️';
$weatherTemp  = $config['weather_temp']       ?? (string)rand(6, 15); // rough seasonal
$weatherCond  = $config['weather_condition']  ?? 'Partly Cloudy';
$weatherHum   = $config['weather_humidity']   ?? '68%';
$weatherRain  = $config['weather_rain']       ?? '';
$weatherSun   = $config['weather_sun']        ?? '';
$lawnCond     = $config['lawn_condition']     ?? 'Good — growth rate moderate';
$lawnPct      = max(0, min(100, (int)($config['lawn_condition_pct'] ?? 72)));

$tipBadge     = $config['tip_badge']          ?? '🌱 ' . $currentMonth . ' Lawn Tip';
$tipHeading   = $config['tip_heading']        ?? 'Time to Treat for Moss Before It Takes Over';
$tipBody      = $config['tip_body']           ?? "Vancouver's wet spring conditions are perfect for moss to establish itself. Now is the ideal time to apply an iron-based moss treatment — it acts fast and prepares the lawn for aeration and overseeding.";

$ctaPrimaryText = $config['cta_primary_text']   ?? 'Book a Service';
$ctaPrimaryUrl  = $config['cta_primary_url']    ?? '/quote';
$ctaSecText     = $config['cta_secondary_text'] ?? 'More Lawn Tips';
$ctaSecUrl      = $config['cta_secondary_url']  ?? '/blog';

$uid = 'ltip-' . ($block['id'] ?? substr(md5(uniqid('', true)), 0, 8));
?>

<section class="mw-ltip" id="<?= h($uid) ?>">
  <div class="mw-ltip__inner">
    <div class="mw-ltip__title">
      <h2><?= $title ?></h2>
      <p><?= h($subtitle) ?></p>
    </div>

    <div class="mw-ltip__card">
      <!-- Weather side -->
      <div class="mw-ltip__weather">
        <div class="mw-ltip__loc">📍 <?= h($weatherLoc) ?></div>
        <div class="mw-ltip__weather-main">
          <div class="mw-ltip__weather-icon"><?= h($weatherIcon) ?></div>
          <div>
            <div class="mw-ltip__temp"><?= h($weatherTemp) ?><sup>°</sup></div>
            <div class="mw-ltip__condition"><?= h($weatherCond) ?></div>
          </div>
        </div>
        <div class="mw-ltip__details">
          <?php if ($weatherHum): ?><div class="mw-ltip__detail"><strong><?= h($weatherHum) ?></strong>Humidity</div><?php endif; ?>
          <?php if ($weatherRain): ?><div class="mw-ltip__detail"><strong><?= h($weatherRain) ?></strong>This Week</div><?php endif; ?>
          <?php if ($weatherSun): ?><div class="mw-ltip__detail"><strong><?= h($weatherSun) ?></strong>Sun Forecast</div><?php endif; ?>
        </div>
        <div class="mw-ltip__lawn-bar">
          <div class="mw-ltip__lawn-label">Lawn Condition</div>
          <div class="mw-ltip__bar-track">
            <div class="mw-ltip__bar-fill" style="width:<?= $lawnPct ?>%"></div>
          </div>
          <div class="mw-ltip__lawn-status"><?= h($lawnCond) ?></div>
        </div>
      </div>

      <!-- Tip side -->
      <div class="mw-ltip__content">
        <div class="mw-ltip__badge"><?= h($tipBadge) ?></div>
        <h3><?= h($tipHeading) ?></h3>
        <p><?= h($tipBody) ?></p>
        <div class="mw-ltip__actions">
          <a href="<?= h($ctaPrimaryUrl) ?>" class="mw-ltip__btn mw-ltip__btn--primary"><?= h($ctaPrimaryText) ?></a>
          <a href="<?= h($ctaSecUrl) ?>" class="mw-ltip__btn mw-ltip__btn--ghost"><?= h($ctaSecText) ?></a>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
.mw-ltip{background:#ede9df;padding:clamp(3rem,5vw,5rem) 1.5rem;border-top:1px solid rgba(45,122,58,.12)}
.mw-ltip__inner{max-width:900px;margin:0 auto}
.mw-ltip__title{text-align:center;margin-bottom:2.5rem}
.mw-ltip__title h2{font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3.5vw,2.8rem);color:#1c2b1e}
.mw-ltip__title h2 em{color:#2d7a3a;font-style:italic}
.mw-ltip__title p{color:#4a6350;margin-top:.5rem}
.mw-ltip__card{background:#fff;border-radius:24px;border:1px solid rgba(45,122,58,.15);overflow:hidden;display:grid;grid-template-columns:1fr 1fr}
.mw-ltip__weather{background:linear-gradient(135deg,#2d7a3a 0%,#1a5227 100%);padding:2.25rem;display:flex;flex-direction:column;justify-content:space-between;gap:1.25rem}
.mw-ltip__loc{color:rgba(255,255,255,.65);font-size:.78rem;text-transform:uppercase;letter-spacing:.1em}
.mw-ltip__weather-main{display:flex;align-items:center;gap:.9rem}
.mw-ltip__weather-icon{font-size:3.5rem;line-height:1}
.mw-ltip__temp{font-family:'DM Mono',monospace;font-size:3.2rem;color:#fff;line-height:1}
.mw-ltip__temp sup{font-size:1.2rem;vertical-align:super}
.mw-ltip__condition{color:rgba(255,255,255,.75);font-size:.88rem;margin-top:.2rem}
.mw-ltip__details{display:flex;gap:1.25rem}
.mw-ltip__detail{color:rgba(255,255,255,.65);font-size:.78rem}
.mw-ltip__detail strong{display:block;color:#fff;font-size:.92rem;margin-bottom:.1rem}
.mw-ltip__lawn-bar{padding-top:1.25rem;border-top:1px solid rgba(255,255,255,.15)}
.mw-ltip__lawn-label{color:rgba(255,255,255,.5);font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.4rem}
.mw-ltip__bar-track{background:rgba(255,255,255,.1);border-radius:6px;height:7px;overflow:hidden}
.mw-ltip__bar-fill{background:#c9a84c;height:100%;border-radius:6px;transition:width 1s ease}
.mw-ltip__lawn-status{color:rgba(255,255,255,.55);font-size:.76rem;margin-top:.35rem}
.mw-ltip__content{padding:2.25rem;display:flex;flex-direction:column;justify-content:center;gap:1rem}
.mw-ltip__badge{display:inline-flex;align-items:center;gap:.35rem;background:rgba(45,122,58,.1);color:#2d7a3a;padding:.28rem .7rem;border-radius:6px;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;width:fit-content}
.mw-ltip__content h3{font-family:'Playfair Display',serif;font-size:1.35rem;color:#1c2b1e;line-height:1.3;margin:0}
.mw-ltip__content p{color:#4a6350;font-size:.88rem;line-height:1.7;margin:0}
.mw-ltip__actions{display:flex;gap:.7rem;flex-wrap:wrap}
.mw-ltip__btn{padding:.6rem 1.2rem;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:600;transition:all .2s;border:none;cursor:pointer;text-decoration:none;display:inline-block}
.mw-ltip__btn--primary{background:#2d7a3a;color:#fff}
.mw-ltip__btn--primary:hover{background:#1a5227;color:#fff}
.mw-ltip__btn--ghost{background:#f8f5ef;color:#1c2b1e;border:1px solid rgba(45,122,58,.15)}
.mw-ltip__btn--ghost:hover{border-color:#2d7a3a;color:#2d7a3a}
@media(max-width:700px){.mw-ltip__card{grid-template-columns:1fr}}
</style>
