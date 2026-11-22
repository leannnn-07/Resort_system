# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Primary — Guests (Zamboanga beachgoers, families, and barkadas):** Planning day-use beach outings, overnight resort stays, or group trips—primarily on phones over everyday Philippine cellular connections. Their job is to check real availability, compare cottages and rooms transparently, and lock in a date via GCash or Maya without waiting on social-media messages.
- **Front-desk staff & resort admins:** Managing daily reservations, walk-in bookings, 14-day room/cottage/pool availability, GCash/Maya receipt verification, and front-desk cash or split balance collection.
- **Super admins:** Overseeing the multi-resort directory, onboarding new resorts, managing platform accounts, and inspecting resort operations and financial records.

## Product Purpose

Vista is a multi-resort booking and operations platform for Zamboanga beach resorts. It replaces manual inquiry-and-confirmation workflows with live cottage and room availability, direct e-wallet downpayment/full-payment submission, and an integrated front-desk verification desk.

Success means two things simultaneously:
1. Guests can discover an available day-use cottage or overnight room and complete a verified booking in minutes on a phone.
2. Resort staff and admins have clear, auditable control over bookings, payment verification, and room/cottage inventory—suitable for both academic/capstone demonstration and real commercial rollout in Zamboanga.

## Positioning

Skip Facebook DM back-and-forth with live day-use cottage and overnight room availability across Zamboanga resorts, paired with direct GCash/Maya payment verification and resort front-desk settlement—built specifically around how Philippine beach resorts operate (single cottages with per-guest entrance fees, flexible downpayment vs. full payment, and multi-stay trip checkout).

## Operating Context

- **Guest booking context:** Mobile-first browsing over everyday Philippine cellular connections; guests want fast load times, distraction-free checkout steps (`1 Your details → 2 Payment → 3 Confirmed`), and printable/retrievable booking references with the resort's exact address for arrival.
- **Payment & verification ritual:** Guests transfer via GCash or Maya to the resort's account, submit their reference number and receipt screenshot, and wait for resort staff verification. Partial payments stay `Pending` (`Downpayment paid`); full approval (`Confirmed`) requires full payment, with remaining balances collectible online or at the front desk via Cash, GCash, Maya, or split payment—every approval stamped with the verifying staff member's name, email, and timestamp.
- **Dual-purpose deployment context:** Must demonstrate clean, complete end-to-end flows for academic/capstone panel review while remaining practical for real commercial rollout across Zamboanga resorts and walk-in front desks.

## Capabilities and Constraints

- **Public guest flows:** Editorial showcase homepage (`index.php`) feeding into login/sign-up (`login.php`, `register.php`) and the resorts hub (`resorts.php`); per-resort landing, photo gallery, cottages & rooms detail view (`room.php`), price list, whole-place rentals, and verified reviews; single-stay and multi-stay trip booking (`checkout.php`, `trip_checkout.php`, `payment.php`, `booking.php`, `trip_booked.php`); self-service account hub (`account.php`) with 5-booking summary, expandable search, and bidirectional Staff/Admin ↔ User account switching.
- **Staff, resort-admin, and super-admin flows:** Live dashboard metrics, reservations list with quick room/cottage booking search, staff new-reservation wizard (supporting day-use cottages, overnight rooms, and whole-place rentals), 14-day calendar covering rooms, cottages, and pools, payment verification and balance/split collection with audit trail, guest and resort management CRUD, reviews, records, archive, and settings.
- **Strict data minimization:** Never ask guests for information the resort does not act on (e.g., no guest home address during booking or profile edits).
- **Inverted-pyramid data conventions:** Always order names as `Lastname, Firstname` and addresses from broadest to most specific (`City, Barangay, Street / Drive, House / Lot no.`).
- **Progressive resort address disclosure:** Resort cards show only `City, Barangay`; full complete addresses (`City, Barangay, Street / Drive, House / Lot no.`) are required from every resort and revealed inside the resort page (below the tagline), booking/payment summaries, and confirmation views.
- **Single guest count input:** Use one total `Guests` field rather than separate Adults/Children selectors.
- **Language:** English only (no localization requirement currently planned).
- **Runtime constraints:** Pure PHP 8.1+ and MariaDB on XAMPP with no Composer or frontend build pipeline.

## Brand Commitments

- **Brand name:** `Vista` (platform directory name in `config.php`; individual resorts display their own resort name when browsing inside a resort).
- **Voice:** Concise, clear, direct, and scannable—avoid long paragraphs in UI copy.

## Evidence on Hand

- **Live database inventory (sample/demo data):** Active Zamboanga resort records, room types, rooms, day-use cottages, entrance fees, add-ons, and published reviews in `resort_db`—explicitly treated as sample/demo data for now, not verified commercial partner endorsements.
- **Local photography assets:** Resort cover photos in `assets/resorts/` (e.g., `vista-del-mar.jpg`, `jeers-family-resort.jpg`) and uploaded gallery images in `uploads/gallery/`.
- **Absences not to fabricate:** Do not invent real partner press logos, third-party awards, commercial customer counts, or SLA/uptime guarantees beyond what the database actually holds.

## Product Principles

1. **Answer availability before asking for effort:** Show open cottages, rooms, capacity, and real totals (including entrance fees) upfront before requiring checkout input.
2. **Collect only what the stay requires:** Eliminate unnecessary form fields and navigation chrome during focused booking, payment, and auth tasks.
3. **Preserve user control across every step:** Keep left-aligned back actions and reversible step navigation so guests can correct details without losing their slot or creating duplicate bookings.
4. **Match real Philippine resort operations:** Treat day-use cottages, GCash/Maya receipt verification, downpayment tracking, and front-desk split/cash collection as first-class workflows—not afterthoughts to overnight hotels.
5. **Maintain strict financial and data clarity:** Enforce full-payment approval rules, permanent staff verification audit trails, and consistent inverted-pyramid formatting across guest and staff surfaces.
