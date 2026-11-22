-- Enable single-cottage day-use bookings (price_item capacity/units + reservation_cottage table + booking_scope 'Cottage').
-- Safe to run more than once on MariaDB 10.2+ (XAMPP).

ALTER TABLE price_item
  ADD COLUMN IF NOT EXISTS max_guests         INT NOT NULL DEFAULT 15 AFTER price,
  ADD COLUMN IF NOT EXISTS quantity_available INT NOT NULL DEFAULT 5  AFTER max_guests;

CREATE TABLE IF NOT EXISTS reservation_cottage (
  reservation_id INT NOT NULL,
  price_item_id  INT NOT NULL,
  cottage_name   VARCHAR(80) NOT NULL,
  cottage_rate   DECIMAL(10,2) NOT NULL,
  entrance_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  quantity       INT NOT NULL DEFAULT 1,
  PRIMARY KEY (reservation_id, price_item_id),
  CONSTRAINT fk_rcot_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_rcot_item FOREIGN KEY (price_item_id)  REFERENCES price_item(price_item_id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
