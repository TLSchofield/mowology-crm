# AI CSS Rules — CMS / Public Site (Mowology)

You are modifying the PUBLIC WEBSITE CSS only.

## 1) Scope boundaries (non-negotiable)
- ONLY edit CSS files inside: /assets/css/ (and subfolders)
- DO NOT add `<style>` blocks into PHP/HTML files.
- DO NOT modify CRM styles or any file under /crm unless explicitly asked.
- DO NOT modify JS, templates, or PHP unless explicitly asked.

## 2) Where CSS must live
- All public-site styles must be in /assets/css/
- If a new style is needed, add it to the correct module file below (do NOT create random new files).

## 3) File structure (modular)

All CSS is loaded via a single entry point: **`master.css`** which uses `@import` to load modules in dependency order.

```
/assets/css/
  master.css                  <-- single entry point (imports only)

  base/
    reset.css                 <-- box-sizing, margin/padding reset
    variables.css             <-- all CSS custom properties (brand, text, bg, border)
    typography.css            <-- body font-family, h1-h6 defaults
    utilities.css             <-- scroll-behavior, ::selection, small helpers

  layout/
    layout.css                <-- .container (max-width: 1200px)
    header.css                <-- .navbar, .nav-links, .mobile-menu-toggle
    sections.css              <-- .section-title, .section-subtitle
    page-hero.css             <-- .page-hero (inner page banners)
    footer.css                <-- .footer, .footer-grid, .footer-bottom

  components/
    buttons.css               <-- .btn, .btn-primary, .btn-secondary, .btn-*-large
    forms.css                 <-- .form-group, .form-control, .submit-btn
    cards.css                 <-- .card, .card-header, etc.
    alerts.css                <-- .alert, .alert-danger
    cta.css                   <-- .cta-section

  pages/
    home.css                  <-- .hero, .services-overview, .testimonials, etc.
    services.css              <-- .service-detail, .benefits-grid, .process-step
    contact.css               <-- .contact-page, .contact-form, .contact-sidebar
    portfolio.css             <-- .portfolio-grid, .portfolio-filters
    about.css                 <-- .about-intro, .values-grid, .team-section
    lead-capture.css          <-- (placeholder for future lead capture page)
    jobflow-quote.css         <-- .jobflow-page scoped: form, progress bar, checkboxes
    jobflow-confirm.css       <-- .review-card, .review-row, .service-tag, .consent-summary
    jobflow-success.css       <-- .success-card, .info-box, .contact-box, .success-footer

  cms.css                     <-- (placeholder for future CMS styles)
```

### Where to put new styles

| What you're styling | File |
|---|---|
| A CSS variable / token | `base/variables.css` |
| A reusable UI element (button, card, alert) | `components/<name>.css` |
| Page layout structure | `layout/<name>.css` |
| Styles for a specific page | `pages/<name>.css` |
| jobFlow form pages | `pages/jobflow-*.css` (scope with `.jobflow-page`) |

## 4) CSS Variables (theming)

All colors and tokens are defined in `base/variables.css`. Use these variables, never hardcoded colors:

```css
/* Brand */
--mowology-green: #2D8659;
--mowology-dark: #1A5F4A;
--mowology-lime: #7FD858;

/* Semantic aliases (use these in components) */
--color-primary: var(--mowology-green);
--color-primary-dark: var(--mowology-dark);
--color-accent: #e85d04;

/* Text */
--text-dark: #1a1a1a;
--text-medium: #4a4a4a;
--text-light: #6a6a6a;

/* Backgrounds & borders */
--bg-light: #f8f9fa;
--bg-white: #ffffff;
--border-color: #e0e0e0;
```

## 5) Naming conventions (avoid conflicts)
- Use descriptive class selectors: `.hero`, `.service-card`, `.btn-primary`
- jobFlow pages use `.jobflow-page` body class for scoping (e.g., `.jobflow-page .container`)
- NEVER target generic elements globally like `body * { ... }` or `div { ... }`
- Prefer class selectors over IDs.

## 6) CMS component rules
- Components must be reusable and independent.
- Any new component must have:
  - a container class (e.g., `.section`, `.feature-grid`)
  - responsive behavior via media queries
  - no assumptions about page order

## 7) Avoid token waste
- Do not restyle the entire site to fix one issue.
- Make the smallest change that achieves the desired UI outcome.

## 8) Safety + performance
- Keep CSS readable.
- Do not add external imports from the web.
- Avoid `!important` unless overriding an existing `!important`.
- Always use CSS variables from `variables.css` for colors and repeated values.

## 9) Deliverables required in every change
When you modify CSS, always output:
1. The exact file(s) changed
2. The exact CSS block(s) added/edited
3. Where to paste it (top/bottom/under which selector)
