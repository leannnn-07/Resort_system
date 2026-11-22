---
version: 1
slug: "index-php"
primary_target: "index.php"
related_targets: []
---

# Surface Brief — index.php

## 1. TASK
Target: `index.php` (Vista platform homepage — Zamboanga multi-resort booking showcase).
Mode: `persuade` (directory-forward availability showcase).
Primary visitor: Zamboanga beachgoers, families, and barkadas browsing mostly on phones over Philippine cellular connections who want to check open day-use cottages or overnight rooms and book directly via GCash or Maya without Facebook DM back-and-forth.
Primary first-viewport action: Availability-first finder (`search.php` with Cottage/Room toggle, Visit date, single Guests count) + direct path into `resorts.php`, keeping `Log in` in the top navigation.

## 2. FORM
seed:abe4257de3040a4c — Assigned grounded direction #3 ("Zamboanga Coral & Limestone Folio", material family: `sun-bleached-coastal-plaster`) with named structural raise from challenger `craft-making-glazier-colorfield-partition` (rectilinear hairline pane grid and deliberate single saturated color field per focal plate) and user-selected A3 pacing (high-impact flagship resort magazine with full-bleed twilight photography, oversized editorial serif typography, and dramatic sunlit-limestone to deep-water contrast bands).

## 3. SYSTEM
- Ground / Light canvas (60%): Sun-warmed coral limestone `#f7f3ec` (`--mg-paper`), crisp white architectural cards `#ffffff` (`--mg-surface`), subtle sand recess `#efe9de`, hairline ink rules `rgba(9, 27, 40, 0.12)`.
- Deep-water contrast bands & ink (30%): Deep Zamboanga twilight sea `#071926` (`--mg-abyss`), secondary dark pane `#0d2738`, primary editorial ink `#091b28` (`>15:1` on light), muted slate ink `#425464` (`7.5:1` on light).
- Accents (10%): Burnished terracotta-ochre `#a84217` (`6.1:1` with white text for primary CTA buttons), deep reef teal `#065a6b` (`6.7:1` on limestone for links, active tabs, and prices), warm travertine gold `#e8a85a` (`9.1:1` strictly on `#071926` dark surfaces for chapter numerals and highlights).
- Typography: `Cormorant Garamond` (500/600/700 + italic) for monumental editorial display (`clamp(2.7rem, 5.8vw, 4.9rem)`, tight tracking `-0.025em`, `line-height: 0.98`) paired with `Instrument Sans` (400/500/600/700) for crisp UI controls, rates, and scannable body copy.
- Layout & rhythm: 5 focused sections — (1) Compact full-bleed twilight hero (`assets/landing.jpg`, 2208×1464) with hairline-divided architectural availability bar, (2) Immediately visible Featured Resorts (1 full-width split editorial lead spread + 3-column balanced architectural grid) & Day-Use Cottages tab (horizontal `.type-row.has-photo` rows), (3) Deep-water `#071926` 3-step GCash/Maya booking ledger, (4) Editorial 3-column verified guest pull-quotes, (5) Deep-water closing CTA band into shared footer.

## 4. TRUTHFULNESS & PRODUCT CONSTRAINTS
- Strict `PRODUCT.md` alignment: single total `Guests` field (`name="adults"`, hidden `children=0`); `City, Barangay` (`address_short()`) on resort cards; horizontal `.type-row.has-photo` rows for day-use cottages; `archived_at IS NULL` on all DB queries.
- Zero fabricated claims: remove invented "2 min" duration, padded "+" counts, press logos, and third-party awards. Display only real live counts and published reviews from `resort_db`.
- Craft floor compliance: zero eyebrows/kickers above headings, zero gradient text, zero frosted-glass blur cards, zero ambient blur orbs, zero one-sided accent bars.

## 5. INTERACTION & RESPONSIVE BEHAVIOR
- Desktop (`1440×900`): Full-bleed compact hero (`min-height: 580px`) keeps headline, lead, availability finder bar, and direct directory link 100% inside the first viewport while letting the limestone resorts chapter surface immediately at the fold.
- Mobile (`390×844`): Tightened hero typography and 2-row compact availability finder keep thesis (`<h1>`) and primary submit CTA completely visible above the `844px` fold with ≥44px touch targets and zero horizontal overflow.
- Shared `data-stay` JS handles Cottage vs Overnight Room check-out toggling; lightweight tabs switch `Featured resorts` / `Day-use cottages`; sticky header transitions cleanly from transparent over dark hero to solid warm limestone on scroll.

## 6. FINISH
FINISH: Editorial resort magazine homepage in sun-warmed coral limestone and deep twilight water, pairing Cormorant Garamond display typography with an architectural hairline-paned availability finder and immediate resort directory — crisp above the fold on 1440×900 and 390×844, zero fabricated metrics, zero decorative slop.
