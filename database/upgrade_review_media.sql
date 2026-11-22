-- Lets guests attach photos and videos to their review. Safe to run on an existing database (use: USE your_db; first).
-- Run upgrade_reviews.sql first if your database has no review table yet.
-- The files themselves are stored in the uploads/reviews/ folder of the website, not in the database.
CREATE TABLE IF NOT EXISTS review_media (
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
