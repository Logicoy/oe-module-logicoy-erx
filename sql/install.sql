--
--  LogiCoy eRx module install schema.
--
--  Named install.sql because that is the filename OpenEMR's Manage Modules
--  screen looks for when deciding whether to offer the SQL Install button
--  (InstallerController::makeButtonForSqlAction).
--
--  Never edit this file after release. Every subsequent schema change gets a
--  numbered file in sql/upgrade/.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfNotTable logicoy_erx_credentials
CREATE TABLE `logicoy_erx_credentials` (
    `id`                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope_type`                   ENUM('practice','provider') NOT NULL DEFAULT 'practice',
    `facility_id`                  INT NOT NULL DEFAULT 0,
    -- 0 means "no provider" rather than NULL: MySQL treats each NULL in a
    -- unique index as distinct, so a NULL here would defeat uniq_scope and
    -- every save would insert a new practice row instead of updating.
    `provider_user_id`             INT NOT NULL DEFAULT 0,
    `client_id`                    VARCHAR(255) NOT NULL DEFAULT '',
    -- Encrypted with CryptoGen. Never logged, never exported, never in globals.
    -- Path to the key file. Uploaded keys live in
    -- sites/<site>/documents/certificates/, which Apache denies; an operator
    -- managed key may sit anywhere outside the web root.
    `signing_key_path`             VARCHAR(255) NULL DEFAULT NULL,
    -- Retained so keys stored before the move to files keep working.
    `signing_key_encrypted`        LONGTEXT NULL,
    `jwt_kid`                      VARCHAR(255) NULL DEFAULT NULL,
    `erx_client_registration_uuid` VARCHAR(64) NULL DEFAULT NULL,
    `erx_practice_uuid`            VARCHAR(64) NULL DEFAULT NULL,
    `erx_location_uuid`            VARCHAR(64) NULL DEFAULT NULL,
    `erx_clinician_user_uuid`      VARCHAR(64) NULL DEFAULT NULL,
    `erx_clinician_user_pk_uuid`   VARCHAR(64) NULL DEFAULT NULL,
    `active`                       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`                   DATETIME NULL DEFAULT NULL,
    `updated_at`                   DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_scope` (`scope_type`, `facility_id`, `provider_user_id`),
    KEY `idx_facility` (`facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

#IfNotTable logicoy_erx_queue
CREATE TABLE `logicoy_erx_queue` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `direction`          ENUM('outbound','inbound') NOT NULL,
    `message_type`       VARCHAR(64) NOT NULL,
    -- The idempotency key. A replayed cron must never produce a second script.
    `logicoy_message_id` VARCHAR(128) NOT NULL,
    `payload`            LONGTEXT NULL,
    `response`           LONGTEXT NULL,
    `patient_id`         INT NULL DEFAULT NULL,
    `facility_id`        INT NULL DEFAULT NULL,
    `provider_user_id`   INT NULL DEFAULT NULL,
    `status`             ENUM('pending','in_flight','complete','failed','parked') NOT NULL DEFAULT 'pending',
    `attempts`           INT NOT NULL DEFAULT 0,
    `claim_token`        VARCHAR(64) NULL DEFAULT NULL,
    `last_error`         VARCHAR(500) NULL DEFAULT NULL,
    `created_at`         DATETIME NULL DEFAULT NULL,
    `updated_at`         DATETIME NULL DEFAULT NULL,
    `completed_at`       DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_message` (`logicoy_message_id`),
    KEY `idx_claim` (`direction`, `status`, `attempts`),
    KEY `idx_claim_token` (`claim_token`),
    KEY `idx_patient` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

#IfNotTable logicoy_erx_message_map
CREATE TABLE `logicoy_erx_message_map` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `logicoy_message_id` VARCHAR(128) NOT NULL,
    `prescription_id`    BIGINT UNSIGNED NOT NULL,
    `patient_id`         INT NOT NULL,
    `created_at`         DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_message` (`logicoy_message_id`),
    KEY `idx_prescription` (`prescription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

#IfNotTable logicoy_erx_log
CREATE TABLE `logicoy_erx_log` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `logicoy_message_id` VARCHAR(128) NULL DEFAULT NULL,
    `patient_id`         INT NULL DEFAULT NULL,
    `facility_id`        INT NULL DEFAULT NULL,
    `provider_user_id`   INT NULL DEFAULT NULL,
    `event`              VARCHAR(64) NOT NULL,
    `status`             VARCHAR(32) NOT NULL DEFAULT '',
    -- Support-facing detail only. No prescription content, no PHI.
    `detail`             VARCHAR(500) NULL DEFAULT NULL,
    `created_at`         DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_message` (`logicoy_message_id`),
    KEY `idx_patient` (`patient_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

#IfNotTable logicoy_erx_patient_map
CREATE TABLE `logicoy_erx_patient_map` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pid`                  INT NOT NULL,
    `erx_patient_pk_uuid`  VARCHAR(64) NOT NULL,
    `facility_id`          INT NOT NULL DEFAULT 0,
    `last_synced_at`       DATETIME NULL DEFAULT NULL,
    `created_at`           DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- One eRx patient per OpenEMR patient per practice. Without this a repeat
    -- sync would create a second patient on the eRx side.
    UNIQUE KEY `uniq_patient` (`pid`, `facility_id`),
    KEY `idx_erx_patient` (`erx_patient_pk_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

#IfNotTable logicoy_erx_provider_profile
CREATE TABLE `logicoy_erx_provider_profile` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`             BIGINT NOT NULL,
    `dob`                 DATE NULL DEFAULT NULL,
    -- M, F or O. The backend rejects anything else with "Invalid gender type",
    -- and OpenEMR's `users` table has no sex or gender column to read instead.
    `gender`              ENUM('M','F','O') NULL DEFAULT NULL,
    -- ERX registers the prescriber as NON_EPCS at the backend, which blocks
    -- controlled substances. EPCS is only accepted once the identity proofing
    -- fields below are complete.
    `prescribing_type`    ENUM('ERX','EPCS') NOT NULL DEFAULT 'ERX',
    `dea_expiration_date` DATE NULL DEFAULT NULL,
    `dea_state`           VARCHAR(2) NULL DEFAULT NULL,
    -- Last four of the SSN, encrypted with CryptoGen. Identity proofing input
    -- for EPCS. Never listed, never logged, never in the audit trail.
    `ssn_last4_encrypted` LONGTEXT NULL,
    `created_at`          DATETIME NULL DEFAULT NULL,
    `updated_at`          DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- One profile per OpenEMR user; the save path relies on this for its upsert.
    UNIQUE KEY `uniq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

-- Added in 1.0.7 (see sql/upgrade/1_0_6-to-1_0_7_upgrade.sql). Guarded, so an
-- upgraded install that already ran the upgrade file is unaffected.

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
    -- The rxFillIndicatorTypeRef code (1-8) asked for after the send, which
    -- dispensing events the pharmacy reports back, and how that request went:
    -- queued, set, failed. Its failure reason is support-facing detail only.
    `fill_indicator`         VARCHAR(2) NULL DEFAULT NULL,
    `fill_indicator_status`  VARCHAR(16) NULL DEFAULT NULL,
    `fill_indicator_detail`  VARCHAR(500) NULL DEFAULT NULL,
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

-- Added in 1.0.9 (see sql/upgrade/1_0_8-to-1_0_9_upgrade.sql). Guarded, so an
-- upgraded install that already ran the upgrade file is unaffected.

#IfNotTable logicoy_erx_idme_verification
CREATE TABLE `logicoy_erx_idme_verification` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `facility_id`           INT NOT NULL DEFAULT 0,
    `provider_user_id`      INT NOT NULL,
    -- The prescriber's ID.me account email. LogiCoy refuses the same email
    -- for two prescribers.
    `idme_email`            VARCHAR(255) NOT NULL DEFAULT '',
    -- PENDING once a verification link has been issued, then whatever
    -- check-status answers: VERIFIED, NOT_VERIFIED or EXPIRED.
    `verification_status`   VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    -- When the current verification lapses. LogiCoy asks partners to warn the
    -- prescriber ahead of it and to re-verify on or after it.
    `verification_exp_date` DATE NULL DEFAULT NULL,
    `status_message`        VARCHAR(500) NULL DEFAULT NULL,
    `initiated_at`          DATETIME NULL DEFAULT NULL,
    `checked_at`            DATETIME NULL DEFAULT NULL,
    `created_at`            DATETIME NULL DEFAULT NULL,
    `updated_at`            DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- One verification per prescriber registration; the save path upserts on it.
    UNIQUE KEY `uniq_prescriber` (`facility_id`, `provider_user_id`),
    KEY `idx_email` (`idme_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

-- Added in 1.0.10 (see sql/upgrade/1_0_9-to-1_0_10_upgrade.sql). Guarded, so
-- an upgraded install that already ran the upgrade file is unaffected.

#IfNotTable logicoy_erx_idme_mfa_session
CREATE TABLE `logicoy_erx_idme_mfa_session` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `facility_id`        INT NOT NULL DEFAULT 0,
    `provider_user_id`   INT NOT NULL,
    -- The MFA method chosen from the prescriber's ID.me account. name and
    -- option identify the kind (Authenticator, SMS, Email, Push); the label
    -- is ID.me's display text for it and may name a masked phone or address.
    `mfa_method_uuid`    VARCHAR(64) NOT NULL DEFAULT '',
    `mfa_method_name`    VARCHAR(64) NOT NULL DEFAULT '',
    `mfa_method_option`  VARCHAR(64) NOT NULL DEFAULT '',
    `mfa_method_label`   VARCHAR(128) NOT NULL DEFAULT '',
    -- idmeStartMFAResponse.uuid: the session the complete and
    -- delivery-status-check calls refer to.
    `session_uuid`       VARCHAR(64) NOT NULL DEFAULT '',
    -- Ours, normalised from the backend's word: WAITING, ACCEPTED or FAILED.
    -- An unknown word is stored as FAILED; only ACCEPTED ever unlocks a send.
    `status`             VARCHAR(32) NOT NULL DEFAULT 'WAITING',
    -- When the session lapses, as the backend gave it, stored in UTC. The
    -- gate compares in PHP against UTC, never against the server's NOW().
    `expires_at`         DATETIME NULL DEFAULT NULL,
    -- Support-facing detail from the backend. Never the code.
    `status_message`     VARCHAR(500) NULL DEFAULT NULL,
    `started_at`         DATETIME NULL DEFAULT NULL,
    `accepted_at`        DATETIME NULL DEFAULT NULL,
    `checked_at`         DATETIME NULL DEFAULT NULL,
    `created_at`         DATETIME NULL DEFAULT NULL,
    `updated_at`         DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- One current session per prescriber registration; starting again
    -- replaces it, and every write upserts on it so a replay is harmless.
    UNIQUE KEY `uniq_prescriber` (`facility_id`, `provider_user_id`),
    KEY `idx_session` (`session_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf

-- Added in 1.0.11 (see sql/upgrade/1_0_10-to-1_0_11_upgrade.sql). Guarded, so
-- an upgraded install that already ran the upgrade file is unaffected.

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
