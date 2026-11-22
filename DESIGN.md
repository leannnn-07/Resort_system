---
name: Vista
description: Editorial coastal resort booking system in sun-warmed coral limestone and deep Zamboanga twilight water.
colors:
  terracotta-ochre: "#a84217"
  terracotta-ochre-deep: "#8d3510"
  reef-teal: "#065a6b"
  reef-teal-deep: "#044452"
  travertine-gold: "#e8a85a"
  travertine-pale: "#f4d6a8"
  coral-limestone: "#f7f3ec"
  sand-recess: "#efe9de"
  architectural-white: "#ffffff"
  cell-hover-tint: "#faf8f4"
  cell-focus-tint: "#faf6ef"
  twilight-abyss: "#071926"
  twilight-slate: "#0d2738"
  editorial-ink: "#091b28"
  coastal-slate: "#425464"
  hero-mist: "#e4edf2"
  abyss-body: "#cbd9e2"
  footer-slate: "#cbd5e1"
  hairline-ink: "rgba(9, 27, 40, 0.13)"
  hairline-ink-strong: "rgba(9, 27, 40, 0.24)"
  hairline-light: "rgba(255, 255, 255, 0.16)"
  hairline-light-strong: "rgba(255, 255, 255, 0.42)"
typography:
  display:
    fontFamily: "'Cormorant Garamond', Georgia, 'Times New Roman', serif"
    fontSize: "clamp(2.85rem, 6.1vw, 5.15rem)"
    fontWeight: 500
    lineHeight: 0.96
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "'Cormorant Garamond', Georgia, 'Times New Roman', serif"
    fontSize: "clamp(2rem, 3.6vw, 3.1rem)"
    fontWeight: 500
    lineHeight: 1.04
    letterSpacing: "-0.02em"
  headline-water:
    fontFamily: "'Cormorant Garamond', Georgia, 'Times New Roman', serif"
    fontSize: "clamp(2.25rem, 4.1vw, 3.55rem)"
    fontWeight: 500
    lineHeight: 1.02
    letterSpacing: "-0.02em"
  title:
    fontFamily: "'Cormorant Garamond', Georgia, 'Times New Roman', serif"
    fontSize: "1.62rem"
    fontWeight: 600
    lineHeight: 1.12
    letterSpacing: "-0.015em"
  quote:
    fontFamily: "'Cormorant Garamond', Georgia, 'Times New Roman', serif"
    fontSize: "1.48rem"
    fontWeight: 500
    lineHeight: 1.38
    letterSpacing: "0em"
  body:
    fontFamily: "'Instrument Sans', system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "15.5px"
    fontWeight: 400
    lineHeight: 1.55
    letterSpacing: "0em"
  body-compact:
    fontFamily: "'Instrument Sans', system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0em"
  price:
    fontFamily: "'Instrument Sans', system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "19px"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "0em"
  label:
    fontFamily: "'Instrument Sans', system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "11px"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "0.08em"
rounded:
  none: "0px"
  control: "3px"
  bar: "4px"
spacing:
  xs: "6px"
  sm: "12px"
  md: "18px"
  lg: "24px"
  xl: "32px"
  band-mobile: "42px"
  band-desktop: "64px"
  band-deep: "78px"
components:
  button-primary:
    backgroundColor: "{colors.terracotta-ochre}"
    textColor: "{colors.architectural-white}"
    rounded: "{rounded.control}"
    padding: "0 26px"
    height: "48px"
  button-primary-hover:
    backgroundColor: "{colors.terracotta-ochre-deep}"
    textColor: "{colors.architectural-white}"
  button-ink:
    backgroundColor: "{colors.editorial-ink}"
    textColor: "{colors.architectural-white}"
    rounded: "{rounded.control}"
    padding: "0 22px"
    height: "44px"
  button-ink-hover:
    backgroundColor: "{colors.terracotta-ochre}"
    textColor: "{colors.architectural-white}"
  button-line:
    backgroundColor: "transparent"
    textColor: "{colors.architectural-white}"
    rounded: "{rounded.control}"
    padding: "0 24px"
    height: "48px"
  button-nav-over-photo:
    backgroundColor: "{colors.architectural-white}"
    textColor: "{colors.editorial-ink}"
    rounded: "{rounded.control}"
    padding: "0 16px"
    height: "40px"
  finder-bar:
    backgroundColor: "{colors.architectural-white}"
    textColor: "{colors.editorial-ink}"
    rounded: "{rounded.bar}"
  finder-cell:
    backgroundColor: "{colors.architectural-white}"
    textColor: "{colors.editorial-ink}"
    padding: "13px 18px"
  finder-submit:
    backgroundColor: "{colors.terracotta-ochre}"
    textColor: "{colors.architectural-white}"
    padding: "0 30px"
    height: "66px"
  card-plate:
    backgroundColor: "{colors.architectural-white}"
    textColor: "{colors.editorial-ink}"
    rounded: "{rounded.none}"
    padding: "20px 22px 22px"
  tab-item:
    backgroundColor: "transparent"
    textColor: "{colors.coastal-slate}"
    padding: "10px 0 16px"
    height: "44px"
  nav-solid:
    backgroundColor: "{colors.coral-limestone}"
    textColor: "{colors.editorial-ink}"
    padding: "0 28px"
---

# Design System: Vista

## Overview

**Creative North Star: "The Zamboanga Coral & Limestone Folio"**

Vista's visual language treats Zamboanga beach resort booking with the composure of a print coastal monograph and the immediacy of a live availability ledger. Rather than floating translucent glass cards over busy backgrounds, every guest-facing screen alternates between sun-warmed coral limestone paper and deep twilight sea contrast bands, letting full-bleed photography, crisp hairline dividers, and monumental serif typography carry the atmosphere while prices, capacities, and dates remain effortlessly scannable on phones.

The system is intentionally flat and architectural. Surfaces sit directly on the page plane, separated by thin maritime-ink rules and disciplined vertical pacing rather than drop shadows or rounded pill chrome. Across guest-facing surfaces—homepage, resort directory, per-resort landing pages, stay details, and checkout—this Folio identity governs every typographic, chromatic, and structural choice. The staff and admin operations desk remains a flat, high-density operational workspace focused on tables, calendars, and payment verification speed, sharing the same discipline of crisp contrast, zero frosted-glass bloat, and restrained color.

Legacy frosted-glass blur panels, soft ambient glow orbs, gradient headline text, and eyebrow/kicker labels above headings are explicitly retired.

**Key Characteristics:**
- Sun-warmed coral limestone ground paired with deep Zamboanga twilight sea contrast bands (60-30-10 pacing).
- Monumental `Cormorant Garamond` display headlines anchored by crisp `Instrument Sans` controls, rates, and tabular figures.
- Rectilinear architectural framing (`0px` on plates and cards, `3px` on buttons, `4px` on floating finder bars) divided by `1px` hairlines.
- Strict flat-by-default surfaces with shadow reserved solely for floating hero search or checkout bars over photography.
- High-contrast accessibility across both light paper (`≥6.1:1`) and deep-water dark bands (`≥9.1:1`), with zero fabricated metrics or decorative ornament.

## Colors

The palette pairs sun-bleached coastal limestone with deep Sulu Sea twilight, reserving warm terracotta-ochre for decisive booking actions, deep reef teal for interactive information and rates on light grounds, and travertine gold exclusively for dark-water contrast bands.

### Primary
- **Terracotta Ochre** (`#a84217`): Reserved strictly for primary conversion actions—availability finder submission, primary closing CTAs, active masthead tab indicators, input focus underline accents, text selection highlights, and hover states on ink buttons (6.1:1 contrast with white text).
- **Terracotta Ochre Deep** (`#8d3510`): Hover and pressed state for primary terracotta actions.

### Secondary
- **Reef Teal** (`#065a6b`): Interactive links, resort starting prices, location sub-lines (`City, Barangay`), review resort links, and calculated night counts on light limestone or white surfaces (6.7:1 contrast on coral limestone, 7.4:1 on architectural white).
- **Reef Teal Deep** (`#044452`): Hover state for reef-teal text links.

### Tertiary
- **Travertine Gold** (`#e8a85a`): Used strictly on deep twilight sea (`#071926`) dark bands for italic display emphasis, step ledger numerals (`01`, `02`, `03`), star icons in dark ledgers, and high-visibility `:focus-visible` outlines on dark surfaces (9.1:1 contrast on `#071926`).
- **Travertine Pale** (`#f4d6a8`): Italic hero display emphasis over dark twilight photography where extra luminance improves scan speed.

### Neutral
- **Coral Limestone** (`#f7f3ec`): Primary page canvas for light bands and scrolled sticky navigation (`rgba(247, 243, 236, 0.98)`), replacing cold white and generic grey.
- **Sand Recess** (`#efe9de`): Subtle hover fill for horizontal directory index rows and quiet tonal recess areas.
- **Architectural White** (`#ffffff`): Crisp surface for resort plates, lead spreads, cottage rows, and the floating availability finder bar; also primary text on deep-water bands and hero photography.
- **Cell Hover Tint** (`#faf8f4`) & **Cell Focus Tint** (`#faf6ef`): Tactile warmth inside availability finder cells on pointer hover and keyboard focus.
- **Twilight Abyss** (`#071926`): Full-bleed dark contrast bands (hero base/scrim, 3-step GCash/Maya booking ledger, closing folio, and seamless dark footer).
- **Twilight Slate** (`#0d2738`): Secondary deep-water backdrop for loading media frames and hover state on solid-header ink buttons.
- **Editorial Ink** (`#091b28`): Primary headings, body copy, dark action buttons (`View resort`), and underlined editorial links on light grounds (>15:1 contrast).
- **Coastal Slate** (`#425464`): Secondary copy, taglines, inventory specifications, inactive tabs, dates, and uppercase field labels (7.2:1 contrast on coral limestone).
- **Hero Mist** (`#e4edf2`), **Abyss Body** (`#cbd9e2`), & **Footer Slate** (`#cbd5e1`): High-contrast secondary copy tiers on twilight photography, deep-water bands, and the shared dark footer.
- **Hairline Ink** (`rgba(9, 27, 40, 0.13)`) & **Hairline Ink Strong** (`rgba(9, 27, 40, 0.24)`): Structural borders for cards, pane dividers, quote separators, and masthead rules on light surfaces.
- **Hairline Light** (`rgba(255, 255, 255, 0.16)`) & **Hairline Light Strong** (`rgba(255, 255, 255, 0.42)`): Structural rules inside deep-water ledgers, hero footers, and ghost button borders.

### Named Rules
**The 60-30-10 Coastal Pacing Rule.** Sixty percent of every guest page lives on sunlit coral limestone and architectural white; thirty percent anchors the rhythm in full-bleed twilight abyss bands and deep ink; terracotta-ochre, reef teal, and travertine gold stay within ten percent so every action and price reads instantaneously.

**The Dark-Band Gold Rule.** Travertine gold (`#e8a85a`) and travertine pale (`#f4d6a8`) never appear as text or icons on light limestone or white surfaces—they are reserved exclusively for deep twilight abyss (`#071926`) grounds and dark photography.

**The Light-Surface Teal Floor.** Interactive links and starting prices on light surfaces always use Reef Teal (`#065a6b`) or Editorial Ink (`#091b28`), never lighter coastal teals that drop below WCAG AA 4.5:1 contrast.

## Typography

**Display Font:** `Cormorant Garamond` (weights `400`, `500`, `600`, `700` plus italics `400`, `500`, `600`; fallback `Georgia, 'Times New Roman', serif`)
**Body Font:** `Instrument Sans` (weights `400`, `500`, `600`, `700`; fallback `system-ui, -apple-system, 'Segoe UI', sans-serif`)

**Character:** High-contrast editorial authority paired with utilitarian clarity. `Cormorant Garamond` delivers monumental resort-magazine drama with tight negative tracking and italic inflection, while `Instrument Sans` keeps dates, guest counts, prices, and mobile controls razor-crisp over Philippine cellular connections.

### Hierarchy
- **Display** (`500` regular / `400` italic emphasis, `clamp(2.85rem, 6.1vw, 5.15rem)` desktop and `clamp(2.25rem, 10.2vw, 2.9rem)` mobile, line-height `0.96`–`1.0`, tracking `-0.025em`): Primary page thesis in full-bleed heroes.
- **Headline — Light Band** (`500`, `clamp(2rem, 3.6vw, 3.1rem)`, line-height `1.04`, tracking `-0.02em`): Section masthead titles on coral limestone.
- **Headline — Deep Water** (`500` / `400` italic gold emphasis, `clamp(2.25rem, 4.1vw, 3.55rem)`, line-height `1.02`, tracking `-0.02em`): Contrast band and closing folio headlines.
- **Title — Feature & Card** (`600`, `clamp(1.9rem, 2.8vw, 2.55rem)` on lead feature spreads, `1.62rem` on folio cards, `1.65rem` on cottage rows, `1.42rem` on compact index rows, line-height `1.06`–`1.12`): Resort names, room titles, and cottage titles.
- **Editorial Pull-Quote** (`500`, `1.48rem`, line-height `1.38`): Verified guest quotes in `Cormorant Garamond`.
- **Body** (`400`, `15.5px`–`16.5px` leads and taglines, `14px`–`14.5px` card descriptions and ledger steps, line-height `1.5`–`1.6`, max measure `38ch`–`56ch`): Scannable prose kept to one or two sentences per block.
- **Prices & Tabular Data** (`700`, `19px` on lead spreads, `16.5px` on cards, `14px` on index rows, `font-variant-numeric: tabular-nums`): Resort rates, ledger counts, and review aggregates.
- **Field Label** (`700`, `11px`, uppercase, tracking `0.08em`, Coastal Slate `#425464`): Exclusively for form control field labels inside paned inputs—never placed as an eyebrow above a display heading.

### Named Rules
**The Zero-Eyebrow Rule.** Never place an uppercase tracked kicker, pill badge, or small label above a display or section heading. Place location metadata (`City, Barangay`) and resort attributions directly beneath the heading in sentence case.

**The Single-Italic Accent Rule.** Reserve `Cormorant Garamond` italics (`400` weight) for one concluding phrase inside dark-band display headlines (`open for your date.`, `without the inbox wait.`, `beach day.`) so emphasis stays unmistakable and never clutters body text.

**The Tabular Numerals Rule.** Every peso rate, guest count, room/cottage inventory figure, and rating average sets `font-variant-numeric: tabular-nums` so numbers align cleanly across grids and index rows.

## Layout

Vista uses a unified `1200px` architectural rail centered inside full-bleed color bands. The fixed top navigation bar, full-bleed hero content, directory spreads, deep-water booking ledger, review quotes, closing folio, and dark footer all share the exact same `1200px` outer alignment—nothing overhangs the page margin.

- **Outer Gutters:** `28px` horizontal padding on desktop and tablet (`>640px`), tightening to `18px` on phones (`≤640px`).
- **Vertical Band Rhythm:** Limestone directory and review bands use `64px–72px` top padding and `72px–80px` bottom padding on desktop (`42px` top / `52px` bottom on phones). Deep-water bands use `78px` vertical padding (`52px` on phones); the closing folio uses `64px` (`48px` on phones).
- **First-Viewport Budget:** Full-bleed heroes stay compact (`min-height: 600px`, `padding: 118px 28px 46px` on desktop; `min-height: auto`, `padding: 84px 18px 28px` on mobile) so the headline, lead, live availability finder, and primary CTA always sit 100% inside both `1440×900` and `390×844` viewports while allowing the coral-limestone directory to surface immediately at the fold.
- **Directory Grid Progression:** Lead featured content uses a full-width `1.32fr : 1fr` horizontal split spread (`26px` bottom margin) above a balanced 3-column architectural folio grid (`24px` gap). Overflow entries beyond the 3-column grid collapse into a compact hairline-divided 5-column horizontal index row (`1.4fr 1fr 0.9fr 0.9fr auto`) so desktop grids never leave orphan cards.
- **Responsive Breakpoints:**
  - **Tablet (`≤1020px`):** Availability finder shifts to a 2-column grid (stay type spans full width in cottage mode, submit spans full width at `52px` height); lead spread stacks to 1 column; folio grid moves to 2 columns; deep-water ledger and pull-quotes stack to 1 column.
  - **Mobile (`≤640px`):** Fixed header hides the secondary account button so brand, `Log in`, and menu toggle sit cleanly on one line; availability finder adapts cleanly in both Cottage mode (`1fr 0.72fr` for date + guests) and Overnight Room mode (`1fr 1fr` for check-in + check-out with full-width guests row); folio grid moves to 1 column (`18px` gap); masthead tabs and action links preserve `≥44px` minimum touch targets.

### Named Rules
**The Shared 1200px Rail Rule.** Navigation, content bands, and footer always share the exact same `1200px` inner container and `28px` (`18px` mobile) outer gutters so the left wordmark and right action button lock vertically to every section edge below.

**The Zero-Orphan Directory Rule.** Never render an unbalanced trailing row of 1 or 2 cards beneath a 3-column grid. Promote one lead item to a full-width horizontal spread, fill a complete 3-column folio row, and list remaining items in a compact horizontal index below.

## Elevation & Depth

Vista is a **flat, hairline-structured print system**. Depth comes from dramatic tonal alternation (sunlit `#f7f3ec` limestone against `#071926` twilight abyss), architectural `1px` borders, and full-bleed photographic contrast—not from blurred drop shadows or glassmorphism. Cards, spreads, tabs, and rows carry `box-shadow: none` and `backdrop-filter: none` at rest and on hover. Subtle hover feedback uses border darkening (`rgba(9, 27, 40, 0.24)`), a crisp `2px` upward translate on folio cards, or a `6px` left indent with `#efe9de` fill on horizontal index rows.

### Shadow Vocabulary
- **Floating Finder Shadow** (`box-shadow: 0 22px 48px rgba(4, 14, 22, 0.42)`): Used exclusively on the white architectural availability finder bar floating over full-bleed twilight hero photography to separate the bright ledger cleanly from dark water.

### Named Rules
**The Flat-By-Default Rule.** Every surface on limestone or deep-water grounds is completely flat (`box-shadow: none`, `backdrop-filter: none`). Elevation shadows appear only when an interactive white bar floats directly across full-bleed photography.

**The No-Glass Rule.** Decorative `backdrop-filter: blur()` panels are prohibited on guest surfaces and cards. Overlays over photography use directional linear dark-water scrims (`rgba(7, 25, 38, 0.88)` fading to `0.28` on desktop; uniform `0.76`→`0.90` vertical scrim on phones) for guaranteed legibility.

## Shapes

The form language is rectilinear, crisp, and architectural—inspired by print folios and steel-framed glass partitions.

- **Architectural Plates & Cards (`0px`):** Lead spreads, resort folio cards, photographic frames, and color-field plates have sharp square corners (`border-radius: 0`) enclosed by `1px solid rgba(9, 27, 40, 0.13)` hairlines.
- **Interactive Controls (`3px`):** Buttons, header navigation pills/links, and cottage rows use a restrained, tactile `3px` corner radius—crisp enough to stay architectural without looking harsh.
- **Floating Bars (`4px`):** The hairline-paned hero availability finder uses `4px` outer corners with `0px` square internal cells divided by `1px` rules.
- **Inner Plate Framing:** Resort color-field plates (when no cover photograph is uploaded yet) carry a `1px solid rgba(255, 255, 255, 0.24)` hairline frame inset `12px` from the edges over a dark-scrimmed themed coastal field—making photo-less resorts look like deliberate print plates rather than empty placeholders.

### Named Rules
**The Sharp-Plate, Crisp-Control Rule.** Structural surfaces and imagery stay square (`0px`), interactive controls soften just to `3px` (`4px` on floating search bars), and oversized `16px+` pill radii are avoided.

## Components

Every component is tactile, scannable, and restrained—built for single-thumb mobile booking and instant visual hierarchy.

### Buttons
- **Shape:** Crisp architectural rectangle (`3px` radius), `font-weight: 600`, `14px`–`15px` `Instrument Sans`, minimum touch height `44px` (`48px` for primary closing CTAs, `66px` integrated into desktop finder bar).
- **Primary (Terracotta Ochre):** `#a84217` fill, `#ffffff` text, padding `0 26px` (`0 30px` in finder). Hover darkens to `#8d3510`. Focus uses a `2px` white inset ring (`-4px` offset) inside the finder or `2px solid #e8a85a` (`2px` offset) on dark bands.
- **Editorial Ink (Card & Solid-Header Primary):** `#091b28` fill, `#ffffff` text, `44px` height, padding `0 22px`. Hover shifts cleanly to `#a84217` (or `#0d2738` in scrolled navigation).
- **Deep-Water Outline (Secondary on Dark):** Transparent fill, `1px solid rgba(255, 255, 255, 0.42)` border, `#ffffff` text, `48px` height, padding `0 24px`. Hover fills `rgba(255, 255, 255, 0.1)` with `#ffffff` border.
- **Editorial Inline Action Link:** `#091b28` (`14px`, `600`) with a crisp `1px solid #091b28` bottom rule (`6px 0` padding), shifting to `#a84217` on hover.

### Cards / Containers
- **Corner Style:** Square (`0px` radius) on lead spreads and resort folio cards; `3px` on horizontal cottage rows.
- **Background:** Architectural White (`#ffffff`) sitting directly on Coral Limestone (`#f7f3ec`).
- **Border:** `1px solid rgba(9, 27, 40, 0.13)`, darkening to `rgba(9, 27, 40, 0.24)` on hover with `translateY(-2px)`.
- **Internal Padding:** `34px 36px` on lead spread editorial panels (`22px 20px` on phones); `20px 22px 22px` on 3-column folio cards. Card footers lock to one horizontal baseline via `margin-top: auto` above a `1px` hairline divider (`padding-top: 16px`).
- **Horizontal Stay Rows:** Day-use cottages and rooms always render as horizontal photo-left detail rows (`.type-row.has-photo` on `#ffffff` with `1px` hairline border, `backdrop-filter: none`, `1.65rem` serif title, resort + `City, Barangay` directly beneath the heading, tabular price, and `View & book` / `Add to trip` actions).

### Inputs / Fields
- **Style:** Borderless native controls (`border: 0`, `border-radius: 0`, transparent background, `15px` `600` `#091b28` text, `caret-color: #a84217`, `color-scheme: light`) nested flush inside `13px 18px` white architectural cells separated by `1px solid rgba(9, 27, 40, 0.13)` vertical hairlines.
- **Labeling:** `11px` uppercase `700` Coastal Slate (`#425464`, `0.08em` tracking) stacked directly above the value with `3px` gap. Always use a single total `Guests` numeric input (`1–50`) rather than separate Adults/Children selectors.
- **Hover / Focus:** Cell background warms to `#faf8f4` on hover and `#faf6ef` on `:focus-within` with an inset `2px` bottom terracotta accent bar (`inset 0 -2px 0 #a84217`).

### Navigation
- **Fixed Editorial Bar:** Transparent over dark twilight heroes with a subtle top scrim (`rgba(7, 25, 38, 0.72)` to transparent), white `28px` `Cormorant Garamond` wordmark (no gradient dot icon), white navigation links, and a crisp white `#091b28`-text account CTA (`3px` radius).
- **Scrolled / Open State:** Transitions smoothly (`0.28s cubic-bezier(0.22, 1, 0.36, 1)`) into solid warm coral limestone (`rgba(247, 243, 236, 0.98)`) with a `1px` bottom hairline, `#091b28` wordmark, `#425464` links, and `#091b28` primary button.
- **Mobile (`≤640px`):** `18px` outer gutters; secondary header button hides so brand, `Log in`, and menu toggle stay uncrowded on one row.

### Architectural Availability Finder
The signature conversion bar. A white `4px`-radius horizontal grid (`1.3fr 1.15fr 0.82fr auto` in Cottage mode; `1.2fr 1fr 1fr 0.72fr auto` when `Overnight room` reveals `Check-out`) anchored over the lower hero with internal `1px` cell hairlines and an integrated full-height `#a84217` submit button. Automatically switches button copy (`Find cottages` / `Check rooms`) and date labels (`Visit date` / `Check-in`) without layout jump.

### Hairline-Flush Masthead Tabs
Section headers sit on a `1px solid rgba(9, 27, 40, 0.24)` full-width rule. Right-aligned text tabs (`14.5px`, `600`, `min-height: 44px`, `margin-bottom: -1px`) share one exact baseline with the trailing Reef Teal action link (`All resorts →`); the active tab anchors a `2px solid #a84217` indicator dead-flush onto the masthead hairline on both desktop and mobile.

### Deep-Water Numbered Ledger
Used inside `#071926` contrast bands for sequential workflows (such as the 3-step GCash/Maya booking process). Pairs a left editorial serif headline with a right `1px solid rgba(255, 255, 255, 0.16)` top-and-bottom ruled ordered list (`64px` numeral column on desktop, `44px` on mobile), using `32px` (`26px` mobile) `Cormorant Garamond` travertine-gold numerals (`01`, `02`, `03`) beside crisp white `17px` `Instrument Sans` titles and `#cbd9e2` descriptions.

### Editorial Pull-Quote Spread
Three-column review layout on coral limestone with zero boxed cards: columns divide cleanly via `1px solid rgba(9, 27, 40, 0.13)` vertical hairlines (`28px` internal side padding on desktop; horizontal bottom hairlines on tablet/mobile), pairing drawn SVG stars and a Reef Teal resort link above a `1.48rem` `Cormorant Garamond` quotation and a hairline-separated author/date footer.

## Do's and Don'ts

### Do:
- **Do** alternate sunlit Coral Limestone (`#f7f3ec`) bands with full-bleed Twilight Abyss (`#071926`) bands to maintain the 60-30-10 magazine rhythm across guest pages.
- **Do** lock every header, content band, and footer to the shared `1200px` container (`28px` desktop / `18px` mobile gutters).
- **Do** place `City, Barangay` location sub-lines and resort attributions directly below headings in sentence case, reserving full street addresses for inside resort pages and booking confirmations.
- **Do** use drawn inline SVG icons for stars, arrows, and UI glyphs, and set `font-variant-numeric: tabular-nums` on every price, inventory count, and rating.
- **Do** enforce `≥44px` touch heights on all buttons, tabs, and action links, and apply `2px solid #e8a85a` `:focus-visible` outlines on every dark band.
- **Do** render live database values (`archived_at IS NULL`) directly at full opacity on load—never hide below-the-fold sections behind scroll-gated `opacity: 0` traps.

### Don't:
- **Don't** place uppercase tracked eyebrows, kickers, or glowing-dot pill badges above headings.
- **Don't** apply `backdrop-filter: blur()`, drop shadows on resting cards, gradient headline text, ambient glow blobs, or one-sided accent border bars.
- **Don't** put Travertine Gold (`#e8a85a`) text or icons on light limestone or white backgrounds, or low-contrast mid-teals on light surfaces.
- **Don't** add meaningless decorative sequence numbers (`02`, `03`, `04`) to unranked cards, or fabricate customer counts, booking-time guarantees, press logos, or awards beyond `resort_db`.
- **Don't** allow nested bordered inputs inside paned search/checkout bars—always scope borderless input rules specifically enough to clear global input borders.
- **Don't** split guest count into separate Adults and Children fields on availability or booking forms—always use one total `Guests` field.
