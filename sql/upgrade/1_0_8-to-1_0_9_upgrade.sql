--
--  ID.me identity verification for prescribers.
--
--  LogiCoy proofs a prescriber's identity through ID.me before it will run
--  the MFA that signs controlled substance prescriptions. The verification
--  happens in the prescriber's browser on ID.me; this table only remembers
--  the outcome and when it expires, so the prescribing screen can warn ahead
--  of the expiry without a backend call.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

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
