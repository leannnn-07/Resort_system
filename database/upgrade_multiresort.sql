-- Upgrade an existing single-resort database (Baje's Resort) to the multi-resort platform.
-- Keeps every existing guest, room, booking and payment: they all become part of Baje's Resort (resort 1).
-- Run this ONCE in phpMyAdmin (Import) on the database you already use. Back it up first.
-- After it, the five extra sample resorts and a platform administrator (platform@resort.test / admin123) are added.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE resort (
  resort_id       INT AUTO_INCREMENT PRIMARY KEY,
  slug            VARCHAR(40)  NOT NULL,
  name            VARCHAR(100) NOT NULL,
  tagline         VARCHAR(160) NULL,
  description     TEXT NULL,
  location        VARCHAR(120) NULL,
  theme           VARCHAR(20)  NOT NULL DEFAULT 'ocean',
  hero_image      VARCHAR(120) NULL,
  contact_email   VARCHAR(120) NULL,
  contact_phone   VARCHAR(40)  NULL,
  checkin_time    VARCHAR(20)  NOT NULL DEFAULT '2:00 PM',
  checkout_time   VARCHAR(20)  NOT NULL DEFAULT '12:00 NN',
  free_cancel_days INT NOT NULL DEFAULT 2,
  exclusive_rate  DECIMAL(10,2) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      INT NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_resort_slug UNIQUE (slug),
  CONSTRAINT ck_resort_rate CHECK (exclusive_rate IS NULL OR exclusive_rate >= 0)
) ENGINE=InnoDB;

INSERT INTO resort (resort_id, slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone, checkin_time, checkout_time, free_cancel_days, exclusive_rate, sort_order) VALUES
 (1, 'bajes-resort', 'Baje''s Resort', 'Quiet rooms, warm water, long mornings.',
  'A relaxed beachfront resort with garden rooms, villas, wooden cottages and a pool for every mood. Swim at dusk, sleep to the quiet.',
  'Beachfront, Philippines', 'ocean', 'landing.jpg', 'reservations@resort.test', '+63 900 000 0000', '2:00 PM', '12:00 NN', 2, 63500.00, 1);

CREATE TABLE building (
  building_id    INT AUTO_INCREMENT PRIMARY KEY,
  resort_id      INT NOT NULL,
  name           VARCHAR(80)  NOT NULL,
  description    VARCHAR(300) NULL,
  exclusive_rate DECIMAL(10,2) NULL,
  CONSTRAINT uk_building_name UNIQUE (resort_id, name),
  CONSTRAINT ck_building_rate CHECK (exclusive_rate IS NULL OR exclusive_rate >= 0),
  CONSTRAINT fk_building_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pool (
  pool_id      INT AUTO_INCREMENT PRIMARY KEY,
  resort_id    INT NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  description  VARCHAR(300) NULL,
  max_guests   INT NOT NULL DEFAULT 20,
  rate_per_day DECIMAL(10,2) NULL,
  CONSTRAINT uk_pool_name UNIQUE (resort_id, name),
  CONSTRAINT ck_pool_guests CHECK (max_guests > 0),
  CONSTRAINT ck_pool_rate CHECK (rate_per_day IS NULL OR rate_per_day >= 0),
  CONSTRAINT fk_pool_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO building (resort_id, name, description, exclusive_rate) VALUES
 (1, 'Main Building', 'Standard and Deluxe rooms around the pool.', NULL),
 (1, 'Villas and Suites', 'Family villas and the beachfront suite.', NULL),
 (1, 'Cottage Row', 'Wooden cottages on the lawn among the coconut palms.', NULL);
INSERT INTO pool (resort_id, name, description, max_guests, rate_per_day) VALUES
 (1, 'Main Pool', 'The big swimming pool beside the Main Building.', 40, 8000),
 (1, 'Kids Pool', 'A shallow pool for little swimmers.', 20, 3000);

CREATE TABLE reservation_pool (
  reservation_id INT NOT NULL,
  pool_id        INT NOT NULL,
  rate_applied   DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (reservation_id, pool_id),
  CONSTRAINT ck_rp_rate CHECK (rate_applied >= 0),
  CONSTRAINT fk_rp_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_pool FOREIGN KEY (pool_id) REFERENCES pool(pool_id)
) ENGINE=InnoDB;

-- guests and staff
ALTER TABLE guest ADD COLUMN created_resort_id INT NULL AFTER email_verified_at,
  ADD CONSTRAINT fk_guest_resort FOREIGN KEY (created_resort_id) REFERENCES resort(resort_id) ON DELETE SET NULL;
ALTER TABLE staff ADD COLUMN resort_id INT NULL AFTER staff_id;
UPDATE staff SET resort_id = 1;
-- Access level: 'Admin' runs the resort, 'Staff' does desk work. Everyone who exists today stays an Admin so nobody is locked out;
-- change people to 'Staff' in Admin > Staff afterwards.
ALTER TABLE staff ADD COLUMN access VARCHAR(10) NOT NULL DEFAULT 'Admin' AFTER role;
ALTER TABLE staff ALTER COLUMN access SET DEFAULT 'Staff';
ALTER TABLE staff ADD CONSTRAINT ck_staff_access CHECK (access IN ('Admin','Staff'));
ALTER TABLE staff ADD CONSTRAINT fk_staff_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE;

-- room types, rooms, services, price list
ALTER TABLE room_type ADD COLUMN resort_id INT NOT NULL DEFAULT 1 AFTER room_type_id;
ALTER TABLE room_type DROP INDEX uk_room_type_name;
ALTER TABLE room_type ALTER COLUMN resort_id DROP DEFAULT;
ALTER TABLE room_type ADD CONSTRAINT uk_room_type_name UNIQUE (resort_id, type_name),
  ADD CONSTRAINT fk_room_type_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE;

ALTER TABLE room ADD COLUMN resort_id INT NOT NULL DEFAULT 1 AFTER room_id,
  ADD COLUMN building_id INT NULL AFTER resort_id;
UPDATE room r JOIN room_type t ON t.room_type_id = r.room_type_id
   SET r.building_id = (SELECT b.building_id FROM building b WHERE b.resort_id = 1 AND b.name =
        CASE WHEN t.type_name LIKE '%Cottage%' THEN 'Cottage Row'
             WHEN t.type_name LIKE '%Villa%' OR t.type_name LIKE '%Suite%' THEN 'Villas and Suites'
             ELSE 'Main Building' END);
ALTER TABLE room DROP INDEX uk_room_number;
ALTER TABLE room ALTER COLUMN resort_id DROP DEFAULT;
ALTER TABLE room MODIFY building_id INT NOT NULL;
ALTER TABLE room ADD CONSTRAINT uk_room_number UNIQUE (resort_id, room_number),
  ADD CONSTRAINT fk_room_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_room_building FOREIGN KEY (building_id) REFERENCES building(building_id);

ALTER TABLE service ADD COLUMN resort_id INT NOT NULL DEFAULT 1 AFTER service_id;
ALTER TABLE service ALTER COLUMN resort_id DROP DEFAULT;
ALTER TABLE service ADD CONSTRAINT fk_service_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE;
ALTER TABLE price_item ADD COLUMN resort_id INT NOT NULL DEFAULT 1 AFTER price_item_id;
ALTER TABLE price_item ALTER COLUMN resort_id DROP DEFAULT;
ALTER TABLE price_item ADD CONSTRAINT fk_price_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE;

-- reservations
ALTER TABLE reservation ADD COLUMN resort_id INT NOT NULL DEFAULT 1 AFTER reservation_id,
  ADD COLUMN booking_scope VARCHAR(10) NOT NULL DEFAULT 'Rooms' AFTER staff_id,
  ADD COLUMN building_id INT NULL AFTER booking_scope;
ALTER TABLE reservation ALTER COLUMN resort_id DROP DEFAULT;
ALTER TABLE reservation ADD CONSTRAINT fk_res_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id),
  ADD CONSTRAINT fk_res_building FOREIGN KEY (building_id) REFERENCES building(building_id) ON DELETE SET NULL,
  ADD CONSTRAINT ck_res_scope CHECK (booking_scope IN ('Rooms','Building','Pool','Resort')),
  ADD INDEX idx_res_resort (resort_id);

-- whole-building price for Baje's: 85% of the room rates, rounded to 500
UPDATE building b SET b.exclusive_rate = (
   SELECT ROUND(SUM(t.base_rate) * 0.85 / 500) * 500 FROM room r JOIN room_type t ON t.room_type_id = r.room_type_id WHERE r.building_id = b.building_id)
 WHERE b.resort_id = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Sample data: resorts 2 to 6 (all staff passwords: admin123) ----------

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
