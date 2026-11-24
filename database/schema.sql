-- Resort Booking Platform: many resorts, one booking system
-- Works with MySQL 8.0.16+ and MariaDB 10.2.1+
-- Import with:  mysql -u root -p < database/schema.sql      (this RESETS all data)

CREATE DATABASE IF NOT EXISTS resort_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE resort_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS staff_notification_read, staff_notification, resort_photo, review_media, review, password_reset, reservation_companion, payment, reservation_service, reservation_cottage, reservation_pool, reservation_room,
                     reservation, rate_limit, price_item, service, room, room_type, pool, building, staff, guest, resort;
SET FOREIGN_KEY_CHECKS = 1;

-- One row per resort. Everything below (rooms, buildings, pools, services, prices, staff, bookings) belongs to one resort.
CREATE TABLE resort (
  resort_id       INT AUTO_INCREMENT PRIMARY KEY,
  slug            VARCHAR(40)  NOT NULL,              -- used in web addresses: landing.php?r=azzura-beach
  name            VARCHAR(100) NOT NULL,
  tagline         VARCHAR(160) NULL,
  description     TEXT NULL,
  location        VARCHAR(120) NULL,
  theme           VARCHAR(20)  NOT NULL DEFAULT 'ocean',   -- colour theme: ocean, lagoon, pine, sunset, forest, dusk
  hero_image      VARCHAR(120) NULL,                  -- optional photo inside assets/, for example resorts/azzura-beach.jpg
  contact_email   VARCHAR(120) NULL,
  contact_phone   VARCHAR(40)  NULL,
  checkin_time    VARCHAR(20)  NOT NULL DEFAULT '2:00 PM',
  checkout_time   VARCHAR(20)  NOT NULL DEFAULT '12:00 NN',
  free_cancel_days INT NOT NULL DEFAULT 2,
  allow_cancellation TINYINT(1) NOT NULL DEFAULT 1,   -- 1 = guests may cancel online up to free_cancel_days before check-in; 0 = not online (contact the resort)
  online_payment_enabled TINYINT(1) NOT NULL DEFAULT 1, -- 1 = accept GCash & Maya payments on the site
  downpayment_percent TINYINT UNSIGNED NOT NULL DEFAULT 50, -- 0..100 required downpayment % (0 = pay at resort, 50 = 50% downpayment, 100 = full upfront)
  gcash_name      VARCHAR(100) NULL,                  -- resort's GCash account name
  gcash_number    VARCHAR(30)  NULL,                  -- resort's GCash mobile number
  maya_name       VARCHAR(100) NULL,                  -- resort's Maya account name
  maya_number     VARCHAR(30)  NULL,                  -- resort's Maya mobile number
  exclusive_rate  DECIMAL(10,2) NULL,                 -- price per night to book the ENTIRE resort; empty = not offered
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      INT NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_resort_slug UNIQUE (slug),
  CONSTRAINT ck_resort_rate CHECK (exclusive_rate IS NULL OR exclusive_rate >= 0)
) ENGINE=InnoDB;

CREATE TABLE guest (
  guest_id    INT AUTO_INCREMENT PRIMARY KEY,
  first_name  VARCHAR(60)  NOT NULL,
  middle_name VARCHAR(60)  NULL,
  last_name   VARCHAR(60)  NOT NULL,
  email       VARCHAR(120) NOT NULL,
  phone      VARCHAR(30),
  address    VARCHAR(200),
  id_type    VARCHAR(40) NULL,      -- Passport, Driver’s licence, ... or 'None presented'
  id_number  VARCHAR(255) NULL,     -- stored encrypted by the app (see includes/secure_id.php)
  password_hash     VARCHAR(255) NULL,   -- set only when the guest has created an online account
  email_verified_at DATETIME NULL,       -- when they proved the email with a code
  login_enabled TINYINT(1) NOT NULL DEFAULT 1,   -- 0 = sign-in switched off
  archived_at   DATETIME NULL,                   -- set when the account is removed: it moves to the archive and can be restored
  created_resort_id INT NULL,            -- the resort whose staff added this guest by hand (NULL = booked or registered online)
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_guest_email UNIQUE (email),
  CONSTRAINT fk_guest_resort FOREIGN KEY (created_resort_id) REFERENCES resort(resort_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- resort_id NULL = platform administrator (can manage every resort and add new ones).
CREATE TABLE staff (
  staff_id      INT AUTO_INCREMENT PRIMARY KEY,
  resort_id     INT NULL,
  full_name     VARCHAR(120) NOT NULL,
  role          VARCHAR(60)  NOT NULL,                -- job title shown to people, for example 'Front desk'
  access        VARCHAR(10)  NOT NULL DEFAULT 'Staff', -- 'Admin' = runs the resort, 'Staff' = desk work. resort_id NULL = super admin
  email         VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NULL,
  login_enabled TINYINT(1) NOT NULL DEFAULT 1,   -- 0 = sign-in switched off (the account is kept)
  archived_at   DATETIME NULL,                   -- set when the account is deleted: it moves to the archive and can be restored
  CONSTRAINT uk_staff_email UNIQUE (email),
  CONSTRAINT ck_staff_access CHECK (access IN ('Admin','Staff')),
  CONSTRAINT fk_staff_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A building (or wing, row of cottages...) inside a resort. Can be booked as a whole when exclusive_rate is set.
CREATE TABLE building (
  building_id    INT AUTO_INCREMENT PRIMARY KEY,
  resort_id      INT NOT NULL,
  name           VARCHAR(80)  NOT NULL,
  description    VARCHAR(300) NULL,
  exclusive_rate DECIMAL(10,2) NULL,        -- price per night to book the ENTIRE building; empty = not offered
  CONSTRAINT uk_building_name UNIQUE (resort_id, name),
  CONSTRAINT ck_building_rate CHECK (exclusive_rate IS NULL OR exclusive_rate >= 0),
  CONSTRAINT fk_building_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A swimming pool. Can be booked for private use by the day when rate_per_day is set.
CREATE TABLE pool (
  pool_id      INT AUTO_INCREMENT PRIMARY KEY,
  resort_id    INT NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  description  VARCHAR(300) NULL,
  max_guests   INT NOT NULL DEFAULT 20,
  rate_per_day DECIMAL(10,2) NULL,          -- price per day for private use of the pool; empty = not offered
  CONSTRAINT uk_pool_name UNIQUE (resort_id, name),
  CONSTRAINT ck_pool_guests CHECK (max_guests > 0),
  CONSTRAINT ck_pool_rate CHECK (rate_per_day IS NULL OR rate_per_day >= 0),
  CONSTRAINT fk_pool_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_type (
  room_type_id  INT AUTO_INCREMENT PRIMARY KEY,
  resort_id     INT NOT NULL,
  type_name     VARCHAR(60) NOT NULL,
  description   TEXT,
  details       TEXT NULL,
  max_occupancy INT NOT NULL,
  base_rate     DECIMAL(10,2) NOT NULL,
  CONSTRAINT uk_room_type_name UNIQUE (resort_id, type_name),
  CONSTRAINT ck_room_type_occ  CHECK (max_occupancy > 0),
  CONSTRAINT ck_room_type_rate CHECK (base_rate >= 0),
  CONSTRAINT fk_room_type_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room (
  room_id      INT AUTO_INCREMENT PRIMARY KEY,
  resort_id    INT NOT NULL,
  building_id  INT NOT NULL,
  room_type_id INT NOT NULL,
  room_number  VARCHAR(10) NOT NULL,
  floor        INT NOT NULL DEFAULT 1,
  status       VARCHAR(20) NOT NULL DEFAULT 'Available',
  CONSTRAINT uk_room_number UNIQUE (resort_id, room_number),
  CONSTRAINT ck_room_status CHECK (status IN ('Available','Cleaning','Maintenance')),
  CONSTRAINT fk_room_resort   FOREIGN KEY (resort_id)    REFERENCES resort(resort_id) ON DELETE CASCADE,
  CONSTRAINT fk_room_building FOREIGN KEY (building_id)  REFERENCES building(building_id),
  CONSTRAINT fk_room_type     FOREIGN KEY (room_type_id) REFERENCES room_type(room_type_id)
) ENGINE=InnoDB;

CREATE TABLE service (
  service_id   INT AUTO_INCREMENT PRIMARY KEY,
  resort_id    INT NOT NULL,
  service_name VARCHAR(80) NOT NULL,
  category     VARCHAR(40),
  unit_price   DECIMAL(10,2) NOT NULL,
  CONSTRAINT ck_service_price CHECK (unit_price >= 0),
  CONSTRAINT fk_service_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Public price list (entrance fees and day-use cottages). Edited in Staff desk > Price list.
CREATE TABLE price_item (
  price_item_id INT AUTO_INCREMENT PRIMARY KEY,
  resort_id     INT NOT NULL,
  category      VARCHAR(40)  NOT NULL,
  item_name     VARCHAR(80)  NOT NULL,
  note          VARCHAR(160) NULL,
  unit          VARCHAR(40)  NOT NULL DEFAULT 'per person',
  price         DECIMAL(10,2) NOT NULL,
  max_guests    INT NOT NULL DEFAULT 15,              -- capacity for day-use cottages
  quantity_available INT NOT NULL DEFAULT 5,          -- how many units of this cottage exist per day
  sort_order    INT NOT NULL DEFAULT 0,
  CONSTRAINT ck_price_item_price CHECK (price >= 0),
  CONSTRAINT fk_price_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One-time staff password reset links (only a hash of the token is stored; the link itself goes by email).
CREATE TABLE password_reset (
  reset_id   INT AUTO_INCREMENT PRIMARY KEY,
  staff_id   INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_reset_token UNIQUE (token_hash),
  CONSTRAINT fk_reset_staff FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Failed sign-in and booking-lookup attempts, so guessing is slowed down even if cookies are cleared.
CREATE TABLE rate_limit (
  rate_limit_id INT AUTO_INCREMENT PRIMARY KEY,
  scope         VARCHAR(20) NOT NULL,
  ip            VARCHAR(45) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rate_lookup (scope, ip, created_at)
) ENGINE=InnoDB;

-- booking_scope: 'Rooms' (normal), 'Building' (a whole building), 'Pool' (private use of a pool) or 'Resort' (the entire resort).
-- Whole-building and whole-resort bookings also list every room they take in reservation_room, so those rooms
-- are blocked for everyone else. Pools taken are listed in reservation_pool.
CREATE TABLE reservation (
  reservation_id   INT AUTO_INCREMENT PRIMARY KEY,
  resort_id        INT NOT NULL,
  booking_ref      VARCHAR(10) NOT NULL,
  guest_id         INT NOT NULL,
  staff_id         INT NULL,
  booking_scope    VARCHAR(10) NOT NULL DEFAULT 'Rooms',
  building_id      INT NULL,
  booking_date     DATE NOT NULL,
  check_in_date    DATE NOT NULL,
  check_out_date   DATE NOT NULL,
  num_adults       INT NOT NULL DEFAULT 1,
  num_children     INT NOT NULL DEFAULT 0,
  num_guests       INT GENERATED ALWAYS AS (num_adults + num_children) STORED,
  booker_type      VARCHAR(10) NOT NULL DEFAULT 'Adult',   -- the person who booked: 'Adult' or 'Senior'
  special_requests VARCHAR(500) NULL,
  source           VARCHAR(10) NOT NULL DEFAULT 'Staff',
  status           VARCHAR(20) NOT NULL DEFAULT 'Pending',
  total_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  required_downpayment DECIMAL(12,2) NOT NULL DEFAULT 0, -- snapshot of required downpayment when booked
  trip_code        VARCHAR(10) NULL,   -- stays booked together in one checkout (possibly at different resorts) share a trip code
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_res_ref UNIQUE (booking_ref),
  CONSTRAINT fk_res_resort   FOREIGN KEY (resort_id) REFERENCES resort(resort_id),
  CONSTRAINT fk_res_guest    FOREIGN KEY (guest_id) REFERENCES guest(guest_id),
  CONSTRAINT fk_res_staff    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE SET NULL,
  CONSTRAINT fk_res_building FOREIGN KEY (building_id) REFERENCES building(building_id) ON DELETE SET NULL,
  CONSTRAINT ck_res_dates    CHECK (check_out_date > check_in_date),
  CONSTRAINT ck_res_adults   CHECK (num_adults >= 1),
  CONSTRAINT ck_res_children CHECK (num_children >= 0),
  CONSTRAINT ck_res_status   CHECK (status IN ('Pending','Confirmed','Checked-in','Checked-out','Cancelled')),
  CONSTRAINT ck_res_source   CHECK (source IN ('Online','Staff')),
  CONSTRAINT ck_res_scope    CHECK (booking_scope IN ('Rooms','Cottage','Building','Pool','Resort')),
  CONSTRAINT ck_res_total    CHECK (total_amount >= 0),
  INDEX idx_res_dates (check_in_date, check_out_date),
  INDEX idx_res_status (status),
  INDEX idx_res_resort (resort_id),
  INDEX idx_res_trip (trip_code)
) ENGINE=InnoDB;

CREATE TABLE reservation_room (
  reservation_id INT NOT NULL,
  room_id        INT NOT NULL,
  rate_applied   DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (reservation_id, room_id),
  CONSTRAINT ck_rr_rate CHECK (rate_applied >= 0),
  CONSTRAINT fk_rr_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_rr_room FOREIGN KEY (room_id) REFERENCES room(room_id)
) ENGINE=InnoDB;

-- Pools taken by a reservation (private pool use, or part of an entire-resort booking). rate_applied is per day.
CREATE TABLE reservation_pool (
  reservation_id INT NOT NULL,
  pool_id        INT NOT NULL,
  rate_applied   DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (reservation_id, pool_id),
  CONSTRAINT ck_rp_rate CHECK (rate_applied >= 0),
  CONSTRAINT fk_rp_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_pool FOREIGN KEY (pool_id) REFERENCES pool(pool_id)
) ENGINE=InnoDB;

CREATE TABLE reservation_cottage (
  reservation_id INT NOT NULL,
  price_item_id  INT NOT NULL,
  cottage_name   VARCHAR(80) NOT NULL,
  cottage_rate   DECIMAL(10,2) NOT NULL,
  entrance_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  quantity       INT NOT NULL DEFAULT 1,
  PRIMARY KEY (reservation_id, price_item_id),
  CONSTRAINT fk_rcot_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_rcot_item FOREIGN KEY (price_item_id)  REFERENCES price_item(price_item_id)  ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE reservation_service (
  reservation_id INT NOT NULL,
  service_id     INT NOT NULL,
  service_date   DATE NOT NULL,
  quantity       INT NOT NULL DEFAULT 1,
  price_charged  DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (reservation_id, service_id, service_date),
  CONSTRAINT fk_rs_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_serv FOREIGN KEY (service_id) REFERENCES service(service_id),
  CONSTRAINT ck_rs_qty  CHECK (quantity > 0)
) ENGINE=InnoDB;

-- The other people staying on a reservation (the booker is in guest; these are the rest of the party).
CREATE TABLE reservation_companion (
  companion_id   INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  guest_type     VARCHAR(10) NOT NULL,
  first_name     VARCHAR(60) NOT NULL,
  middle_name    VARCHAR(60) NULL,
  last_name      VARCHAR(60) NOT NULL,
  age            INT NULL,                       -- no longer asked; kept only so old bookings keep their value
  CONSTRAINT ck_rc_type CHECK (guest_type IN ('Adult','Senior','Child')),
  CONSTRAINT ck_rc_age  CHECK (age IS NULL OR age BETWEEN 0 AND 120),
  CONSTRAINT fk_rc_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  INDEX idx_rc_res (reservation_id)
) ENGINE=InnoDB;

CREATE TABLE payment (
  payment_id           INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id       INT NOT NULL,
  payment_date         DATE NOT NULL,
  amount               DECIMAL(12,2) NOT NULL,
  method               VARCHAR(30) NOT NULL,
  payment_status       VARCHAR(20) NOT NULL DEFAULT 'Paid',
  reference_no         VARCHAR(80) NULL,                      -- GCash / Maya reference number submitted by guest or staff
  sender_name          VARCHAR(100) NULL,                     -- optional sender name/mobile for faster verification
  proof_image          VARCHAR(255) NULL,                     -- required payment screenshot path inside uploads/payments/
  source               VARCHAR(20) NOT NULL DEFAULT 'Staff',  -- 'Guest' (submitted online) or 'Staff' (recorded at desk)
  verified_at          DATETIME NULL,                         -- when staff verified or recorded the payment
  verified_by_staff_id INT NULL,                              -- staff member who verified or rejected the payment
  approved_by_name     VARCHAR(120) NULL,                     -- snapshot of approving staff's name
  approved_by_email    VARCHAR(120) NULL,                     -- snapshot of approving staff's email
  verification_note    VARCHAR(255) NULL,                     -- optional staff note (e.g. rejection reason)
  recorded_by_staff_id INT NULL,                              -- staff member who recorded a front-desk payment
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_res        FOREIGN KEY (reservation_id)       REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_verified   FOREIGN KEY (verified_by_staff_id) REFERENCES staff(staff_id)             ON DELETE SET NULL,
  CONSTRAINT fk_pay_recorded   FOREIGN KEY (recorded_by_staff_id) REFERENCES staff(staff_id)             ON DELETE SET NULL,
  CONSTRAINT ck_pay_amount CHECK (amount > 0),
  CONSTRAINT ck_pay_status CHECK (payment_status IN ('Paid','Pending','Refunded','Voided')),
  INDEX idx_pay_ref (method, reference_no)
) ENGINE=InnoDB;

CREATE TABLE staff_notification (
  notif_id       INT AUTO_INCREMENT PRIMARY KEY,
  resort_id      INT NOT NULL,
  reservation_id INT NULL,
  kind           VARCHAR(30) NOT NULL,   -- 'booking', 'payment', 'cancelled'
  title          VARCHAR(120) NOT NULL,
  body           VARCHAR(220) NOT NULL,
  link           VARCHAR(200) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sn_resort (resort_id, created_at),
  INDEX idx_sn_res (reservation_id),
  CONSTRAINT fk_sn_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE,
  CONSTRAINT fk_sn_res    FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE staff_notification_read (
  staff_id INT NOT NULL,
  notif_id INT NOT NULL,
  read_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (staff_id, notif_id),
  CONSTRAINT fk_snr_staff FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
  CONSTRAINT fk_snr_notif FOREIGN KEY (notif_id) REFERENCES staff_notification(notif_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Sample data: 5 resorts (all staff passwords: admin123, change them in Admin > Staff) ----------

-- Guest reviews: one rating (1 to 5 stars) and an optional comment per finished stay. The resort can reply or hide a review.
CREATE TABLE review (
  review_id      INT AUTO_INCREMENT PRIMARY KEY,
  resort_id      INT NOT NULL,
  reservation_id INT NULL,
  guest_id       INT NULL,
  author         VARCHAR(80) NOT NULL,
  rating         TINYINT NOT NULL,
  title          VARCHAR(100) NULL,
  comment        TEXT NULL,
  status         ENUM('Published','Hidden') NOT NULL DEFAULT 'Published',
  reply          TEXT NULL,
  replied_at     DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT uk_review_reservation UNIQUE (reservation_id),
  CONSTRAINT ck_review_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT fk_review_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE,
  CONSTRAINT fk_review_res FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE SET NULL,
  CONSTRAINT fk_review_guest FOREIGN KEY (guest_id) REFERENCES guest(guest_id) ON DELETE SET NULL,
  INDEX idx_review_resort (resort_id, status, created_at)
) ENGINE=InnoDB;

-- Photos and videos guests attach to a review (the files live in uploads/reviews/).
CREATE TABLE review_media (
  media_id    INT AUTO_INCREMENT PRIMARY KEY,
  review_id   INT NOT NULL,
  kind        ENUM('photo','video') NOT NULL,
  file_name   VARCHAR(60) NOT NULL,          -- random name inside uploads/reviews/<review_id>/
  thumb_name  VARCHAR(60) NULL,              -- small preview of a photo (made when the server can), shown in lists
  mime_type   VARCHAR(40) NOT NULL,
  file_size   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_media_review FOREIGN KEY (review_id) REFERENCES review(review_id) ON DELETE CASCADE,
  INDEX idx_media_review (review_id, media_id)
) ENGINE=InnoDB;

-- The resort's own photo gallery (the "See photos" page). Only resort admins and the super admin upload these (files in uploads/gallery/).
CREATE TABLE resort_photo (
  photo_id    INT AUTO_INCREMENT PRIMARY KEY,
  resort_id   INT NOT NULL,
  file_name   VARCHAR(60) NOT NULL,          -- random name inside uploads/gallery/<resort_id>/
  thumb_name  VARCHAR(60) NULL,              -- small preview (made when the server can)
  caption     VARCHAR(120) NULL,
  sort_order  INT NOT NULL DEFAULT 0,        -- lowest first
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_resort_photo_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE,
  INDEX idx_resort_photo (resort_id, sort_order, photo_id)
) ENGINE=InnoDB;

-- ===== Vista del Mar Resort =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (1, 'vista-del-mar', 'Vista del Mar Resort', 'Casitas by the sea, treehouses, and curacha at sunset.', 'A well-known beach resort in Zamboanga City with a view of the Sulu Sea and distant islands. Stay in a casita by the water or a treehouse among the greenery, and end the day with fresh seafood and Zamboanga dishes at the alfresco restaurant while the sun goes down.', 'Zamboanga City', 'sunset', NULL, 'reservations@resort.test', '+63 900 000 0000', '2:00 PM', '12:00 NN', 2, 54000, 1);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (1, 'Casita Row', 'Casitas right by the sea.', 36500),
 (1, 'Treehouse Grove', 'Treehouses surrounded by trees and greenery.', 17500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (1, 'Sea Casita', 'An air-conditioned casita by the sea, with a hot and cold shower and cable TV.', 'A casita right by the sea. Air-conditioned, with a hot and cold shower, cable TV and a mini refrigerator, and a porch for watching the water.', 2, 4500),
 (1, 'Family Casita', 'A bigger casita by the sea with room for the whole family.', 'A larger casita by the sea with extra beds, air conditioning, hot and cold shower, cable TV and a mini refrigerator. Room for a family of up to five.', 5, 6800),
 (1, 'Treehouse', 'A treehouse among the trees, with air conditioning and a quiet, green view.', 'A treehouse set among the trees and greenery. Air-conditioned, with a hot and cold shower, cable TV and a mini refrigerator, and about as peaceful as a stay gets.', 2, 5200);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Casita Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Sea Casita'), 'C1', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Casita Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Sea Casita'), 'C2', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Casita Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Sea Casita'), 'C3', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Casita Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Sea Casita'), 'C4', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Casita Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Sea Casita'), 'C5', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Casita Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Family Casita'), 'FC1', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Casita Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Family Casita'), 'FC2', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Casita Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Family Casita'), 'FC3', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Treehouse'), 'T1', 2, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Treehouse'), 'T2', 2, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Treehouse'), 'T3', 2, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Treehouse'), 'T4', 2, 'Available');
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (1, 'Curacha dinner set (2 persons)', 'Dining', 1800),
 (1, 'Seafood dinner at the alfresco restaurant (2 persons)', 'Dining', 2200),
 (1, 'Great Santa Cruz Island (Pink Beach) trip', 'Activity', 2500),
 (1, 'Airport pickup (Zamboanga Intl)', 'Transport', 1200),
 (1, 'Breakfast', 'Dining', 350);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (1, 'Entrance fee', 'Adult', NULL, 'per person', 100, 1),
 (1, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 50, 2),
 (1, 'Cottage (day use)', 'Beach Cottage', 'Daytime use, seats 8', 'per cottage', 1000, 1),
 (1, 'Cottage (day use)', 'Seaside Table', 'Daytime use, seats 4', 'per table', 600, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (1, 'Maria Santos', 'Resort admin', 'Admin', 'admin@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (1, 'Jose Reyes', 'Reservations', 'Staff', 'jose@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (1, 'Ana Cruz', 'Concierge', 'Staff', 'ana@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Azzura Beach Resort and Hotel =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (2, 'azzura-beach', 'Azzura Beach Resort and Hotel', 'Soft brown sand, a pool, and kayaks, twenty minutes from the city.', 'A casual beach getaway just outside Zamboanga City. Spend the day on the soft brown sand, paddle a kayak, ride an ATV along the shore or cool off in the pool, then stay over in a budget room, a family room, a barkada room or a villa for the whole group.', 'Zamboanga City', 'lagoon', NULL, 'reservations@azzura.test', '+63 900 000 0102', '2:00 PM', '12:00 NN', 2, 61500, 2);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (2, 'Beach Row', 'Budget, standard and family rooms a few steps from the beach.', 25000),
 (2, 'Barkada Hall', 'Big shared rooms for friends travelling together.', 13000),
 (2, 'Villas', 'Beachside villas for groups.', 24000);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (2, 'Budget Room', 'Simple fan or air-con room with a double bed. The cheapest way to wake up at the beach.', 'A simple room with a double bed and a private bathroom. It is the cheapest way to sleep next to the beach, and a good fit for couples and solo travellers who plan to spend the day outside.', 2, 1800),
 (2, 'Standard Room', 'Air-conditioned room with a queen bed and a bathroom with hot and cold shower.', 'An air-conditioned room with a queen bed, a bathroom with hot and cold shower and a small table outside. A comfortable base between swims.', 2, 3200),
 (2, 'Family Room', 'Air-conditioned room with two beds, made for parents and kids.', 'Two beds and enough floor space for a small family. Air-conditioned, with its own bathroom, a short walk from the pool and the beach.', 4, 4800),
 (2, 'Barkada Room', 'A big room with several beds for a group of friends.', 'Barkada means a group of friends, and this room is built for one. Several beds in one air-conditioned room, so everybody can share the cost and stay together.\n\nPair it with a day cottage on the beach for a very affordable group trip.', 8, 7500),
 (2, 'Beach Villa', 'A villa for a big group, with a living area and room for the whole barkada.', 'A villa for large groups and family gatherings. Multiple beds, a living area and a terrace, set close to the sand.\n\nIt sleeps up to ten.', 10, 14000);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Budget Room'), 'B1', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Budget Room'), 'B2', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Standard Room'), 'S1', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Standard Room'), 'S2', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Standard Room'), 'S3', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Standard Room'), 'S4', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Standard Room'), 'S5', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Family Room'), 'F1', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Beach Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Family Room'), 'F2', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Barkada Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Barkada Room'), 'K1', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Barkada Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Barkada Room'), 'K2', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Villas'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Beach Villa'), 'V1', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Villas'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Beach Villa'), 'V2', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (2, 'Beachside Pool', 'A swimming pool next to the beach, with a shallow end for children.', 50, 9000);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (2, 'Great Santa Cruz Island (Pink Beach) trip', 'Activity', 2500),
 (2, 'Kayak rental (1 hour)', 'Activity', 300),
 (2, 'ATV beach ride (15 min)', 'Activity', 600),
 (2, 'Airport pickup (Zamboanga Intl)', 'Transport', 1200),
 (2, 'Breakfast', 'Dining', 350),
 (2, 'Umbrella rental', 'Activity', 300);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (2, 'Entrance fee', 'Adult', NULL, 'per person', 150, 1),
 (2, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 100, 2),
 (2, 'Cottage (day use)', 'Beach Umbrella', 'Daytime use', 'per umbrella', 300, 1),
 (2, 'Cottage (day use)', 'Cottage', 'Daytime use, seats up to 8', 'per cottage', 1200, 2),
 (2, 'Cottage (day use)', 'Gazebo', 'Daytime use, seats up to 20', 'per gazebo', 2800, 3);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (2, 'Teresa Villamor', 'Resort admin', 'Admin', 'manager@azzura.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (2, 'Noel Abella', 'Front desk', 'Staff', 'desk@azzura.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Santiago's Resort =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (3, 'santiagos-resort', 'Santiago''s Resort', 'An easy family getaway with a pool for every age.', 'An affordable resort a few minutes'' drive from Zamboanga City, made for families and groups. Swim in the adult or kids'' pool, rent a cottage for the day, book a function hall with videoke, or stay overnight in a standard or barkada room.', 'Zamboanga City', 'dusk', NULL, 'reservations@santiagos.test', '+63 900 000 0103', '2:00 PM', '12:00 NN', 2, 33500, 3);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (3, 'Pool Block', 'Standard rooms facing the pools.', 15000),
 (3, 'Barkada Block', 'Group rooms for friends and families.', 18500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (3, 'Standard Room', 'Clean, air-conditioned room with a double bed, a few steps from the pools.', 'A clean, air-conditioned room with a double bed and a private bathroom, a few steps from the pools. Good value for a quick overnight stay near the city.', 3, 2200),
 (3, 'Barkada Room', 'A large room with multiple beds for a group of friends or a big family.', 'A large room with several beds for a group of friends or a big family. Share the cost, share the room, and spend the evening at the function hall or the billiard table.', 8, 5500);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Pool Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Standard Room'), 'P1', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Pool Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Standard Room'), 'P2', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Pool Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Standard Room'), 'P3', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Pool Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Standard Room'), 'P4', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Pool Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Standard Room'), 'P5', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Pool Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Standard Room'), 'P6', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Pool Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Standard Room'), 'P7', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Pool Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Standard Room'), 'P8', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Barkada Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Barkada Room'), 'K1', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Barkada Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Barkada Room'), 'K2', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Barkada Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Barkada Room'), 'K3', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Barkada Block'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Barkada Room'), 'K4', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (3, 'Adult Pool', 'The main swimming pool for adults and teens.', 40, 7000),
 (3, 'Kids Pool', 'A shallow pool for young children.', 20, 2500);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (3, 'Function hall with videoke (4 hours)', 'Activity', 1500),
 (3, 'Extra bed', 'Room', 500),
 (3, 'Breakfast', 'Dining', 250),
 (3, 'Airport pickup (Zamboanga Intl)', 'Transport', 1000),
 (3, 'Late checkout', 'Room', 500);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (3, 'Entrance fee', 'Adult', NULL, 'per person', 100, 1),
 (3, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 70, 2),
 (3, 'Cottage (day use)', 'Small Cottage', 'Daytime use, seats 6', 'per cottage', 800, 1),
 (3, 'Cottage (day use)', 'Big Cottage', 'Daytime use, seats 12', 'per cottage', 1500, 2),
 (3, 'Cottage (day use)', 'Function Hall (with videoke)', 'Daytime use, seats up to 80', 'per hall', 6000, 3);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (3, 'Carlos Bautista', 'Resort admin', 'Admin', 'manager@santiagos.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (3, 'Mia Dalisay', 'Front desk', 'Staff', 'desk@santiagos.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Hannah's Nature Camp =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (4, 'hannahs-nature-camp', 'Hannah''s Nature Camp', 'Spring-fed pools, mountain air and a sky full of stars.', 'An eco-friendly mountain resort away from the city lights. Stay in a rustic cottage, some with their own spring-fed mini pool, cool off in the spring water, have a bite at the alfresco cafe and stay up for a bonfire and stargazing.', 'Zamboanga City', 'pine', NULL, 'reservations@hannahscamp.test', '+63 900 000 0104', '2:00 PM', '11:00 AM', 3, 37000, 4);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (4, 'Camp Ridge', 'Rustic cottages along the ridge with mountain views.', 20500),
 (4, 'Spring Cottages', 'Cottages with their own spring-fed mini pools.', 16500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (4, 'Rustic Cottage', 'A simple wooden cottage with a double bed and a mountain view.', 'A simple wooden cottage with a double bed, a small porch and a mountain view. Fall asleep to the sound of the forest.', 3, 2800),
 (4, 'Spring Pool Cottage', 'A cottage with its own spring-fed mini pool, steps from the door.', 'Your own cool, spring-fed mini pool, a few steps from the cottage door. Soak in the morning, read in the afternoon, and look up at the stars at night.\n\nBest for couples and small families, and very popular on weekends.', 4, 4800),
 (4, 'Family Cottage', 'A larger cottage for families and small groups, with a covered terrace.', 'A larger cottage with room for a family or a small group, and a covered terrace for meals and games. A good base for a weekend of bonfires and stargazing.', 6, 6500);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Camp Ridge'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Rustic Cottage'), 'R1', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Camp Ridge'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Rustic Cottage'), 'R2', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Camp Ridge'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Rustic Cottage'), 'R3', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Camp Ridge'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Rustic Cottage'), 'R4', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Spring Cottages'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Spring Pool Cottage'), 'SP1', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Spring Cottages'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Spring Pool Cottage'), 'SP2', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Spring Cottages'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Spring Pool Cottage'), 'SP3', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Spring Cottages'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Spring Pool Cottage'), 'SP4', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Camp Ridge'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Family Cottage'), 'FC1', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Camp Ridge'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Family Cottage'), 'FC2', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (4, 'Spring Pool', 'A spring-fed swimming pool with cool mountain water.', 25, 4000);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (4, 'Bonfire and s''mores kit', 'Activity', 500),
 (4, 'Stargazing night walk', 'Activity', 400),
 (4, 'Breakfast at the alfresco cafe', 'Dining', 300),
 (4, 'Shuttle from Zamboanga City', 'Transport', 1500),
 (4, 'Firewood bundle', 'Activity', 250);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (4, 'Entrance fee', 'Adult', NULL, 'per person', 100, 1),
 (4, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 50, 2),
 (4, 'Cottage (day use)', 'Picnic Hut', 'Daytime use, seats 6', 'per hut', 1000, 1),
 (4, 'Cottage (day use)', 'Spring Pool Cottage (day use)', 'Daytime use, up to 4 guests', 'per cottage', 2500, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (4, 'Hannah Reyes', 'Resort admin', 'Admin', 'manager@hannahscamp.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (4, 'Dan Flores', 'Front desk', 'Staff', 'desk@hannahscamp.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Dakak Park and Beach Resort =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (5, 'dakak-beach', 'Dakak Park and Beach Resort', 'A forest beach resort with reefs, trails and a long white shore.', 'A forest beach resort on the Zamboanga Peninsula, known for its 750-metre white-sand beach, lush forest and reefs for snorkeling and diving. Ride a horse, climb a rock wall, zip across the trees, play a round of golf or simply lie on the sand.', 'Dapitan City, Zamboanga del Norte', 'forest', NULL, 'reservations@dakak.test', '+63 900 000 0105', '2:00 PM', '12:00 NN', 3, 116500, 5);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (5, 'Forest Wing', 'Deluxe and Prime Deluxe rooms among the trees.', 44000),
 (5, 'Beachfront Row', 'Rooms right on the white-sand beach.', 28000),
 (5, 'Family Pavilion', 'Big rooms for families and groups.', 24000),
 (5, 'Presidential Villa', 'Our most private and spacious stay.', 20500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (5, 'Deluxe Room', 'Air-conditioned room with a king or twin beds, set in the forest.', 'An air-conditioned room with king or twin beds and a private bathroom, set among the forest trees. A comfortable base between beach days and outdoor activities.', 3, 6800),
 (5, 'Prime Deluxe Room', 'A roomier deluxe room with a better view and a small sitting area.', 'A roomier deluxe room with a sitting area and a better view of the grounds. A step up for couples and small families.', 3, 8200),
 (5, 'Beachfront Room', 'A room on the sand, with a terrace facing the sea.', 'Open the door and the beach is right there. The Beachfront Room has a terrace facing the sea, air conditioning and a comfortable king bed.\n\nBest at sunrise.', 3, 11000),
 (5, 'Family Pavilion Room', 'A large room in the family pavilion for up to six guests.', 'A large room in the Family Pavilion with space for up to six guests. A good fit for family trips and reunions where everyone wants to be close together.', 6, 9500),
 (5, 'Presidential Suite', 'The resort''s most luxurious stay, with a separate living area and a private terrace.', 'The most luxurious stay at the resort. A separate living area, a king bedroom and a private terrace, with room for up to four guests.', 4, 24000);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Forest Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Deluxe Room'), 'D1', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Forest Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Deluxe Room'), 'D2', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Forest Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Deluxe Room'), 'D3', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Forest Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Deluxe Room'), 'D4', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Forest Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Prime Deluxe Room'), 'PD1', 2, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Forest Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Prime Deluxe Room'), 'PD2', 2, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Forest Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Prime Deluxe Room'), 'PD3', 2, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Beachfront Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Beachfront Room'), 'BF1', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Beachfront Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Beachfront Room'), 'BF2', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Beachfront Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Beachfront Room'), 'BF3', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Family Pavilion'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Family Pavilion Room'), 'FP1', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Family Pavilion'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Family Pavilion Room'), 'FP2', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Family Pavilion'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Family Pavilion Room'), 'FP3', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Presidential Villa'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Presidential Suite'), 'PS1', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (5, 'Resort Pool', 'The main resort swimming pool.', 60, 15000);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (5, 'Horseback riding', 'Activity', 800),
 (5, 'Zipline', 'Activity', 600),
 (5, 'ATV ride', 'Activity', 1200),
 (5, 'Rock climbing', 'Activity', 500),
 (5, 'Snorkeling gear rental', 'Activity', 350),
 (5, 'Golf round (18 holes)', 'Activity', 2500),
 (5, 'Airport transfer (Dipolog)', 'Transport', 2500),
 (5, 'Breakfast buffet', 'Dining', 550);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (5, 'Entrance fee', 'Adult', NULL, 'per person', 300, 1),
 (5, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 150, 2),
 (5, 'Cottage (day use)', 'Beach Hut', 'Daytime use, seats 6', 'per hut', 2000, 1),
 (5, 'Cottage (day use)', 'Family Hut', 'Daytime use, seats up to 15', 'per hut', 4000, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (5, 'Jun Dela Cruz', 'Resort admin', 'Admin', 'manager@dakak.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (5, 'Lea Ramos', 'Front desk', 'Staff', 'desk@dakak.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- Super admin: runs the whole site, manages every resort and every admin account. Password: admin123
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (NULL, 'Platform Admin', 'Super admin', 'Admin', 'platform@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

INSERT INTO guest (first_name, last_name, email, phone, address) VALUES
 ('Liza',  'Mendoza',    'liza@example.com',  '+63 917 000 0001', 'Zamboanga City'),
 ('Mark',  'Villanueva', 'mark@example.com',  '+63 917 000 0002', 'Pagadian City'),
 ('Sofia', 'Garcia',     'sofia@example.com', '+63 917 000 0003', 'Dipolog City');

-- Sample booking at Vista del Mar Resort: Family Casita FC1, 3 nights starting tomorrow (lookup with DEMO2345 + liza@example.com)
INSERT INTO reservation (resort_id, booking_ref, guest_id, staff_id, booking_date, check_in_date, check_out_date,
                         num_adults, num_children, special_requests, source, status, total_amount)
VALUES (1, 'DEMO2345', 1, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY),
        2, 1, 'Quiet casita please. Celebrating an anniversary.', 'Staff', 'Confirmed', 21100.00);
INSERT INTO reservation_room (reservation_id, room_id, rate_applied)
VALUES (1, (SELECT room_id FROM room WHERE resort_id = 1 AND room_number = 'FC1'), 6800.00);
INSERT INTO reservation_service (reservation_id, service_id, service_date, quantity, price_charged)
VALUES (1, (SELECT service_id FROM service WHERE resort_id = 1 AND service_name = 'Breakfast'), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 2, 700.00);
INSERT INTO payment (reservation_id, payment_date, amount, method, payment_status)
VALUES (1, CURDATE(), 5000.00, 'Card', 'Paid');
INSERT INTO reservation_companion (reservation_id, guest_type, first_name, last_name) VALUES
 (1, 'Adult', 'Carlo', 'Mendoza'),
 (1, 'Child', 'Bea', 'Mendoza');

-- Sample reviews (no stay attached, so they are shown as past guests).
INSERT INTO review (resort_id, author, rating, title, comment, reply, replied_at, created_at) VALUES
(1, 'Alex K.', 5, 'Sunset and seafood', 'Dinner at the alfresco restaurant with the sun going down over the Sulu Sea. The curacha was the best part.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 75 DAY)),
(1, 'Mia Z.', 4, 'Charming casitas', 'Rooms are rustic but clean, and falling asleep to the waves was lovely.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 79 DAY)),
(1, 'Raf E.', 3, 'Fine for a night', 'Great view, but the treehouse stairs are steep with luggage.', 'Thanks Raf, we now offer help with bags.', NOW(), DATE_SUB(NOW(), INTERVAL 83 DAY)),
(1, 'Paolo M.', 5, 'Our anniversary dinner', 'We booked a Sea Casita and had dinner by the water. Staff remembered it was our anniversary and brought dessert.', 'Happy anniversary, Paolo and family!', NOW(), DATE_SUB(NOW(), INTERVAL 19 DAY)),
(2, 'Liza C.', 5, 'Easy day trip from the city', 'We went for the day, rented a cottage and a kayak, and the kids loved the pool. Rooms were clean for the price.', 'Thank you Liza, see you again!', NOW(), DATE_SUB(NOW(), INTERVAL 23 DAY)),
(2, 'Mark T.', 4, 'Great for a barkada', 'Booked the Barkada Room for eight of us. Lots of space, and the beach is right there. Wi-Fi is weak.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(2, 'Joy P.', 5, 'ATV ride was fun', 'Staff were friendly and helped with the Pink Beach trip. Would stay over again.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 31 DAY)),
(3, 'Rosa V.', 5, 'Perfect for the family reunion', 'We booked the function hall and two barkada rooms. The kids stayed in the pool all afternoon. Very fair prices.', 'What a wonderful group, thank you Rosa.', NOW(), DATE_SUB(NOW(), INTERVAL 39 DAY)),
(3, 'Gab L.', 4, 'Close to the city', 'Quick drive from town, clean rooms and friendly staff. Gets busy on weekends.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 43 DAY)),
(3, 'Tess B.', 4, 'Good value', 'Billiards and table tennis kept the teens busy. Would come back.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 47 DAY)),
(4, 'Nina G.', 5, 'The stars were unreal', 'No light pollution at all. We stayed up for hours, with a bonfire and s''mores. The spring pool was ice cold and lovely.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 63 DAY)),
(4, 'Ben H.', 4, 'Rustic and peaceful', 'Simple cottage, spotless, and a quiet escape from the city. Bring mosquito repellent.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 67 DAY)),
(4, 'Ivy and Rex', 5, 'Honeymoon pick', 'The Spring Pool Cottage was just for the two of us. Thank you for the little surprises.', 'Congratulations again!', NOW(), DATE_SUB(NOW(), INTERVAL 71 DAY)),
(5, 'Carl P.', 5, 'Beach, forest and reef', 'We snorkeled in the morning, rode horses in the afternoon and had the beach nearly to ourselves.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 51 DAY)),
(5, 'Joan M.', 4, 'Lots to do', 'The kids loved the zipline. The drive from the airport is long, so book the transfer.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 55 DAY)),
(5, 'Edgar S.', 5, 'Worth the trip', 'Beachfront Room at sunrise was the highlight. Staff were attentive throughout.', 'Thank you Edgar, come back soon!', NOW(), DATE_SUB(NOW(), INTERVAL 59 DAY));

-- Linked staff/user accounts: one person can switch between them without logging out
CREATE TABLE IF NOT EXISTS account_link (
  link_id       INT AUTO_INCREMENT PRIMARY KEY,
  staff_id      INT NOT NULL,
  guest_id      INT NOT NULL,
  user_to_staff TINYINT(1) NOT NULL DEFAULT 1,
  staff_to_user TINYINT(1) NOT NULL DEFAULT 0,
  created_by    INT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_link_staff (staff_id),
  UNIQUE KEY uk_link_guest (guest_id),
  CONSTRAINT fk_link_staff FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
  CONSTRAINT fk_link_guest FOREIGN KEY (guest_id) REFERENCES guest(guest_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
