--
--  The pharmacy each draft was saved with, by name.
--
--  The backend's draft listing does not return the pharmacy, and the
--  sent-prescription record never had it, so the screen could only say "the
--  pharmacy" when asking to send or cancel. This remembers the pharmacy the
--  prescriber picked, keyed on the LogiCoy medication uuid, which the
--  medication keeps from draft through send. One row per medication; a save
--  upserts it, so a replayed save is harmless.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfNotTable logicoy_erx_rx_pharmacy
CREATE TABLE `logicoy_erx_rx_pharmacy` (
    `erx_medication_uuid`  VARCHAR(64) NOT NULL,
    `pharmacy_org_id`      VARCHAR(64) NOT NULL DEFAULT '',
    -- Display only, as chosen from the directory search when the draft was
    -- saved. Never sent anywhere; the backend routes on the org id it holds.
    `pharmacy_name`        VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`           DATETIME NULL DEFAULT NULL,
    `updated_at`           DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`erx_medication_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf
