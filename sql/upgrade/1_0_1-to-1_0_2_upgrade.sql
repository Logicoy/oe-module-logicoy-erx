--
--  Stores the signing key as a file and keeps only its path in the database.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfMissingColumn logicoy_erx_credentials signing_key_path
ALTER TABLE `logicoy_erx_credentials`
    ADD COLUMN `signing_key_path` VARCHAR(255) NULL DEFAULT NULL AFTER `client_id`;
#EndIf
