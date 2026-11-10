# Design review: what changed and why

**Problem:** every page was dark glass on a dark photo. Photos were small or missing on the booking pages, the rooms list was plain text rows, the phone menu wrapped awkwardly, and the whole site felt heavy for a place people visit to relax.

**Direction:** "golden hour, frosted light". The public site now uses light frosted glass over a softened version of the resort photo, deep navy buttons, and a serif headline font (Fraunces) with Inter for body text. The staff desk keeps its dark glass theme, so staff screens did not change.

| Area | Change |
|---|---|
| Header | Sticky frosted bar. Transparent over the hero photo, turns solid on scroll. Proper phone menu with a toggle button |
| Landing | Larger serif headline, white booking bar floating on the photo, rooms as photo cards, a closing call-to-action band |
| Rooms and cottages | Photo cards with capacity, photo count, price and one clear button, on the rooms page, booking home and landing |
| Search results | Photo beside every result, plus a 4-step progress bar (Dates, Room, Your details, Confirmed) |
| Checkout | Progress bar, room photo in the summary card |
| Confirmation | A clear "Request received" card with the booking reference right after booking |
| Room page | Header and text stay readable over bright photos; the booking panel is light frosted glass |
| Footer | Four columns: about, links, contact, check-in and check-out times |
| Accessibility | 16px base text, stronger contrast on light surfaces, keyboard-friendly menu (Escape closes it) |

Fonts come from Google Fonts. Offline, the site falls back to Georgia and system fonts and still works.

## Staff desk consistency pass

One set of rules now drives every staff page (`assets/style.css`, section "Staff desk design system", and `admin_head()` in `includes/layout.php`).

| Element | Rule |
|---|---|
| Navigation | Labelled sidebar in three groups (Operations, Property, Resort) plus Platform for the platform admin. Icon-only on tablets, a top icon bar on phones. "View website" and "Sign out" sit at the bottom with the signed-in name |
| Context bar | Directly under the top edge on every page: which resort you are managing (a switcher for the platform admin) and a one-line warning while the sample password is in use |
| Page header | Title (24px), one-line subtitle (counts, dates, context) and the page's main actions on the right. Made by `admin_head()` |
| Panels | Same padding and 16px gap; a panel title row; helper text under the title |
| Forms | Auto-filling grid, labels above fields, grey hint line under a field instead of long labels |
| Tables | Number and money columns (and their headings) right-aligned, long text muted and wrapping, row actions are small buttons: **Edit** / **Open** neutral, **Delete** / **Remove** / **Void** red |
| Status | Pills for status and Yes/No |
| Empty states | Centered message with the next step |

Fixes found during the review: the Reservations column called "Booked" actually showed room numbers (now "Rooms"); the room form offered "None" for the building although a building is required; a pool with no price was bookable for free (now it is simply not offered as a whole pool).

## Public pages (guest side)

One pattern for every public page, so nothing feels like a different site:

- **Grid:** content, header and footer share one 1120px column, so the logo, the first heading and the footer start on the same line.
- **Header:** brand, links, one primary button. The links turn into the menu button below 1040px so they never overflow on tablets.
- **Hero:** the homepage and every resort's landing page use the same full-photo hero (large serif title, italic lead, short text, buttons, a light search card, three facts). On scroll the photo melts into the same glass look as the pages below.
- **Page title:** every inner page starts with one `.page-head` title. When there is a progress bar (Dates, Room, Your details, Confirmed) it sits directly under the title. A page never repeats the header's "Book a stay" button.
- **Forms:** log in, create account, forgot password and manage booking are the same centred 520px card with the title inside it.
- **Reviews and ratings:** stars, bars and review cards share the glass card style used by rooms and prices.

## One theme for both sides
The guest site and the admin area share the same light "golden hour frosted" theme: the token block, headings, buttons, inputs, panels, badges and background layers are declared once for `body.site, body.admin`. Admin-only overrides (rail, tables, calendar swatches) live at the end of `assets/style.css` and use the same tokens. Fallbacks for no `backdrop-filter` and reduced transparency are light for both.

Only pages inside a resort (`?r=slug`) show that resort's photo and theme. The all-resorts home, login, account and booking look-up use the same backdrop as the staff area (`theme_css()` returns nothing outside a resort; the home page is `body.platform`, a light hero with no photo).
