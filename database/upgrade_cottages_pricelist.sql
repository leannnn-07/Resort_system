-- Upgrade for an EXISTING resort_db (keeps your bookings). Run once, in phpMyAdmin > SQL, with resort_db selected.
-- New installs do not need this: database/schema.sql already includes everything.

ALTER TABLE room_type ADD COLUMN details TEXT NULL AFTER description;

CREATE TABLE IF NOT EXISTS price_item (
  price_item_id INT AUTO_INCREMENT PRIMARY KEY,
  category      VARCHAR(40)  NOT NULL,
  item_name     VARCHAR(80)  NOT NULL,
  note          VARCHAR(160) NULL,
  unit          VARCHAR(40)  NOT NULL DEFAULT 'per person',
  price         DECIMAL(10,2) NOT NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  CONSTRAINT ck_price_item_price CHECK (price >= 0)
) ENGINE=InnoDB;

UPDATE room_type SET details = 'A calm, simple base for couples and solo travellers.\n\nThe Standard Room has a queen bed, air conditioning and a window onto the garden. It is the easiest way to enjoy the resort without paying for more room than you need.' WHERE type_name = 'Standard Room';
UPDATE room_type SET details = 'Wake up to the sea. The Deluxe Room has a king bed with crisp white linen, warm wood floors and a private balcony with a partial sea view, so the morning light and the sound of the water come to you.\n\nThere is a sitting area for slow afternoons, and enough space for a small family of up to three.' WHERE type_name = 'Deluxe Room';
UPDATE room_type SET details = 'Space for the whole family under one roof. The Family Villa has two bedrooms, a kitchenette and a private patio, with bright rooms that open toward the garden and the sea.\n\nIt sleeps up to six, so everyone can be together without being on top of each other.' WHERE type_name = 'Family Villa';
UPDATE room_type SET details = 'Our most private stay, right on the sand. The Beach Suite is a beachfront suite with a plunge pool and direct access to the beach, a canopy bed, a lounge area, and sliding doors that open to a balcony facing the water.\n\nBest at sunset, and made for two.' WHERE type_name = 'Beach Suite';

INSERT INTO room_type (type_name, description, details, max_occupancy, base_rate) VALUES
 ('Standard Cottage', 'A wooden cottage with a pitched roof and its own balcony, set on the lawn among coconut palms.',
  'A simple wooden cottage with a pitched roof and its own balcony, set on an open lawn among coconut palms. It is a good fit for guests who want fresh air, shade and the sound of the trees rather than a hotel corridor.\n\nChoose it for a relaxed overnight stay, or see the Price list if you only need a cottage for the day.', 3, 3000.00),
 ('Family Cottage', 'A self-contained cottage with a covered terrace, a dining table and a built-in grill, surrounded by palms.',
  'A self-contained cottage built for groups. The covered terrace has a dining table and a built-in grill, so you can cook and eat outdoors in the shade, with palms and garden all around.\n\nIt sleeps up to six, which makes it a good base for family gatherings and celebrations.', 6, 6500.00);

INSERT INTO room (room_type_id, room_number, floor, status)
SELECT t.room_type_id, r.num, 1, 'Available'
  FROM (SELECT 'Standard Cottage' AS tn, 'SC1' AS num UNION ALL SELECT 'Standard Cottage','SC2' UNION ALL SELECT 'Standard Cottage','SC3'
        UNION ALL SELECT 'Family Cottage','FC1' UNION ALL SELECT 'Family Cottage','FC2') r
  JOIN room_type t ON t.type_name = r.tn;

-- SAMPLE PRICES. Replace with your real entrance fees and cottage rates in Staff desk > Price list.
INSERT INTO price_item (category, item_name, note, unit, price, sort_order) VALUES
 ('Entrance fee',      'Adult',                  NULL,                      'per person',  150.00, 1),
 ('Entrance fee',      'Child (4 to 12 years)',  NULL,                      'per person',  100.00, 2),
 ('Entrance fee',      'Senior citizen / PWD',   'With a valid ID',         'per person',  120.00, 3),
 ('Entrance fee',      'Child (3 years and under)', NULL,                   'per person',    0.00, 4),
 ('Cottage (day use)', 'Standard Cottage',       'Daytime use', 'per cottage', 1200.00, 1),
 ('Cottage (day use)', 'Family Cottage',         'Daytime use, with built-in grill', 'per cottage', 2500.00, 2);
