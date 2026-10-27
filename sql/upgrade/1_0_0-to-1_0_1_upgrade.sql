--
--  Adds the OpenEMR patient to eRx patient mapping.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfNotTable logicoy_erx_patient_map
CREATE TABLE `logicoy_erx_patient_map` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pid`                  INT NOT NULL,
    `erx_patient_pk_uuid`  VARCHAR(64) NOT NULL,
    `facility_id`          INT NOT NULL DEFAULT 0,
    `last_synced_at`       DATETIME NULL DEFAULT NULL,
    `created_at`           DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_patient` (`pid`, `facility_id`),
    KEY `idx_erx_patient` (`erx_patient_pk_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf
