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
(6, 'Raf E.', 3, 'Fine for a night', 'Convenient but busy. The pool was crowded on Saturday.', 'Thanks Raf. Booking the whole pool is an option for private time.', NOW(), DATE_SUB(NOW(), INTERVAL 83 DAY))) AS seed
WHERE NOT EXISTS (SELECT 1 FROM review);
