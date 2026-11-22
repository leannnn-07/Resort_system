-- Add staff / resort admin / super admin notification bell tables.
-- Safe to run more than once on MariaDB 10.2+ (XAMPP).

CREATE TABLE IF NOT EXISTS staff_notification (
  notif_id       INT AUTO_INCREMENT PRIMARY KEY,
  resort_id      INT NOT NULL,
  reservation_id INT NULL,
  kind           VARCHAR(30) NOT NULL,
  title          VARCHAR(120) NOT NULL,
  body           VARCHAR(220) NOT NULL,
  link           VARCHAR(200) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sn_resort (resort_id, created_at),
  INDEX idx_sn_res (reservation_id),
  CONSTRAINT fk_sn_resort FOREIGN KEY (resort_id) REFERENCES resort(resort_id) ON DELETE CASCADE,
  CONSTRAINT fk_sn_res    FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_notification_read (
  staff_id INT NOT NULL,
  notif_id INT NOT NULL,
  read_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (staff_id, notif_id),
  CONSTRAINT fk_snr_staff FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
  CONSTRAINT fk_snr_notif FOREIGN KEY (notif_id) REFERENCES staff_notification(notif_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
