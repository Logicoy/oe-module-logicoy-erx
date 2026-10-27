--
--  Adds the per-provider eRx profile: date of birth, prescribing type
--  (eRx or EPCS), DEA expiration date, DEA state and the last four of the SSN.
--
--  These live in a module table rather than as columns on `users` because the
--  module must not change core schema. They are collected on the OpenEMR user
--  screens through UserEditRenderEvent and sent to the LogiCoy backend when a
--  prescriber is registered.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfNotTable logicoy_erx_provider_profile
CREATE TABLE `logicoy_erx_provider_profile` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`             BIGINT NOT NULL,
    `dob`                 DATE NULL DEFAULT NULL,
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
