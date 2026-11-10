# Resort booking platform (Baje's Resort and five more)

PHP + MySQL. One website, many resorts. Every resort has its own rooms, buildings, pools, services, price list, staff, bookings, colours and web address. Guests can book rooms, **or an entire pool, an entire building, or the entire resort** for their group only.

- **Resort directory**: http://localhost/resort/ (`index.php`). Lists every active resort with a search box.
- **Each resort**: `landing.php?r=<slug>`, for example `landing.php?r=coral-cove`. Rooms, price list, booking and checkout all keep the `r=` part in the address.
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

**Already have the earlier single-resort version with real bookings?** Do not import `schema.sql` (it resets data). Back up, then import `database/upgrade_multiresort.sql` once. Everything you have becomes part of Baje's Resort, and the five sample resorts and the platform admin are added. Your existing staff accounts become **Resort admins** of Baje's Resort (so nobody loses access); change anyone to **Staff** on the Staff page.

## Sample resorts
| Resort | Place | Theme |
|---|---|---|
| Baje's Resort | Beachfront | ocean |
| Coral Cove Resort | El Nido, Palawan | lagoon |
| Pinewood Highlands Retreat | Baguio, Benguet | pine |
| Sunset Lagoon Resort | Panglao, Bohol | sunset |
| Palm Grove Garden Resort | Dumaguete, Negros Oriental | forest |
| Taal Lakeview Resort | Tagaytay, Cavite | dusk |

Each has 2 to 4 buildings or areas, 4 room types, 13 to 14 rooms, 2 to 3 pools, add-on services and a price list. All prices are SAMPLES.

## Who can do what (three access levels)
| Level | Sample sign-in | Lands on | Can do |
|---|---|---|---|
| **Super admin** (runs the whole site) | platform@resort.test | Platform overview | Everything: add and edit resorts, create **Admin accounts** for any resort (and more super admins), see every resort's numbers, and open any resort's desk with the switcher. The only level that sees the **Platform** menu |
| **Resort admin** (runs one resort) | manager@coralcove.test, admin@resort.test (Baje's) | That resort's dashboard | Everything for their own resort: reservations, rooms, buildings, pools, room types, services, price list, settings and the resort's staff accounts. Can't see or open other resorts |
| **Staff** (front desk) | desk@coralcove.test, jose@resort.test | That resort's dashboard | Reservations, calendar, guests and room status (for example Cleaning to Available). No prices, settings or staff pages |

Every page checks the level on the server, not just in the menu, and a resort admin can never reach another resort's data. A resort admin can't demote or delete their own account, and the site always keeps at least one super admin.

## Staff sign-in (password for all samples: **admin123**, change them in the Admin accounts or Staff page)
| Who | Email | Level |
|---|---|---|
| Super admin | platform@resort.test | Super admin |
| Baje's Resort | admin@resort.test | Resort admin |
| Baje's Resort | jose@resort.test, ana@resort.test | Staff |
| Coral Cove | manager@coralcove.test / desk@coralcove.test | Resort admin / Staff |
| Pinewood Highlands | manager@pinewood.test / desk@pinewood.test | Resort admin / Staff |
| Sunset Lagoon | manager@sunsetlagoon.test / desk@sunsetlagoon.test | Resort admin / Staff |
| Palm Grove | manager@palmgrove.test / desk@palmgrove.test | Resort admin / Staff |
| Taal Lakeview | manager@taallakeview.test / desk@taallakeview.test | Resort admin / Staff |

Demo guest booking: reference **DEMO2345** with email **liza@example.com** on "Manage booking".

## Adding another resort
1. Sign in as the platform admin (platform@resort.test) and open **Resorts > Add a resort**. Give it a name, place, colour theme, check-in/out times and contact details. A cover photo can be uploaded there too.
2. Use the sidebar switcher to pick the new resort, then add its **Buildings**, **Room types**, **Rooms**, **Pools**, **Services** and **Price list**.
3. Add a staff account for its manager under **Staff** (choose the resort), so they can sign in and run it themselves.
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

## Guest accounts (log in and create account)
- **Create account** (`register.php`): name, email, optional phone and a password (8+ characters). We email a 6-digit code; the account is only created once the code is entered. If the email was used for an earlier booking, the new account is attached to it, so old bookings show up straight away.
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

## Not included
SMS, online card payments. Guests pay at the resort; staff record payments.

## Photos
- Room and cottage photos go in `assets/rooms/<resort-slug>/<room-type-name>-<number>.jpg`, for example `assets/rooms/bajes-resort/deluxe-room-1.jpg` or `assets/rooms/coral-cove/stilt-cottage-1.jpg` (type name in lowercase with dashes, numbered from 1). They play as a slideshow on the room page. Baje's photos are already in `assets/rooms/bajes-resort/`. Resorts with no photos get a colour backdrop in their theme.
- A resort's cover photo is set on the Resorts page (stored in `assets/resorts/`), or by putting a file in `assets/` and typing its name in the resort's hero image.

## Upgrading an older database
Run only what you have not run yet, in this order: `upgrade_cottages_pricelist.sql`, `upgrade_hardening.sql`, `upgrade_email_auth.sql`, `upgrade_companions.sql`, `upgrade_accounts.sql`, then `upgrade_multiresort.sql`. See `AUDIT.md` for the safety changes.

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

- Room photos: upload and remove them under Property > Room photos (they show on the room page and search results).
- Resort "About" text from Settings is shown on the resort's front page.
- Guest address and ID number are shown on the reservation page; ID number is also a column in Guests.
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

## Resort page layout

A resort's front page shows only: the booking card (dates and guests), what guests say, rooms and cottages, and entrance and cottage rates. The header has a round back button that returns to the list of all resorts, and no separate "All resorts" link. Whole-place booking stays one click away under **Whole resort** in the header.
