# Senior review: what was wrong, and what changed

The first version worked, and its booking logic was sound (prepared statements, CSRF tokens, row locking so two guests can't take the same room). The faults were around it: security gaps, a database that trusted the code too much, and a background effect that was heavy on weak devices.

## Security
| Fault | Fix |
|---|---|
| `database/schema.sql` (with staff password hashes) and `README.md` could be downloaded from the browser | `.htaccess` files deny direct access to `.sql`, `.md`, `config.php`, `database/` and `includes/` |
| Folder listings were allowed | `Options -Indexes` |
| Login and booking-lookup limits were stored in the session, so clearing cookies reset them | Failed attempts are now counted per IP in a new `rate_limit` table (session limit kept as a second layer) |
| Every sample staff account uses password `admin123` | A warning banner shows in the staff desk until the password is changed |
| No security headers | Added Content-Security-Policy, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy |
| Session cookie had no strict mode or `Secure` flag | Strict mode on, `Secure` added automatically on HTTPS, session renamed |
| An uncaught database error could print file paths and SQL to guests | One error handler: guests see a plain message, details go to the PHP error log. Set `'debug' => true` in `config.php` on your own computer to see them |

## Money
| Fault | Fix |
|---|---|
| Staff could record a payment larger than the balance, or a refund larger than what was paid | Both are rejected with a clear message |
| Payments could be deleted, which erases the money trail | Payments are now **voided**: they stay on the record, show struck through, and stop counting |
| Same email with a different name silently used the old guest's name | The name given at booking is kept in the booking's special requests |

## Database
| Fault | Fix |
|---|---|
| Statuses were free text, so a typo could hide a reservation or room | CHECK rules on reservation status and source, room status and payment status |
| No negative-value protection on room rates and totals | CHECK rules added |
| No record of when guests, reservations and payments were created | `created_at` columns added |
| Reservation lists filter by status without an index | `idx_res_status` added |
| Public price list had no table | `price_item` table, editable in Staff desk > Price list |
| Room page text had no place to live | `room_type.details` for the full description |

## Performance
| Fault | Fix |
|---|---|
| The blurred background was re-rendered on every scroll frame, which stutters on phones and older laptops | A blurred copy of the photo is a fixed layer that only fades in. Nothing is blurred while scrolling |
| 480 KB and 300 KB background photos | Recompressed (about 15% to 20% smaller), landing image preloaded |
| No compression or caching | Gzip and 30-day image caching in `.htaccess` (only if the Apache modules are on) |

## Design and accessibility
- Added a "Skip to content" link, a page description, a favicon and link-preview tags.
- The landing hero text now has a scrim that fades out at both ends, so there is no visible edge.
- Motion respects the reduced-motion setting; the slideshow does not autoplay for those users.

## Still open (not fixed here)
- **No emails or SMS.** Guests only see their booking on screen. Needs a mail service.
- **No online payments.** Guests pay at the resort by design.
- **Day-use cottages are priced but not bookable online.** Only overnight stays go through the booking system.
- **Photos are matched to rooms by file name** (`assets/rooms/<room-type-name>-<n>.jpg`). Renaming a room type breaks the match until the files are renamed too.
- **Prices, capacities and room descriptions for the new cottages and the price list are samples.**
- **Staff passwords:** the sample accounts must be changed before going live, and the database user `root` with no password should be replaced.
- This code was checked for structure and previewed in a browser, but it was not run against a live PHP/MySQL server in the review environment. Run through the booking flow once before opening to guests.
