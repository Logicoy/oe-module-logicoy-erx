--
--  Tracks prescriptions after they leave the prescribing screen, and the
--  notifications pharmacies send back about them.
--
--  logicoy_erx_prescription holds one row per transmitted RxNew so the screen
--  can offer cancel and status checks, and so inbound cancel and fill results
--  have something to land on. The medication uuid is the backend's identifier
--  and the idempotency key: recording the same send twice updates one row.
--
--  logicoy_erx_notification holds what /v1/notifications/get returns. That
--  endpoint answers with every notification, not just new ones, so the
--  notification uuid is unique and a repeat poll refreshes display fields
--  without ever reopening a request the prescriber has already answered.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfNotTable logicoy_erx_prescription
CREATE TABLE `logicoy_erx_prescription` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `erx_medication_uuid`  VARCHAR(64) NOT NULL,
    `pid`                  INT NOT NULL,
    `facility_id`          INT NOT NULL DEFAULT 0,
    `provider_user_id`     INT NULL DEFAULT NULL,
    -- Display only, so the list is readable without a backend call.
    `drug_name`            VARCHAR(255) NULL DEFAULT NULL,
    -- This module's view of the lifecycle: sent, cancel_queued,
    -- cancel_requested, cancelled, cancel_denied, cancel_failed.
    `status`               VARCHAR(32) NOT NULL DEFAULT 'sent',
    -- As reported by the backend: SUCCESS, FAILED, PENDING_VERIFICATION, ...
    `transmission_status`  VARCHAR(40) NULL DEFAULT NULL,
    -- As reported by the pharmacy: DISPENSED, NOT_DISPENSED, ...
    `fill_status`          VARCHAR(40) NULL DEFAULT NULL,
    `cancel_request_uuid`  VARCHAR(64) NULL DEFAULT NULL,
    -- Support-facing detail from the backend. No prescription content.
    `status_detail`        VARCHAR(500) NULL DEFAULT NULL,
    `sent_at`              DATETIME NULL DEFAULT NULL,
    `status_checked_at`    DATETIME NULL DEFAULT NULL,
    `created_at`           DATETIME NULL DEFAULT NULL,
    `updated_at`           DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_medication` (`erx_medication_uuid`),
    KEY `idx_patient` (`pid`, `facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

#IfNotTable logicoy_erx_notification
CREATE TABLE `logicoy_erx_notification` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `erx_notification_uuid`  VARCHAR(64) NOT NULL,
    `facility_id`            INT NOT NULL DEFAULT 0,
    -- NULL when the backend patient is not one this install synced.
    `pid`                    INT NULL DEFAULT NULL,
    `rx_type`                VARCHAR(48) NOT NULL DEFAULT '',
    `erx_medication_uuid`    VARCHAR(64) NULL DEFAULT NULL,
    `erx_patient_uuid`       VARCHAR(64) NULL DEFAULT NULL,
    `erx_clinician_uuid`     VARCHAR(64) NULL DEFAULT NULL,
    -- Shown in the inbox so a request for an unlinked patient can still be
    -- identified. Chart data; never written to a log.
    `patient_name`           VARCHAR(255) NULL DEFAULT NULL,
    `clinician_name`         VARCHAR(255) NULL DEFAULT NULL,
    `message`                VARCHAR(1000) NULL DEFAULT NULL,
    `priority`               VARCHAR(16) NULL DEFAULT NULL,
    `reply_required`         TINYINT(1) NOT NULL DEFAULT 0,
    `requested_at`           DATETIME NULL DEFAULT NULL,
    `due_at`                 DATETIME NULL DEFAULT NULL,
    -- open: awaiting the prescriber. info: nothing to answer.
    `state`                  ENUM('open','response_queued','responded','response_failed','info')
                             NOT NULL DEFAULT 'info',
    `response_code`          VARCHAR(32) NULL DEFAULT NULL,
    `responded_by`           INT NULL DEFAULT NULL,
    `responded_at`           DATETIME NULL DEFAULT NULL,
    -- The pharmacy's requested medications, cached from get-details so a
    -- change response is built from the backend's own data.
    `request_detail`         LONGTEXT NULL,
    -- Follow-up status lookups (cancel, fill, verification) run once per
    -- notification; processed_at marks that done so a replay skips them.
    `processed_at`           DATETIME NULL DEFAULT NULL,
    `attempts`               INT NOT NULL DEFAULT 0,
    `last_error`             VARCHAR(500) NULL DEFAULT NULL,
    `created_at`             DATETIME NULL DEFAULT NULL,
    `updated_at`             DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_notification` (`erx_notification_uuid`),
    KEY `idx_state` (`facility_id`, `state`),
    KEY `idx_patient` (`pid`),
    KEY `idx_medication` (`erx_medication_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf
