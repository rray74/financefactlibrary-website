-- Email alerts to the site owner (scripts/send-alerts.php,
-- includes/alerts.php).
--
-- One row per thing already emailed about, so the same item isn't emailed
-- every day while it waits. alert_key says what it is: "held:12" (a held
-- change), "look:340" (a figure that needs a look) or "cron:check-sources.php:stale"
-- (a scheduled job with a problem). The row is deleted when the item clears,
-- so if it comes back, it's emailed about again.
CREATE TABLE admin_alerts_sent (
    alert_key VARCHAR(190) NOT NULL PRIMARY KEY,
    first_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
