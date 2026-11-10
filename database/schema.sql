-- Resort Booking Platform: many resorts, one booking system
-- Works with MySQL 8.0.16+ and MariaDB 10.2.1+
-- Import with:  mysql -u root -p < database/schema.sql      (this RESETS all data)

CREATE DATABASE IF NOT EXISTS resort_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE resort_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS review, password_reset, reservation_companion, payment, reservation_service, reservation_pool, reservation_room,
                     reservation, rate_limit, price_item, service, room, room_type, pool, building, staff, guest, resort;
SET FOREIGN_KEY_CHECKS = 1;

-- One row per resort. Everything below (rooms, buildings, pools, services, prices, staff, bookings) belongs to one resort.
CREATE TABLE resort (
  resort_id       INT AUTO_INCREMENT PRIMARY KEY,
  slug            VARCHAR(40)  NOT NULL,              -- used in web addresses: landing.php?r=coral-cove
  name            VARCHAR(100) NOT NULL,
  tagline         VARCHAR(160) NULL,
  description     TEXT NULL,
  location        VARCHAR(120) NULL,
  theme           VARCHAR(20)  NOT NULL DEFAULT 'ocean',   -- colour theme: ocean, lagoon, pine, sunset, forest, dusk
  hero_image      VARCHAR(120) NULL,                  -- optional photo inside assets/, for example resorts/coral-cove.jpg
  contact_email   VARCHAR(120) NULL,
  contact_phone   VARCHAR(40)  NULL,
  checkin_time    VARCHAR(20)  NOT NULL DEFAULT '2:00 PM',
  checkout_time   VARCHAR(20)  NOT NULL DEFAULT '12:00 NN',
  free_cancel_days INT NOT NULL DEFAULT 2,
  exclusive_rate  DECIMAL(10,2) NULL,                 -- price per night to book the ENTIRE resort; empty = not offered
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      INT NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_resort_slug UNIQUE (slug),
  CONSTRAINT ck_resort_rate CHECK (exclusive_rate IS NULL OR exclusive_rate >= 0)
) ENGINE=InnoDB;

CREATE TABLE guest (
  guest_id   INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(60)  NOT NULL,
  last_name  VARCHAR(60)  NOT NULL,
  email      VARCHAR(120) NOT NULL,
  phone      VARCHAR(30),
  address    VARCHAR(200),
  id_number  VARCHAR(50),
  password_hash     VARCHAR(255) NULL,   -- set only when the guest has created an online account
  email_verified_at DATETIME NULL,       -- when they proved the email with a code
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
  CONSTRAINT ck_res_scope    CHECK (booking_scope IN ('Rooms','Building','Pool','Resort')),
  CONSTRAINT ck_res_total    CHECK (total_amount >= 0),
  INDEX idx_res_dates (check_in_date, check_out_date),
  INDEX idx_res_status (status),
  INDEX idx_res_resort (resort_id)
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
  last_name      VARCHAR(60) NOT NULL,
  age            INT NOT NULL,
  CONSTRAINT ck_rc_type CHECK (guest_type IN ('Adult','Senior','Child')),
  CONSTRAINT ck_rc_age  CHECK (age BETWEEN 0 AND 120),
  CONSTRAINT fk_rc_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  INDEX idx_rc_res (reservation_id)
) ENGINE=InnoDB;

CREATE TABLE payment (
  payment_id     INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  payment_date   DATE NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  method         VARCHAR(30) NOT NULL,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'Paid',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_res FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  CONSTRAINT ck_pay_amount CHECK (amount > 0),
  CONSTRAINT ck_pay_status CHECK (payment_status IN ('Paid','Pending','Refunded','Voided'))
) ENGINE=InnoDB;

-- ---------- Sample data: 6 resorts (all staff passwords: admin123, change them in Admin > Staff) ----------

-- ===== Baje's Resort =====
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

INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (1, 'bajes-resort', 'Baje''s Resort', 'Quiet rooms, warm water, long mornings.', 'A relaxed beachfront resort with garden rooms, villas, wooden cottages and a pool for every mood. Swim at dusk, sleep to the quiet.', 'Beachfront, Philippines', 'ocean', 'landing.jpg', 'reservations@resort.test', '+63 900 000 0000', '2:00 PM', '12:00 NN', 2, 63500, 1);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (1, 'Main Building', 'Standard and Deluxe rooms around the pool.', 18500),
 (1, 'Villas and Suites', 'Family villas and the beachfront suite.', 26500),
 (1, 'Cottage Row', 'Wooden cottages on the lawn among the coconut palms.', 18500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (1, 'Standard Room', 'Queen bed, garden view and air conditioning. A calm base for couples and solo travellers.', 'A calm, simple base for couples and solo travellers.\n\nThe Standard Room has a queen bed, air conditioning and a window onto the garden. It is the easiest way to enjoy the resort without paying for more room than you need.', 2, 3500),
 (1, 'Deluxe Room', 'King bed, private balcony and a partial sea view. Room for a small family.', 'Wake up to the sea. The Deluxe Room has a king bed with crisp white linen, warm wood floors and a private balcony with a partial sea view, so the morning light and the sound of the water come to you.\n\nThere is a sitting area for slow afternoons, and enough space for a small family of up to three.', 3, 5500),
 (1, 'Family Villa', 'Two bedrooms, kitchenette and a private patio. Sleeps the whole family.', 'Space for the whole family under one roof. The Family Villa has two bedrooms, a kitchenette and a private patio, with bright rooms that open toward the garden and the sea.\n\nIt sleeps up to six, so everyone can be together without being on top of each other.', 6, 9500),
 (1, 'Beach Suite', 'Beachfront suite with a plunge pool and direct sand access.', 'Our most private stay, right on the sand. The Beach Suite is a beachfront suite with a plunge pool and direct access to the beach, a canopy bed, a lounge area, and sliding doors that open to a balcony facing the water.\n\nBest at sunset, and made for two.', 2, 12000),
 (1, 'Standard Cottage', 'A wooden cottage with a pitched roof and its own balcony, set on the lawn among coconut palms.', 'A simple wooden cottage with a pitched roof and its own balcony, set on an open lawn among coconut palms. It is a good fit for guests who want fresh air, shade and the sound of the trees rather than a hotel corridor.\n\nChoose it for a relaxed overnight stay, or see the Price list if you only need a cottage for the day.', 3, 3000),
 (1, 'Family Cottage', 'A self-contained cottage with a covered terrace, a dining table and a built-in grill, surrounded by palms.', 'A self-contained cottage built for groups. The covered terrace has a dining table and a built-in grill, so you can cook and eat outdoors in the shade, with palms and garden all around.\n\nIt sleeps up to six, which makes it a good base for family gatherings and celebrations.', 6, 6500);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Main Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Standard Room'), '101', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Main Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Standard Room'), '102', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Main Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Standard Room'), '103', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Main Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Deluxe Room'), '201', 2, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Main Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Deluxe Room'), '202', 2, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Villas and Suites'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Family Villa'), 'V1', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Villas and Suites'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Family Villa'), 'V2', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Villas and Suites'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Beach Suite'), 'S1', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Cottage Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Standard Cottage'), 'SC1', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Cottage Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Standard Cottage'), 'SC2', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Cottage Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Standard Cottage'), 'SC3', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Cottage Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Family Cottage'), 'FC1', 1, 'Available'),
 (1, (SELECT building_id FROM building WHERE resort_id = 1 AND name = 'Cottage Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 1 AND type_name = 'Family Cottage'), 'FC2', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (1, 'Main Pool', 'The big swimming pool beside the Main Building.', 40, 8000),
 (1, 'Kids Pool', 'A shallow pool for little swimmers.', 20, 3000);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (1, 'Airport pickup', 'Transport', 1200),
 (1, 'Breakfast buffet', 'Dining', 450),
 (1, 'Island hopping tour', 'Activity', 1800),
 (1, 'Spa massage (60 min)', 'Wellness', 1500),
 (1, 'Intro scuba dive', 'Activity', 3200),
 (1, 'Late checkout', 'Room', 800);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (1, 'Entrance fee', 'Adult', NULL, 'per person', 150, 1),
 (1, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 100, 2),
 (1, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 120, 3),
 (1, 'Entrance fee', 'Child (3 years and under)', NULL, 'per person', 0, 4),
 (1, 'Cottage (day use)', 'Standard Cottage', 'Daytime use', 'per cottage', 1200, 1),
 (1, 'Cottage (day use)', 'Family Cottage', 'Daytime use, with built-in grill', 'per cottage', 2500, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (1, 'Maria Santos', 'Resort admin', 'Admin', 'admin@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (1, 'Jose Reyes', 'Reservations', 'Staff', 'jose@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (1, 'Ana Cruz', 'Concierge', 'Staff', 'ana@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Coral Cove Resort =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (2, 'coral-cove', 'Coral Cove Resort', 'Limestone cliffs, clear water, and a bed above the reef.', 'Coral Cove sits in a quiet bay ringed by limestone cliffs. Stay in a garden room, a reef villa, or a stilt cottage over the water, and hop to the islands by boat from our own jetty.', 'El Nido, Palawan', 'lagoon', NULL, 'reservations@coralcove.test', '+63 900 000 0102', '2:00 PM', '11:00 AM', 3, 91000, 2);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (2, 'Cove Hall', 'Garden and ocean-view rooms facing the bay.', 31000),
 (2, 'Reef Villas', 'Private villas with a patio and hammock.', 29500),
 (2, 'Stilt Row', 'Stilt cottages built over the shallow reef.', 30500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (2, 'Garden Room', 'Queen bed, rain shower and a terrace onto the tropical garden.', 'A cool, quiet room with a queen bed, a rain shower and a terrace that opens onto the tropical garden. Ten steps from breakfast, two minutes from the beach.', 2, 4200),
 (2, 'Ocean View Room', 'King bed and a balcony looking straight over the bay.', 'The Ocean View Room has a king bed and a balcony facing the bay, so you can watch the longtail boats go out at sunrise.\n\nA daybed on the balcony fits a child, so it works for small families too.', 3, 6500),
 (2, 'Reef Villa', 'Two bedrooms, a living area and a private patio with a hammock.', 'A private villa for a family or two couples. Two bedrooms, a living area, a small kitchen and a patio with a hammock, tucked behind the palms a short walk from the sand.', 5, 11500),
 (2, 'Stilt Cottage', 'A cottage on stilts over the reef, with a deck and a ladder into the water.', 'Sleep above the reef. The Stilt Cottage stands on the water with a wide deck, a king bed, and a ladder that drops straight into the sea for early swims.\n\nBest for couples, and very popular, so book early.', 2, 9000);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Cove Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Garden Room'), 'G1', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Cove Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Garden Room'), 'G2', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Cove Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Garden Room'), 'G3', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Cove Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Garden Room'), 'G4', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Cove Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Ocean View Room'), 'O1', 2, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Cove Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Ocean View Room'), 'O2', 2, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Cove Hall'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Ocean View Room'), 'O3', 2, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Reef Villas'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Reef Villa'), 'RV1', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Reef Villas'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Reef Villa'), 'RV2', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Reef Villas'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Reef Villa'), 'RV3', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Stilt Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Stilt Cottage'), 'ST1', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Stilt Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Stilt Cottage'), 'ST2', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Stilt Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Stilt Cottage'), 'ST3', 1, 'Available'),
 (2, (SELECT building_id FROM building WHERE resort_id = 2 AND name = 'Stilt Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 2 AND type_name = 'Stilt Cottage'), 'ST4', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (2, 'Infinity Pool', 'An infinity pool that meets the bay at the horizon.', 50, 12000),
 (2, 'Plunge Pool', 'A small, private plunge pool by the villas.', 10, 3500);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (2, 'Island hopping tour A', 'Activity', 1500),
 (2, 'Snorkel set rental', 'Activity', 300),
 (2, 'Airport van transfer', 'Transport', 1800),
 (2, 'Breakfast for two', 'Dining', 800),
 (2, 'Sunset boat cruise', 'Activity', 2200);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (2, 'Entrance fee', 'Adult', NULL, 'per person', 200, 1),
 (2, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 120, 2),
 (2, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 160, 3),
 (2, 'Cottage (day use)', 'Beach Hut', 'Daytime use', 'per hut', 1500, 1),
 (2, 'Cottage (day use)', 'Family Hut', 'Daytime use, seats up to 10', 'per hut', 2800, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (2, 'Liza Fernandez', 'Resort admin', 'Admin', 'manager@coralcove.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (2, 'Ramon Dizon', 'Front desk', 'Staff', 'desk@coralcove.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Pinewood Highlands Retreat =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (3, 'pinewood-highlands', 'Pinewood Highlands Retreat', 'Cool air, pine trees, and a fireplace waiting for you.', 'A mountain retreat among the pines. Wake up to fog on the ridge, warm up in the heated pool, and end the day by the fire. Perfect for families, barkadas and company retreats.', 'Baguio, Benguet', 'pine', NULL, 'stay@pinewood.test', '+63 900 000 0103', '3:00 PM', '11:00 AM', 5, 74500, 3);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (3, 'Lodge', 'The main lodge with cozy rooms and the dining hall.', 16000),
 (3, 'Cabin Row', 'Wooden cabins along the pine path.', 23000),
 (3, 'Summit House', 'A large house at the top of the property, with a view of the valley.', 35000);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (3, 'Lodge Room', 'Warm wooden room with two beds and a view of the pines.', 'A simple, warm room in the main lodge with two beds, hot showers and a window onto the pines. The dining hall is just downstairs.', 2, 3800),
 (3, 'Pine Cabin', 'A wooden cabin with a loft, a porch and a small fire pit outside.', 'A wooden cabin with a bedroom, a sleeping loft for kids, a porch and a small fire pit outside your door. Bring marshmallows.', 4, 6800),
 (3, 'Family Chalet', 'A large chalet with three bedrooms, a living room and a fireplace.', 'Room for a big family or a group of friends. Three bedrooms, a living room with a real fireplace, a dining table for eight and a kitchen.', 8, 12500),
 (3, 'Fireplace Suite', 'A romantic suite with a fireplace, soaking tub and valley view.', 'The Fireplace Suite is made for two. A king bed, a fireplace, a deep soaking tub and a wide window over the valley.', 2, 8200);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Lodge'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Lodge Room'), 'L1', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Lodge'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Lodge Room'), 'L2', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Lodge'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Lodge Room'), 'L3', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Lodge'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Lodge Room'), 'L4', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Lodge'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Lodge Room'), 'L5', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Cabin Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Pine Cabin'), 'C1', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Cabin Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Pine Cabin'), 'C2', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Cabin Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Pine Cabin'), 'C3', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Cabin Row'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Pine Cabin'), 'C4', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Summit House'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Family Chalet'), 'CH1', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Summit House'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Family Chalet'), 'CH2', 1, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Summit House'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Fireplace Suite'), 'FS1', 2, 'Available'),
 (3, (SELECT building_id FROM building WHERE resort_id = 3 AND name = 'Summit House'), (SELECT room_type_id FROM room_type WHERE resort_id = 3 AND type_name = 'Fireplace Suite'), 'FS2', 2, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (3, 'Heated Pool', 'A warm outdoor pool with a view of the pine forest.', 30, 7500),
 (3, 'Warm Soak Pool', 'A small hot-spring style soaking pool.', 12, 3000);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (3, 'Firewood bundle', 'Room', 250),
 (3, 'Breakfast buffet', 'Dining', 400),
 (3, 'Horseback ride (30 min)', 'Activity', 600),
 (3, 'Bonfire and s''mores set', 'Activity', 700),
 (3, 'Airport pickup (Clark)', 'Transport', 4500);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (3, 'Entrance fee', 'Adult', NULL, 'per person', 120, 1),
 (3, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 80, 2),
 (3, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 96, 3),
 (3, 'Cottage (day use)', 'Picnic Hut', 'Daytime use, seats up to 8', 'per hut', 1000, 1),
 (3, 'Cottage (day use)', 'Pavilion', 'Daytime use, seats up to 30', 'per pavilion', 3500, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (3, 'Carlos Bautista', 'Resort admin', 'Admin', 'manager@pinewood.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (3, 'Mia Dalisay', 'Front desk', 'Staff', 'desk@pinewood.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Sunset Lagoon Resort =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (4, 'sunset-lagoon', 'Sunset Lagoon Resort', 'Warm light, a long lagoon, and three pools.', 'Sunset Lagoon is a lively family resort on Panglao island with three pools, a lagoon beach and a sunset bar. Choose a room in the Sunset Wing, or a bungalow right by the water.', 'Panglao, Bohol', 'sunset', NULL, 'hello@sunsetlagoon.test', '+63 900 000 0104', '2:00 PM', '12:00 NN', 2, 66500, 4);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (4, 'Sunset Wing', 'Two-storey wing with rooms and suites facing west.', 29000),
 (4, 'Lagoon Annex', 'Quieter rooms beside the lagoon beach.', 18500),
 (4, 'Bungalow Walk', 'Beach bungalows in a row along the sand.', 19500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (4, 'Standard Room', 'Two beds or one queen, air conditioning, and a small balcony.', 'A comfortable, bright room with two single beds or one queen, air conditioning and a small balcony with a partial pool view.', 3, 3600),
 (4, 'Lagoon Room', 'Ground floor room with a patio that opens to the lagoon.', 'Step out of your door and onto the lagoon edge. The Lagoon Room is on the ground floor with a patio, two beds and a daybed.', 4, 5400),
 (4, 'Sunset Suite', 'A corner suite with a wraparound balcony facing the sunset.', 'A big corner suite with a king bed, a living area and a wraparound balcony that faces the sunset. A very good place to be around six in the evening.', 4, 9800),
 (4, 'Beach Bungalow', 'A bungalow on the sand with a hammock porch and outdoor shower.', 'A beach bungalow with a king bed, a hammock porch and an outdoor shower, about twenty steps from the water.', 3, 7600);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Sunset Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Standard Room'), 'A101', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Sunset Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Standard Room'), 'A102', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Sunset Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Standard Room'), 'A103', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Sunset Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Standard Room'), 'A104', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Sunset Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Sunset Suite'), 'A201', 2, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Sunset Wing'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Sunset Suite'), 'A202', 2, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Lagoon Annex'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Lagoon Room'), 'B101', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Lagoon Annex'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Lagoon Room'), 'B102', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Lagoon Annex'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Lagoon Room'), 'B103', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Lagoon Annex'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Lagoon Room'), 'B104', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Bungalow Walk'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Beach Bungalow'), 'BB1', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Bungalow Walk'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Beach Bungalow'), 'BB2', 1, 'Available'),
 (4, (SELECT building_id FROM building WHERE resort_id = 4 AND name = 'Bungalow Walk'), (SELECT room_type_id FROM room_type WHERE resort_id = 4 AND type_name = 'Beach Bungalow'), 'BB3', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (4, 'Lagoon Pool', 'The main pool with a swim-up bar.', 60, 14000),
 (4, 'Kiddie Pool', 'A shallow splash pool for small children.', 25, 2500),
 (4, 'Quiet Pool', 'An adults-only pool away from the music.', 20, 5000);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (4, 'Dolphin watching trip', 'Activity', 1900),
 (4, 'Kayak rental (1 hour)', 'Activity', 400),
 (4, 'Breakfast buffet', 'Dining', 420),
 (4, 'Sunset cocktails for two', 'Dining', 900),
 (4, 'Airport pickup (Tagbilaran)', 'Transport', 1500),
 (4, 'Late checkout', 'Room', 700);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (4, 'Entrance fee', 'Adult', NULL, 'per person', 180, 1),
 (4, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 100, 2),
 (4, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 144, 3),
 (4, 'Cottage (day use)', 'Beach Cottage', 'Daytime use', 'per cottage', 1500, 1),
 (4, 'Cottage (day use)', 'Poolside Cabana', 'Daytime use, with sofa and table', 'per cabana', 2200, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (4, 'Teresa Villamor', 'Resort admin', 'Admin', 'manager@sunsetlagoon.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (4, 'Noel Abella', 'Front desk', 'Staff', 'desk@sunsetlagoon.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Palm Grove Garden Resort =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (5, 'palm-grove', 'Palm Grove Garden Resort', 'Treehouses, bamboo cottages and a garden that never stops.', 'A green, slow resort built around a big garden. Sleep in a treehouse, a bamboo cottage or a garden room, swim in the lap pool, and wander the herb garden before breakfast.', 'Dumaguete, Negros Oriental', 'forest', NULL, 'book@palmgrove.test', '+63 900 000 0105', '2:00 PM', '12:00 NN', 2, 58500, 5);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (5, 'Garden Block A', 'Garden rooms on the quiet side of the grove.', 13500),
 (5, 'Garden Block B', 'Family rooms close to the pool.', 13000),
 (5, 'Treehouse Grove', 'Treehouses and bamboo cottages among the palms.', 32500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (5, 'Garden Room', 'Queen bed and a private garden terrace.', 'A calm garden room with a queen bed, a rain shower and a private terrace with two chairs and a view of the herb garden.', 2, 3200),
 (5, 'Garden Family Room', 'Two double beds, ideal for a family of four.', 'Two double beds, a sitting area and a wide terrace. Easy for a family of four, and close to the pool.', 4, 5000),
 (5, 'Treehouse', 'A treehouse reached by a short bridge, with a deck in the palms.', 'A real treehouse, a few metres up among the palms, reached by a short suspension bridge. A king bed, a deck, and birds in the morning.', 2, 7500),
 (5, 'Bamboo Cottage', 'A bamboo cottage with a high roof and a veranda.', 'A tall bamboo cottage with a thatched roof, a veranda and two beds. Cool even at noon.', 4, 5200);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Garden Block A'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Garden Room'), 'GA1', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Garden Block A'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Garden Room'), 'GA2', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Garden Block A'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Garden Room'), 'GA3', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Garden Block A'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Garden Room'), 'GA4', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Garden Block A'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Garden Room'), 'GA5', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Garden Block B'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Garden Family Room'), 'GB1', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Garden Block B'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Garden Family Room'), 'GB2', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Garden Block B'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Garden Family Room'), 'GB3', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Treehouse'), 'TH1', 2, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Treehouse'), 'TH2', 2, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Treehouse'), 'TH3', 2, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Bamboo Cottage'), 'BC1', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Bamboo Cottage'), 'BC2', 1, 'Available'),
 (5, (SELECT building_id FROM building WHERE resort_id = 5 AND name = 'Treehouse Grove'), (SELECT room_type_id FROM room_type WHERE resort_id = 5 AND type_name = 'Bamboo Cottage'), 'BC3', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (5, 'Garden Pool', 'A freeform pool surrounded by palms.', 40, 6500),
 (5, 'Lap Pool', 'A long, quiet pool for morning swims.', 15, 3000);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (5, 'Organic breakfast', 'Dining', 380),
 (5, 'Cooking class', 'Activity', 1200),
 (5, 'Bicycle rental (day)', 'Activity', 250),
 (5, 'Dumaguete airport pickup', 'Transport', 700),
 (5, 'Herbal massage (60 min)', 'Wellness', 1100);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (5, 'Entrance fee', 'Adult', NULL, 'per person', 130, 1),
 (5, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 80, 2),
 (5, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 104, 3),
 (5, 'Cottage (day use)', 'Garden Hut', 'Daytime use', 'per hut', 900, 1),
 (5, 'Cottage (day use)', 'Big Pavilion', 'Daytime use, seats up to 25', 'per pavilion', 3000, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (5, 'Grace Tan', 'Resort admin', 'Admin', 'manager@palmgrove.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (5, 'Paolo Lim', 'Front desk', 'Staff', 'desk@palmgrove.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- ===== Taal Lakeview Resort =====
INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (6, 'taal-lakeview', 'Taal Lakeview Resort', 'Breakfast above the clouds, a pool above the lake.', 'Perched on the ridge, Taal Lakeview has views over the lake and the volcano from almost every room. Come for a weekend away from the city, a wedding, or a team retreat.', 'Tagaytay, Cavite', 'dusk', NULL, 'reserve@taallakeview.test', '+63 900 000 0106', '3:00 PM', '11:00 AM', 3, 82000, 6);
INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (6, 'Ridge Building', 'Rooms along the ridge, all with a view.', 49500),
 (6, 'Lakeview Villas', 'Standalone villas with their own terrace.', 32500);
INSERT INTO room_type (resort_id, type_name, description, details, max_occupancy, base_rate) VALUES
 (6, 'Ridge Room', 'Two beds, air conditioning and a ridge view.', 'A comfortable room on the ridge with two beds, air conditioning and a window that looks out over the trees and, on a clear day, the lake.', 3, 4000),
 (6, 'Lakeview Deluxe', 'King bed and a balcony with a full lake and volcano view.', 'The Lakeview Deluxe has a king bed and a balcony that looks right over the lake and the volcano. Take your coffee out there.', 3, 6800),
 (6, 'Lakeview Villa', 'A standalone villa with two bedrooms and a terrace over the lake.', 'A two-bedroom villa standing on its own with a terrace over the lake, a living room and a kitchenette. Good for families and small groups.', 6, 12800),
 (6, 'Family Suite', 'A family suite with a bunk room and a lake view.', 'A family suite with a master bedroom, a bunk room for kids and a balcony with a view of the lake.', 5, 8800);
INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Ridge Room'), 'R1', 1, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Ridge Room'), 'R2', 1, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Ridge Room'), 'R3', 1, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Ridge Room'), 'R4', 1, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Ridge Room'), 'R5', 1, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Lakeview Deluxe'), 'D1', 2, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Lakeview Deluxe'), 'D2', 2, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Lakeview Deluxe'), 'D3', 2, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Family Suite'), 'F1', 2, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Ridge Building'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Family Suite'), 'F2', 2, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Lakeview Villas'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Lakeview Villa'), 'V1', 1, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Lakeview Villas'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Lakeview Villa'), 'V2', 1, 'Available'),
 (6, (SELECT building_id FROM building WHERE resort_id = 6 AND name = 'Lakeview Villas'), (SELECT room_type_id FROM room_type WHERE resort_id = 6 AND type_name = 'Lakeview Villa'), 'V3', 1, 'Available');
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (6, 'Infinity Edge Pool', 'A pool that seems to flow into the lake.', 35, 9000),
 (6, 'Jacuzzi Pool', 'A small hot jacuzzi pool.', 8, 2500);
INSERT INTO service (resort_id, service_name, category, unit_price) VALUES
 (6, 'Breakfast with a view', 'Dining', 500),
 (6, 'Bulalo dinner set (4 pax)', 'Dining', 1800),
 (6, 'Wine and cheese board', 'Dining', 1400),
 (6, 'Hot stone massage (60 min)', 'Wellness', 1700),
 (6, 'Manila shuttle (van)', 'Transport', 5500);
INSERT INTO price_item (resort_id, category, item_name, note, unit, price, sort_order) VALUES
 (6, 'Entrance fee', 'Adult', NULL, 'per person', 250, 1),
 (6, 'Entrance fee', 'Child (4 to 12 years)', NULL, 'per person', 150, 2),
 (6, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 200, 3),
 (6, 'Cottage (day use)', 'Viewing Deck Table', 'Daytime use, seats 6', 'per table', 1800, 1),
 (6, 'Cottage (day use)', 'Function Hall', 'Daytime use, seats up to 60', 'per hall', 9000, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (6, 'Andrea Ocampo', 'Resort admin', 'Admin', 'manager@taallakeview.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (6, 'Ben Salazar', 'Front desk', 'Staff', 'desk@taallakeview.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- Super admin: runs the whole site, manages every resort and every admin account. Password: admin123
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (NULL, 'Platform Admin', 'Super admin', 'Admin', 'platform@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

INSERT INTO guest (first_name, last_name, email, phone, address, id_number) VALUES
 ('Liza',  'Mendoza',    'liza@example.com',  '+63 917 000 0001', 'Cagayan de Oro', 'DL-1002345'),
 ('Mark',  'Villanueva', 'mark@example.com',  '+63 917 000 0002', 'Davao City',     'PP-5567788'),
 ('Sofia', 'Garcia',     'sofia@example.com', '+63 917 000 0003', 'Cebu City',      'DL-9981122');

-- Sample booking at Baje's Resort: Deluxe Room 201, 3 nights starting tomorrow (lookup with DEMO2345 + liza@example.com)
INSERT INTO reservation (resort_id, booking_ref, guest_id, staff_id, booking_date, check_in_date, check_out_date,
                         num_adults, num_children, special_requests, source, status, total_amount)
VALUES (1, 'DEMO2345', 1, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY),
        2, 1, 'Quiet room please. Celebrating an anniversary.', 'Staff', 'Confirmed', 17400.00);
INSERT INTO reservation_room (reservation_id, room_id, rate_applied)
VALUES (1, (SELECT room_id FROM room WHERE resort_id = 1 AND room_number = '201'), 5500.00);
INSERT INTO reservation_service (reservation_id, service_id, service_date, quantity, price_charged)
VALUES (1, (SELECT service_id FROM service WHERE resort_id = 1 AND service_name = 'Breakfast buffet'), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 2, 900.00);
INSERT INTO payment (reservation_id, payment_date, amount, method, payment_status)
VALUES (1, CURDATE(), 5000.00, 'Card', 'Paid');
INSERT INTO reservation_companion (reservation_id, guest_type, first_name, last_name, age) VALUES
 (1, 'Adult', 'Carlo', 'Mendoza', 41),
 (1, 'Child', 'Bea', 'Mendoza', 9);

-- Sample reviews (no stay attached, so they are shown as past guests).
INSERT INTO review (resort_id, author, rating, title, comment, reply, replied_at, created_at) VALUES
(1, 'Maria S.', 5, 'Quiet and beautiful', 'The Deluxe Room balcony at sunrise was worth the trip. Staff remembered our names by the second day.', 'Thank you Maria! We will tell the team.', NOW(), DATE_SUB(NOW(), INTERVAL 7 DAY)),
(1, 'John D.', 4, 'Great for families', 'Kids loved the shallow pool. The Family Villa kitchenette saved us a lot on meals.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(1, 'Ana R.', 5, 'Perfect long weekend', 'Easy booking, easy check-in and a calm place to sleep. The cottages under the palms are lovely.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 15 DAY)),
(1, 'Paolo M.', 3, 'Good, a bit noisy', 'Lovely grounds, but a wedding party the first night kept us up. Rooms are clean and the pool is great.', 'Sorry about the noise, Paolo. We now end events at 10 PM.', NOW(), DATE_SUB(NOW(), INTERVAL 19 DAY)),
(2, 'Liza C.', 5, 'Infinity pool dreams', 'We booked the whole infinity pool for my sister''s birthday. Nobody else around, and the staff set up everything.', 'We loved hosting you, Liza!', NOW(), DATE_SUB(NOW(), INTERVAL 23 DAY)),
(2, 'Mark T.', 4, 'Sea view worth it', 'Great sunsets and friendly staff. Breakfast could start earlier.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 27 DAY)),
(2, 'Joy P.', 5, 'Best snorkel trip', 'The island hopping add-on was well organised and the guide was fantastic.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 31 DAY)),
(2, 'Ken A.', 4, 'Solid stay', 'Clean rooms, good water pressure, easy parking.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(3, 'Rosa V.', 5, 'Whole resort for our reunion', 'We took the entire resort for 40 relatives. Everything ran smoothly and the price was fair.', 'What a wonderful group, thank you Rosa.', NOW(), DATE_SUB(NOW(), INTERVAL 39 DAY)),
(3, 'Gab L.', 4, 'Peaceful hillside', 'Cool air, quiet mornings and a very friendly team. The road up is steep, so take it slowly.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 43 DAY)),
(3, 'Tess B.', 5, 'Lovely cottages', 'Wooden cottages with a view of the valley. We would come back in a heartbeat.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 47 DAY)),
(4, 'Dan F.', 4, 'Great value', 'Clean rooms for a good price. Walking distance to the beach and plenty of food nearby.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 51 DAY)),
(4, 'Che O.', 5, 'Friendly staff', 'Late arrival was no problem, and they upgraded our room for free.', 'Happy to help, Che!', NOW(), DATE_SUB(NOW(), INTERVAL 55 DAY)),
(4, 'Vic N.', 3, 'Okay overall', 'Comfortable but the Wi-Fi was slow in the evening.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 59 DAY)),
(5, 'Nina G.', 5, 'A real escape', 'No crowds, quiet water and a campfire on the beach. The rates are very fair.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 63 DAY)),
(5, 'Ben H.', 4, 'Nice and simple', 'Basic rooms, spotless, and a great view from the terrace.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 67 DAY)),
(5, 'Ivy and Rex', 5, 'Honeymoon pick', 'The private building was just us and the sea. Thank you for the little surprises.', 'Congratulations again!', NOW(), DATE_SUB(NOW(), INTERVAL 71 DAY)),
(6, 'Alex K.', 4, 'City break done right', 'Good location and a rooftop pool. Breakfast was excellent.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 75 DAY)),
(6, 'Mia Z.', 5, 'Smooth from start to finish', 'Booked online, checked in quickly and got exactly the room we chose.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 79 DAY)),
(6, 'Raf E.', 3, 'Fine for a night', 'Convenient but busy. The pool was crowded on Saturday.', 'Thanks Raf. Booking the whole pool is an option for private time.', NOW(), DATE_SUB(NOW(), INTERVAL 83 DAY));
