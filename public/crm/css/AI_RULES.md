# CRM CSS — AI Rules

## Files
| File | Role | Editable? |
|------|------|-----------|
| `classic.css` | AppStack vendor base | **NO** |
| `corporate.css` | AppStack vendor alt theme | **NO** |
| `mowology-brand.css` | All brand overrides + custom classes | **YES** — only file to edit |

## CSS Token Reference
| Token | Hex | Usage |
|-------|-----|-------|
| `--mw-green` | `#2D8659` | Primary buttons, links, badges |
| `--mw-dark` | `#1A5F4A` | Hover states |
| `--mw-lime` | `#7FD858` | Active nav, success accents |
| `--mw-light` | `#E8F3F0` | Light backgrounds |
| `--mw-forest` | `#0D3B2E` | Sidebar background |
| `--mw-orange` | `#e85d04` | CTA accent |

## Section Map (mowology-brand.css)
1. `:root` tokens + AppStack overrides (sidebar, topbar, cards, buttons)
2. Dashboard (`.stat-card`, `.mw-qr-card`, urgency/status badges)
3. Quote Workflow (`.mw-split-*`, quote workflow page styles)
4. List Views (`.mw-stats-row`, `.mw-filter-tabs`, `.mw-table`, `.mw-badge-status`, `.mw-action-btn-*`)
5. Create/Edit Forms (`.mw-form-row`, `.mw-form-group`, `.mw-totals-box`)
6. Detail Views (`.mw-content-grid`, `.mw-detail-row`, `.mw-page-header`)
7. Job View (`.mw-note-item`, `.mw-photos-grid`, `.mw-modal-overlay`)
8. Quote View (`.mw-line-items-table`, `.mw-signature-section`, `.mw-activity-list`)
9. Schedule/Calendar (`.mw-calendar-*`, `.mw-job-card-sched`, `.mw-legend`)
10. Products Module (`.mw-tools-grid`, `.mw-tool-card`, `.mw-badge-tag`)
11. Products Manager (`.mw-product-*`)
12. Cost Factors (`.mw-calc-box`, `.mw-profit-card`, badge variants)
13. Area Measurement (`.mw-measure-*`, `.mw-area-item`)

## Naming Rules
- All custom classes use `.mw-` prefix
- Bootstrap 4 classes (`.btn`, `.card`, `.form-control`, `.d-flex`, etc.) are used as-is
- No `!important` unless overriding an existing `!important` in vendor CSS
- No inline `<style>` blocks in any PHP page
- No hardcoded hex colors — use `var(--mw-*)` tokens
