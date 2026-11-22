-- Adds the resort photo gallery (the "See photos" page). Safe to run on an existing database (use: USE your_db; first).
-- The picture files are stored in the uploads/gallery/ folder of the website, not in the database.
CREATE TABLE IF NOT EXISTS resort_photo (
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
