--
--  Clears the 'pending' placeholder that earlier versions wrote into the eRx
--  identifier columns.
--
--  The placeholder satisfied a save time validation rule, but the provisioning
--  steps read any non-empty value as "already registered" and returned it
--  instead of calling the backend. A row carrying it could never be registered.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

UPDATE `logicoy_erx_credentials`
   SET `erx_practice_uuid` = NULL
 WHERE `erx_practice_uuid` = 'pending';

UPDATE `logicoy_erx_credentials`
   SET `erx_location_uuid` = NULL
 WHERE `erx_location_uuid` = 'pending';
