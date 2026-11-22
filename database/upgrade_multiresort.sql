-- Upgrade an existing single-resort database (Baje's Resort) to the multi-resort platform.
-- Keeps every existing guest, room, booking and payment: they all become part of Baje's Resort (resort 1).
-- Run this ONCE in phpMyAdmin (Import) on the database you already use. Back it up first.
-- After it, four extra sample resorts (real places around Zamboanga) and a platform administrator (platform@resort.test / admin123) are added.

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
  'Zamboanga City', 'ocean', 'landing.jpg', 'reservations@resort.test', '+63 900 000 0000', '2:00 PM', '12:00 NN', 2, 63500.00, 1);

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

-- ---------- Sample data: resorts 2 to 5 (all staff passwords: admin123) ----------

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
 (2, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 120, 3),
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
 (3, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 80, 3),
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
 (4, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 80, 3),
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
 (5, 'Entrance fee', 'Senior citizen / PWD', 'With a valid ID', 'per person', 240, 3),
 (5, 'Cottage (day use)', 'Beach Hut', 'Daytime use, seats 6', 'per hut', 2000, 1),
 (5, 'Cottage (day use)', 'Family Hut', 'Daytime use, seats up to 15', 'per hut', 4000, 2);
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (5, 'Jun Dela Cruz', 'Resort admin', 'Admin', 'manager@dakak.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG'),
 (5, 'Lea Ramos', 'Front desk', 'Staff', 'desk@dakak.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');

-- Super admin: runs the whole site, manages every resort and every admin account. Password: admin123
INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES
 (NULL, 'Platform Admin', 'Super admin', 'Admin', 'platform@resort.test', '$2y$10$gXlAx/vThgoSNjW5SHEiZ.99t5.Oo2QmoGy5SRo9qgczccemKYmuG');
