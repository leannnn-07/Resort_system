-- Adds guest ratings and comments. Safe to run on an existing database (running it again changes nothing) (use: USE your_db; first).
-- Guest reviews: one rating (1 to 5 stars) and an optional comment per finished stay. The resort can reply or hide a review.
CREATE TABLE IF NOT EXISTS review (
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

-- Optional sample reviews: delete this block if you do not want them.
-- Sample reviews are added only while the review table is empty, so running this file again adds nothing.
INSERT INTO review (resort_id, author, rating, title, comment, reply, replied_at, created_at) SELECT * FROM (VALUES
(1, 'Maria S.', 5, 'Quiet and beautiful', 'The Deluxe Room balcony at sunrise was worth the trip. Staff remembered our names by the second day.', 'Thank you Maria! We will tell the team.', NOW(), DATE_SUB(NOW(), INTERVAL 7 DAY)),
(1, 'John D.', 4, 'Great for families', 'Kids loved the shallow pool. The Family Villa kitchenette saved us a lot on meals.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(1, 'Ana R.', 5, 'Perfect long weekend', 'Easy booking, easy check-in and a calm place to sleep. The cottages under the palms are lovely.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 15 DAY)),
(1, 'Paolo M.', 3, 'Good, a bit noisy', 'Lovely grounds, but a wedding party the first night kept us up. Rooms are clean and the pool is great.', 'Sorry about the noise, Paolo. We now end events at 10 PM.', NOW(), DATE_SUB(NOW(), INTERVAL 19 DAY)),
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
(5, 'Edgar S.', 5, 'Worth the trip', 'Beachfront Room at sunrise was the highlight. Staff were attentive throughout.', 'Thank you Edgar, come back soon!', NOW(), DATE_SUB(NOW(), INTERVAL 59 DAY))) AS seed
WHERE NOT EXISTS (SELECT 1 FROM review);
