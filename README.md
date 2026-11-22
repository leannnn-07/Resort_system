# Vista: resort booking platform (Vista del Mar Resort and four more, all in the Zamboanga area)

PHP + MySQL. One website, many resorts. Every resort has its own rooms, buildings, pools, services, price list, staff, bookings, colours and web address. Guests can book rooms, **or an entire pool, an entire building, or the entire resort** for their group only.

- **Resort directory**: http://localhost/resort/ (`index.php`). The headline reads "Book a resort in Zamboanga Now", the search bar sits on top in the page header, and every active resort is listed below. Creating an account sends the guest to this page.
- **Each resort**: `landing.php?r=<slug>`, for example `landing.php?r=azzura-beach`. Rooms, price list, booking and checkout all keep the `r=` part in the address.
- **Staff desk**: http://localhost/resort/admin/ (every resort's staff sign in here and only see their own resort).

## Requirements
- PHP 8.1 or newer with `pdo_mysql`
- MySQL 8.0.16+ or MariaDB 10.2.1+

## Setup (XAMPP)
1. Start Apache and MySQL in the XAMPP Control Panel.
2. Copy the `resort` folder to `C:\xampp\htdocs\`.
3. Open http://localhost/phpmyadmin, click **Import**, choose `database/schema.sql`, click **Import**. (Importing again resets everything to the sample data.)
4. If your MySQL has a password, edit `config.php`.
5. Open http://localhost/resort/

**Already have the earlier single-resort version with real bookings?** Do not import `schema.sql` (it resets data). Back up, then import `database/upgrade_multiresort.sql` once. Everything you have becomes part of resort 1 (it keeps the name Baje's Resort until you rename it on the Resorts page), and four sample Zamboanga resorts and the platform admin are added. Your existing staff accounts become **Resort admins** of resort 1 (so nobody loses access); change anyone to **Staff** on the Staff page.

## Accounts required to book, and "Are you sure?" pop-ups
- **Booking needs an account.** A guest who is not logged in cannot check availability, book a stay, book a whole pool/building/resort, use **Trip**, or reach checkout. Every one of those pages calls `require_guest_to_book($pdo)` (in `includes/layout.php`), which sends the guest to the login page with a pop-up: **"Create an account first to book"** (Create account / Log in). After logging in or signing up they land back on the page they were trying to open. Browsing resorts, rooms, photos, prices and reviews stays open to everyone, and so does "Manage booking" with a reference.
- **Confirmation pop-ups.** Any button, form or link with a `data-confirm="Question?"` attribute asks first. Optional: `data-confirm-title`, `data-confirm-ok`, `data-confirm-cancel`, `data-confirm-tone="danger"`. It is wired up in `assets/app.js` (loaded on every public and staff page), so a new button only needs the attribute. Already added to: log out (guest and staff), cancel booking, remove from trip, change password, request booking / book trip, and on the staff side: delete (all list pages), remove photo, remove service, void payment, record payment, change reservation status (confirm, check in, check out, cancel), create reservation, hide/show review, save settings, add resort.

## Sample resorts
| Resort | Place | Theme |
|---|---|---|
| Vista del Mar Resort (the main one, resort 1) | Zamboanga City | sunset |
| Azzura Beach Resort and Hotel | Zamboanga City | lagoon |
| Santiago's Resort | Zamboanga City | dusk |
| Hannah's Nature Camp | Zamboanga City | pine |
| Dakak Park and Beach Resort | Dapitan City, Zamboanga del Norte | forest |

All five are real places in the Zamboanga area. Their descriptions and room types follow public travel-guide write-ups, but **everything else is made-up sample data**: prices, how many rooms, phone numbers, emails (all `.test`), staff names and guest reviews. Replace the prices, contacts and reviews with real ones (and get the resorts' permission) before going live. Each has 2 to 4 buildings or areas, 3 to 5 room types, 10 to 14 rooms, add-on services and a price list (Vista del Mar has no pool, so it only offers whole-resort and building bookings).

## Who can do what (three access levels)
| Level | Sample sign-in | Lands on | Can do |
|---|---|---|---|
| **Super admin** (runs the whole site) | platform@resort.test | Dashboard (Platform tab) | Everything: add and edit resorts, create staff accounts (**Accounts**) for any resort (and more super admins), see every resort's numbers, and open any resort's desk with the switcher. The only level that sees the **Platform** menu |
| **Resort admin** (runs one resort) | manager@azzura.test, admin@resort.test (Vista del Mar) | That resort's dashboard | Everything for their own resort: reservations, rooms, buildings, pools, room types, services, price list, settings and the resort's staff accounts. Can't see or open other resorts |
| **Staff** (front desk) | desk@azzura.test, jose@resort.test | That resort's dashboard | Reservations, calendar, guests and room status (for example Cleaning to Available). No prices, settings or staff pages |

Every page checks the level on the server, not just in the menu, and a resort admin can never reach another resort's data. A resort admin can't demote or delete their own account, and the site always keeps at least one super admin.

## Staff sign-in (password for all samples: **admin123**, change them under Accounts or on the Staff page)
| Who | Email | Level |
|---|---|---|
| Super admin | platform@resort.test | Super admin |
| Vista del Mar | admin@resort.test | Resort admin |
| Vista del Mar | jose@resort.test, ana@resort.test | Staff |
| Azzura Beach | manager@azzura.test / desk@azzura.test | Resort admin / Staff |
| Santiago's | manager@santiagos.test / desk@santiagos.test | Resort admin / Staff |
| Hannah's Nature Camp | manager@hannahscamp.test / desk@hannahscamp.test | Resort admin / Staff |
| Dakak | manager@dakak.test / desk@dakak.test | Resort admin / Staff |

Demo guest booking: reference **DEMO2345** with email **liza@example.com** on "Manage booking".

## Adding another resort
Sign in as the platform admin (platform@resort.test) and click **Add a resort** (on the Overview or All resorts page). It is a short guided set-up, one small step at a time, with a progress bar you can click to jump back:

1. **The basics**: name, location, tagline, about, colour theme and the background photo. This creates the resort as a **hidden draft**.
2. **Contact and stay rules**: email, phone, check-in/out times, free-cancellation days.
3. **Buildings or areas**, 4. **Room types** (with price and max guests), 5. **Rooms** (add many at once: `101, 102, 105-110`), 6. **Pools**, 7. **Services**, 8. **Price list**. Each step is a small add-form with the list of what you have added so far, so you can add another or remove a mistake. Pools, services and the price list can be skipped.
9. **Resort photos** (optional): upload the pictures for the See photos page. Captions and order are set later under Resort photos.
10. **Finish**: a summary (click a line to go back), an optional first resort admin account, and the **Open for bookings** switch. The resort only goes live here.

Every step saves straight away, so you can stop at any point: the draft stays hidden and **All resorts** shows a **Continue setup** button on it until it has rooms. Nothing in the old single long form is lost; everything can still be changed later in Settings, Buildings, Room types, Rooms and so on.

## Switching between resorts (super admin)
The **Managing** bar at the top of every resort page is a search box plus a drop-down list. Open it, type part of a name or place to filter (Arrow keys and Enter work, Esc closes), and pick a resort. It also has shortcuts to **All resorts** and **Add a resort**. Hidden drafts are marked. It stays usable without JavaScript as a plain list of links.
4. As soon as it has rooms it shows in the directory and can be booked. Setting a price on a building, a pool or the resort turns on whole-place booking for it.
Themes: ocean, lagoon, pine, sunset, forest, dusk. Photos are optional; without them a resort uses a colour backdrop in its theme.

## Booking an entire resort, building or pool
Guests open **Book the whole place** (`exclusive.php?r=<slug>`), pick dates and group size, and see what is free:
- **Entire pool**: private use of one pool for the day(s). Price is the pool's *rate per day*; the group must fit the pool's *max guests*.
- **Entire building**: every room in one building. Price is the building's *price per night* (empty = not offered). Capacity is the sum of the room occupancies.
- **Entire resort**: every room and every pool, price per night set on the resort (**Settings**, or the Resorts page).
How it stays safe: a whole-building or whole-resort booking reserves every room (rooms under Maintenance excepted) and a whole-resort booking also reserves every pool, so normal room search, other whole-place bookings and the calendar all treat them as taken. If a single room in a building is already booked, that building (and the resort) shows as unavailable for those dates. Two guests racing for the same thing get one winner (rows are locked inside a transaction). Cancelling releases everything.
Guests are not asked to list every name for these bookings. Staff and guests see the booking type ("Entire building: Summit House") on the booking page, in emails, and in the staff reservation list and view.

## How a normal room booking works
1. Guest picks check-in, check-out, adults and children, then sees available room types in that resort.
2. The system works out how many rooms the group needs and only offers room types that can hold the group.
3. Guest enters details (every other guest's name and type), optional add-ons, and confirms the emailed code. Rooms are assigned automatically and locked.
4. A booking reference such as `K7PM2QXA` is shown. Status starts as **Pending**.
5. The resort's staff confirm it, check the guest in, record payments, and check out (rooms go to **Cleaning**; set back to **Available** on the Rooms page).
6. Guests look up a booking with reference + email (the reference tells the site which resort it belongs to), print it, and cancel online while it is unpaid and far enough before check-in.

## Rules you can change in `config.php`
Minimum and maximum nights, how far ahead guests can book, maximum rooms and guests per booking, currency, and the platform name (`platform`, shown on the directory page). A resort's own name, check-in/out times, contact details and free-cancellation window are set per resort on the staff desk (Settings).

## Email, verification and password reset
Settings are in `config.php` under `mail`, `verify_email`, `base_url` and `force_https`.

- **Email code at checkout** (`verify_email => true`): before a booking is created the guest enters a 6-digit code sent to their email. Codes last 10 minutes, allow 5 tries, and can be re-sent once a minute. An email verified once in a session is not asked again in that session.
- **Emails sent**: verification code, "request received" (with the booking reference), "confirmed" and "cancelled" (when staff confirm or cancel, or the guest cancels online), and the staff password-reset link.
- **Staff password reset**: "Forgot your password?" on the staff sign-in page. The link works once for 1 hour. Only a hash of the link token is stored (table `password_reset`).
- **Test mode (default)**: `mail.driver` is `log`, so no real email is sent. Every email is written to `storage/mail.log`; open it to read verification codes and reset links.
- **Real email**: set `mail.driver` to `smtp` and fill in host, port, encryption, username and password. For Gmail use `smtp.gmail.com`, port 587, `tls`, your address and a Google *App password*. A failed send never loses a booking; it is written to the PHP error log.
- **Live site**: set `base_url` (for example `https://resort.example.com/resort`) so reset links can't be spoofed, then turn on `force_https` once your SSL certificate works.

## Guest names and guest types
- At checkout the guest says whether they are an **Adult** or a **Senior citizen (60+)**, then every other person in the party gets a row: **type** (Adult, Senior citizen or Child), first name, last name and age. The age must fit the type (adult 18 to 59, senior 60+, child 17 or younger).
- The number of rows comes from the adults and children chosen when searching; changing a person's type changes the adult/child counts saved on the reservation (the total stays the same).
- Staff see everyone in the **Guests** table on a reservation (Admin > Reservations > click the number in the Guests column, or open the reservation). It lists the booker (with email and phone) and each other guest with type and age. Staff can fill in the same rows when they create a reservation (optional there).
- Names also appear on the guest's booking page and in the confirmation email.
- Stored in table `reservation_companion` (and `reservation.booker_type`). For an existing database run `database/upgrade_companions.sql` once (safe to run again).

## Trips: several resorts, one checkout
A guest can pick stays at different resorts and book them together, instead of checking out once per resort.
- On any room list, room page or whole-place card, **Add to trip** sits next to **Reserve** (Reserve still books just that one stay). The **Trip** button in the header shows how many stays are in the trip (`trip.php`).
- The trip page groups stays by resort, re-checks price and availability every time, and lets the guest remove a stay. **Book trip** (`trip_checkout.php`) asks for the guest's details once, each stay's other guests and optional add-ons, and one email code (not needed when logged in).
- Every stay becomes its **own booking at its own resort** (own reference, own status), so each resort's staff only see their part. The stays share a **trip code**, one confirmation page (`trip_booked.php`) and **one** email. A guest finds a whole trip again with the trip code and email on *Manage booking*, or from *My account*.
- **All or nothing**: the stays are saved in one database transaction. If any stay was taken a moment ago, nothing is booked and the guest is told which one.
- A trip holds up to 8 stays (`TRIP_MAX_STAYS` in `includes/trips.php`). Nothing is paid online; each resort is paid at the resort as before.
- Stored as one new column, `reservation.trip_code`. For an existing database run `database/upgrade_trips.sql` once (safe to run again, needs MariaDB which XAMPP uses). Until it is run, trips still book correctly, but the stays are not linked in the database.

## Guest accounts (log in and create account)
- **Create account** (`register.php`): name, email, optional phone and a password (8+ characters). We email a 6-digit code; the account is only created once the code is entered, and the guest is then taken to the resorts page (unless they came from a booking in progress, which they return to). If the email was used for an earlier booking, the new account is attached to it, so old bookings show up straight away.
- **Log in / log out** (`login.php`, `logout.php`) and **Forgot password** (`forgot.php`, also by emailed code).
- **My account** (`account.php`): the guest's bookings (with a View button), their details, and a change-password form. The email address can't be changed.
- **Checkout while logged in**: name, email and phone are filled in, the email is locked, and no checkout code is needed because the email was already verified. Guests can still book without an account (they get the checkout code).
- Safety: passwords are stored as bcrypt hashes, codes expire in 10 minutes with 5 tries, requests are rate-limited, and the sign-up and forgot-password screens look the same whether or not an email already has an account.
- Stored as two new columns on `guest` (`password_hash`, `email_verified_at`). For an existing database run `database/upgrade_accounts.sql` once (safe to run again).

## Date and availability logic
- Check-in can't be in the past, check-out must be after check-in, and stays must be within the minimum and maximum nights.
- A room is free if no non-cancelled booking overlaps: another booking overlaps when its check-in is before your check-out and its check-out is after your check-in. A guest leaving the morning someone else arrives does not conflict.
- Rooms under **Maintenance** are never offered.
- Prices are copied into each booking, so later price changes don't alter old bookings.

## Staff desk pages
Dashboard, Reservations (search, booking type), New reservation, Reservation page (status, services, payments, balance, rooms/pools), Calendar (14-day room grid), Guests, Buildings, Rooms, Room types, Pools, Services, Price list, Settings (resort details and whole-resort price), Staff, and (platform admin only) Resorts. Resort staff can never open or change another resort's data: every page and every action is checked against their resort.

## Security notes
Prepared statements everywhere, escaped output, CSRF tokens on every form, hashed passwords, sign-in and lookup throttling.
Before going live: use HTTPS (`force_https`), set `base_url`, switch the mail driver to `smtp`, create a dedicated MySQL user instead of `root`, and change the sample passwords.

## On-site payments (GCash & Maya — Step 4 with screenshot & staff verification)
- Booking steps are **1 Dates → 2 Room → 3 Your details → 4 Payment → 5 Confirmed**.
- Each resort configures its own **Required downpayment percentage (0–100%, default 50%)** and **GCash / Maya account name and mobile number** under **Staff desk → Settings** (`admin/settings.php`).
- After entering guest details (Step 3), resorts with a downpayment send the guest to **Step 4 (`payment.php`)** where they see the resort's GCash & Maya details and required amount, transfer the funds, enter their **Reference Number**, and **upload a required screenshot** of their payment receipt (JPG, PNG or WebP, max 8 MB, stored safely in `uploads/payments/`). Duplicate reference numbers across bookings are blocked automatically.
- Submitted payments appear as **Pending** on **Staff desk → Reservations** (`Verify reference (1)`) and on the reservation page (`admin/reservation_view.php`) with the clickable screenshot thumbnail and reference number.
- Staff inspect the screenshot and check the reference number against their GCash/Maya app, then click **Verify & approve** (marks the payment `Paid`, automatically confirms a `Pending` booking once the required downpayment is met, and emails the guest) or **Reject** (voids it with a reason so the guest can re-upload a valid screenshot and reference number).
- Existing databases: auto-migrates on first load, or import `database/upgrade_payments.sql`.

## Not included
SMS.

## Photos
- Room and cottage photos go in `assets/rooms/<resort-slug>/<room-type-name>-<number>.jpg`, for example `assets/rooms/vista-del-mar/sea-casita-1.jpg` or `assets/rooms/azzura-beach/beach-villa-1.jpg` (type name in lowercase with dashes, numbered from 1). They play as a slideshow on the room page. The earlier Baje's Resort photos are still in `assets/rooms/bajes-resort/` but no resort uses them now; rename that folder and the files to match a resort's slug and room type names to reuse them. Resorts with no photos get a colour backdrop in their theme.
- A resort's cover photo is set on the Resorts page (stored in `assets/resorts/`), or by putting a file in `assets/` and typing its name in the resort's hero image.

## Upgrading an older database
Run only what you have not run yet, in this order: `upgrade_cottages_pricelist.sql`, `upgrade_hardening.sql`, `upgrade_email_auth.sql`, `upgrade_companions.sql`, `upgrade_accounts.sql`, `upgrade_multiresort.sql`, then `upgrade_trips.sql`, then `upgrade_reviews.sql`, then `upgrade_review_media.sql`, then `upgrade_resort_photos.sql`, then `upgrade_no_age.sql`. See `AUDIT.md` for the safety changes.

## Booking rules enforced by the system

- Status changes are atomic: if two staff change the same booking at once, the second is told to reload.
- Check-in is only possible from the arrival date; an ended stay cannot be re-confirmed.
- Checking out early releases the unused nights and removes later service charges; the total is recalculated.
- Cancelled or checked-out bookings cannot have services removed or payments added; a payment cannot be dated in the future.
- Rooms in Maintenance are held (rate 0) when a whole building or resort is booked, so nothing double-books.
- A pool with no daily rate is not offered for whole-pool booking.
- Select fields in admin forms reject values outside the offered options (tampering protection), including other resorts' records.
- Guests can only cancel Pending/Confirmed bookings with no payment on file, outside the free-cancellation window.

## Changing a booking

On a reservation page (pending or confirmed), staff can:
- **Change dates:** (checked-in stays: only the check-out date moves, never to today or earlier) the same rooms or pool are kept; the move is refused if another booking holds them on the new dates or a room is under maintenance. Services move with the stay, the total is recalculated and the guest is emailed.
- **Edit guests:** names, types and ages of everyone staying, and whether the booker is an adult or senior.

## Every input has an output, every output has an input

- Room photos: upload and remove them under Services > Room photos (they show on the room page and search results).
- Resort "About" text from Settings is shown on the resort's front page.
- Guests can have an ID type (Passport, Driver’s licence, ... or None presented) and an optional ID number. The number is stored encrypted (AES-256-GCM, key in `config.php` `id_key` or `storage/id.key`), shown masked to resort admins and as “On file” to front desk. Front desk can record a number but not read it back. Existing databases: run `database/upgrade_id_type.sql`, then `php database/encrypt_ids.php`.
- Guest requests can be edited by staff on the reservation page.
- Staff can book a whole pool, building or resort from New reservation, with the same availability checks as online booking.
- **Edit headcount:** on whole-pool, whole-building and whole-resort bookings staff can correct the number of adults and children, up to what the pool or rooms hold. It does not change the price.

## Booking flow safeguards

- Double clicks, back-button resubmits and re-sent verification forms open the booking already made instead of creating another.
- A guest email can hold at most 5 online requests awaiting confirmation, so one person cannot block the resort's rooms.
- An expired or orphaned verification form says so and sends the guest back to enter their details.
- If a booking fails after the email is verified (for example the room was just taken), the form returns with all details and add-ons filled in.
- Search explains when a group needs more rooms than one online booking allows.

## Ratings and reviews

- Each resort has its own rating (1 to 5 stars) and guest comments, shown on its front page (average, count and latest comments), on its **Reviews** page (star breakdown, filter by stars, paging) and next to every resort in the resort list.
- Only guests with a finished stay can review: after check-out, **Manage booking** (and My account) shows "How was your stay?". One review per stay; guests can edit it later. Only the first name and the initial of the surname are published, and the review is marked "Verified stay".
- Resort admins answer or hide reviews under **Resort > Reviews** (hidden reviews are left out of the average). The super admin sees each resort's rating on the Platform page.
- Existing databases: run `database/upgrade_reviews.sql` once. Fresh installs already include the table and a few sample reviews.

### Resort photos ("See photos")
- Every resort has a **See photos** page: a grid of the resort's own pictures that open full size with next and previous. It is the first link in the main menu (before Rooms), and there is a **See photos** button under the tagline on the resort's front page. **Manage booking** now lives in the footer and in My account.
- **Only resort admins and the super admin** can add, caption, reorder (arrows) or remove these photos, under **Services > Resort photos** in the staff area. Front-desk staff and guests cannot. Guests' own pictures stay with their reviews.
- Up to 20 photos per upload and 60 per resort, JPG, PNG or WebP, up to 8 MB each (or your server's limit). The files are saved in `uploads/gallery/<resort number>/` and use the same checks as review photos (the real file type is checked, not the name, and PHP can't run in that folder).
- Existing databases: run `database/upgrade_resort_photos.sql` once. Until you do, the button leads to an empty page and the upload form shows a hint. Fresh installs already include the table.
- The **Room photos** page is separate: it holds the pictures of each room type.

### Replacing a resort's cover photo
A replaced cover photo keeps the same file name, so browsers used to keep showing the old picture. The page address now carries the file's change time, so a new cover appears straight away (Settings also shows the current cover). Cover photos can be up to 8 MB.

### Photos and videos on reviews
- When a guest reviews a finished stay they can also add **photos and videos**: up to 6 photos (JPG, PNG or WebP) and 2 videos (MP4, WebM or MOV) per review. They can add more or remove their own later by editing the review. Photos show as small pictures that open full size (with next and previous), and videos play right in the review, on the resort's front page and on its Reviews page.
- Sizes: photos up to 8 MB and videos up to 50 MB each, but never more than your server allows. XAMPP's default `upload_max_filesize` and `post_max_size` are both 40M, so videos are limited to about 39 MB there and all the files of one review together must fit in 40 MB. To allow bigger videos, raise both values in `php.ini` (XAMPP Control Panel > Apache > Config > PHP) and restart Apache. The review form always shows the real limits, and a file that is too big is rejected with a clear message instead of failing silently.
- Existing databases: run `database/upgrade_review_media.sql` once. Until you do, reviews still work and the upload boxes are simply hidden. Fresh installs (`schema.sql`) already include the `review_media` table.
- Files are saved in `uploads/reviews/<review number>/` with random names, so the web server must be able to write to `uploads/` (on XAMPP for Windows this works out of the box; on Linux hosting set the folder to 755 or 775). `uploads/.htaccess` lets only pictures and videos be downloaded and switches PHP off in that folder, so an uploaded file can never run as code.
- Safety checks: the type of every file is decided from its real contents, not its name or what the browser says. Anything that is not a real JPG/PNG/WebP picture or MP4/WebM/MOV video is refused, as are iPhone HEIC pictures (iPhones convert a photo to JPG automatically when the upload box only accepts JPG, PNG and WebP). If any file is refused, nothing of that submission is saved.
- Small preview pictures are made automatically when PHP's GD extension is on (it is in XAMPP), so a page with many photos loads quickly. Without GD the full-size photo is used instead.
- Staff: **Resort > Reviews** shows each review's photos and videos with a **Remove** button (with an "Are you sure?" pop-up), and hiding a review hides its media too. New uploads go live straight away, like the written review, so it is worth checking this page now and then.
- Not included: photos are saved as the guest sent them (including any hidden camera data such as location), videos are not re-encoded, and the files of a resort that is deleted are left in `uploads/` for you to remove.

## Resort page layout

A resort's front page shows only: the booking card (dates and guests), what guests say, rooms and cottages, and entrance and cottage rates. The header has a round back button that returns to the list of all resorts, and no separate "All resorts" link. Whole-place booking stays one click away under **Whole resort** in the header.


## Room page and guest ages
- The room page uses the same title, steps bar and column as Rooms and the search results: photo gallery on the left, a booking card on the right that stays on screen while you scroll, details below.
- Guests are no longer asked for an exact age. Each other guest is only marked Adult, Senior or Child. Existing databases: run `database/upgrade_no_age.sql` once (until then bookings still work).
- When a booking can't be cancelled online, the Cancel button opens a pop-up with the reason (My account and the booking page).

## Accounts: login switch, archive, search

- **Login on/off** (Staff & admins, Staff, Users): a switch per account. Off = the person can't log in and is signed out on their next click; the account and its data are kept. Nobody can switch off their own login, the only active super admin can't be switched off or archived, and a resort admin can switch off front desk staff but not other resort admins.
- **Archive** (its own entry in the sidebar, for resort admins and the super admin): Delete on Guests, Staff, Staff & admins and Users now archives the record instead of erasing it, because a guest with bookings can't be deleted from the database. The Archive page lists archived guests/users and staff/admin accounts with the date, search, and a Restore button. Bookings are never lost. Archived accounts can't sign in. A resort admin sees only their own resort's archive; the super admin sees all.
- **Search**: every list page has a search box; All resorts and the public resort list can also be sorted by name or rating (resorts with no reviews come last). Super admins also get **Search** in the sidebar: one box across resorts, accounts, guests, bookings, rooms, add-ons and prices, each result opening its edit form.
- Existing databases get the two new columns (`login_enabled`, `archived_at`) automatically on first run; `database/upgrade_login_archive.sql` does the same by hand.

## Linked accounts (switch between user and staff account)

- **Accounts > Linked accounts** (resort admins and the super admin): pair a user account (by its verified email) with a staff or admin account. Each direction is its own switch:
  - **User → staff**: a resort admin or the super admin can allow it. The user sees a *Switch to staff account* button on My account.
  - **Staff → user**: only the super admin can allow it. The staff/admin sees *Switch to user account* at the bottom of the sidebar.
- A switch signs out of the account being left, so only one is active at a time. It is refused when either account is archived or has login off, and the user account needs a verified email. A resort admin can only link staff of their own resort. Unlinking keeps both accounts.
- Older databases get the `account_link` table automatically on first run (`database/upgrade_account_link.sql` does it by hand).
