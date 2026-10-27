--
--  ID.me multi-factor authentication sessions for EPCS prescribers.
--
--  Identity proofing (logicoy_erx_idme_verification) is done once and lasts
--  until its expiry date. MFA is the per-session second factor a VERIFIED
--  prescriber passes before LogiCoy will accept a controlled substance
--  prescription from them; it is valid only until the expires time the
--  backend returns, typically the working day. This table remembers the
--  current session per prescriber registration so the send gate and the
--  screens can read it without a backend call. The factor itself is checked
--  by ID.me through the backend; no code or secret is stored here.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

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
