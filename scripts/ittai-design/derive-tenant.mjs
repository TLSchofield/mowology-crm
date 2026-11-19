// Ittai v1.1 — derive a tenant's accessible accent set from ONE brand colour, and prove the contrast. No dependencies.
// usage: node derive-tenant.mjs "#2D8659" [--css]      (exit 1 if any rule cannot be met)
// Rules (DESIGN-SYSTEM.md §3, COMPONENTS.md Button):
//   light & field primary fill  : white label ≥ 7:1        (and, being that dark, ≥ 3:1 against the white page)
//   dark primary fill           : dark-ink label ≥ 7:1 AND fill ≥ 3:1 against the dark surface (#161E1A)
//   accent mark / focus of brand: ≥ 3:1 against its surface in each mode (non-text contrast)
//   soft tint (selected/current): text-primary on it ≥ 7:1 in each mode
// Hue and chroma are kept; only lightness moves (in OKLCH), so the tenant still recognises its colour.
// AAA (owner, 2026-10-19): the primary is also TEXT (links, bordered-button labels), so it must reach 7:1 on the
// darkest panel it sits on in each mode — the sunk tone (light #EEF1EE, dark #222D27), not only on white.
const LIGHT_SUNK = '#EEF1EE', DARK_SUNK = '#222D27';
const INK = '#0F1512', WHITE = '#FFFFFF', LIGHT_SURFACE = '#FFFFFF', DARK_SURFACE = '#161E1A', TEXT_LIGHT = '#131D17', TEXT_DARK = '#E9EEEA';

const hex2rgb = h => { h = h.replace('#', ''); if (h.length === 3) h = [...h].map(c => c + c).join(''); return [0, 2, 4].map(i => parseInt(h.slice(i, i + 2), 16) / 255); };
const rgb2hex = c => '#' + c.map(v => Math.round(Math.min(1, Math.max(0, v)) * 255).toString(16).padStart(2, '0')).join('').toUpperCase();
const toLin = v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
const toGam = v => v <= 0.0031308 ? 12.92 * v : 1.055 * v ** (1 / 2.4) - 0.055;
const lum = h => { const [r, g, b] = hex2rgb(h).map(toLin); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
export const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
function toOklch(h) {
  const [r, g, b] = hex2rgb(h).map(toLin);
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b), m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b), s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s, A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s, B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
  return [L, Math.hypot(A, B), Math.atan2(B, A)];
}
function fromOklch([L, C, H]) {
  for (let c = C; c >= 0; c -= 0.002) {                       // reduce chroma until it fits sRGB
    const A = c * Math.cos(H), B = c * Math.sin(H);
    const l = (L + 0.3963377774 * A + 0.2158037573 * B) ** 3, m = (L - 0.1055613458 * A - 0.0638541728 * B) ** 3, s = (L - 0.0894841775 * A - 1.291485548 * B) ** 3;
    const rgb = [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s, -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s];
    if (rgb.every(v => v >= -0.0005 && v <= 1.0005)) return rgb2hex(rgb.map(toGam));
  }
  return rgb2hex([L, L, L]);
}
// Move lightness from the brand's own until test() passes; dir -1 darkens, +1 lightens.
function solve(brand, dir, test) {
  const [L, C, H] = toOklch(brand);
  for (let i = 0; i <= 200; i++) { const x = fromOklch([Math.min(0.99, Math.max(0.02, L + dir * i * 0.005)), C, H]); if (test(x)) return x; }
  return null;
}
export function deriveTenant(brand) {
  const strong = solve(brand, -1, x => contrast(x, WHITE) >= 7 && contrast(x, LIGHT_SUNK) >= 7);          // light + field fill, and as text
  const bright = solve(brand, +1, x => contrast(x, INK) >= 7 && contrast(x, DARK_SUNK) >= 7);              // dark fill, and as text
  const markLight = solve(brand, -1, x => contrast(x, LIGHT_SURFACE) >= 3);
  const markDark = solve(brand, +1, x => contrast(x, DARK_SURFACE) >= 3);
  const [L, C, H] = toOklch(brand);
  const softLight = fromOklch([0.95, Math.min(C, 0.05), H]), softDark = fromOklch([0.27, Math.min(C, 0.05), H]);
  const checks = [
    ['light/field: white on primary', strong && contrast(WHITE, strong), 7],
    ['light/field: primary vs page', strong && contrast(strong, LIGHT_SURFACE), 3],
    ['light/field: primary as text on sunk (AAA)', strong && contrast(strong, LIGHT_SUNK), 7],
    ['dark: ink on primary', bright && contrast(INK, bright), 7],
    ['dark: primary vs surface', bright && contrast(bright, DARK_SURFACE), 3],
    ['dark: primary as text on sunk (AAA)', bright && contrast(bright, DARK_SUNK), 7],
    ['light: brand mark vs surface', markLight && contrast(markLight, LIGHT_SURFACE), 3],
    ['dark: brand mark vs surface', markDark && contrast(markDark, DARK_SURFACE), 3],
    ['light: text on soft tint', contrast(TEXT_LIGHT, softLight), 7],
    ['dark: text on soft tint', contrast(TEXT_DARK, softDark), 7],
  ].map(([name, v, need]) => ({ name, ratio: v ? Math.round(v * 100) / 100 : null, need, ok: !!v && v >= need }));
  return { brand: brand.toUpperCase(), strong, bright, markLight, markDark, softLight, softDark, checks, ok: checks.every(c => c.ok) };
}
export function tenantCss(t) {
  return `/* tenant ${t.brand} — generated by derive-tenant.mjs; every pairing checked */
:root { --tenant-accent: ${t.brand}; --tenant-mark: ${t.markLight}; --tenant-primary: ${t.strong}; --tenant-on-primary: #FFFFFF; --tenant-soft: ${t.softLight};
        --tenant-mark-dark: ${t.markDark}; --tenant-primary-dark: ${t.bright}; --tenant-on-primary-dark: ${INK}; --tenant-soft-dark: ${t.softDark}; }`;
}
if (import.meta.url === `file://${process.argv[1]}`) {
  const brand = process.argv[2]; if (!/^#?[0-9a-f]{3,6}$/i.test(brand || '')) { console.error('usage: node derive-tenant.mjs "#RRGGBB" [--css]'); process.exit(2); }
  const t = deriveTenant(brand.startsWith('#') ? brand : '#' + brand);
  if (process.argv.includes('--css')) console.log(tenantCss(t));
  else { console.log(`${t.brand} → primary ${t.strong} · dark primary ${t.bright} · mark ${t.markLight}/${t.markDark} · soft ${t.softLight}/${t.softDark}`);
         for (const c of t.checks) console.log(`  ${c.ok ? 'ok ' : 'FAIL'} ${String(c.ratio).padStart(5)}:1 (need ${c.need}) ${c.name}`); }
  process.exit(t.ok ? 0 : 1);
}
