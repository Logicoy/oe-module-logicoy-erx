--
--  Backfills the clinician identifier a credential row is missing.
--
--  A prescriber has one identifier under two names. /v1/clinician/add returns
--  it as erxClinicianUserPKUuid, which is what the send and search endpoints
--  ask for, while the draft endpoints ask for the same value as
--  erxClinicianUserUuid. Registration stored only what the response named, so
--  every row written by the credentials screen carries the PK uuid and an empty
--  erx_clinician_user_uuid, and every draft call was rejected with
--  "Clinician User UUID is mandatory".
--
--  Rows loaded by hand with both values are left alone; only a blank side is
--  filled, and only from the other one.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

UPDATE `logicoy_erx_credentials`
   SET `erx_clinician_user_uuid` = `erx_clinician_user_pk_uuid`
 WHERE (`erx_clinician_user_uuid` IS NULL OR `erx_clinician_user_uuid` = '')
   AND `erx_clinician_user_pk_uuid` IS NOT NULL
   AND `erx_clinician_user_pk_uuid` <> '';

UPDATE `logicoy_erx_credentials`
   SET `erx_clinician_user_pk_uuid` = `erx_clinician_user_uuid`
 WHERE (`erx_clinician_user_pk_uuid` IS NULL OR `erx_clinician_user_pk_uuid` = '')
   AND `erx_clinician_user_uuid` IS NOT NULL
   AND `erx_clinician_user_uuid` <> '';
