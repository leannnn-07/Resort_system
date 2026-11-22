-- Linked staff/user accounts. The site creates this table by itself the first time it runs; this file is only for doing it by hand.
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
