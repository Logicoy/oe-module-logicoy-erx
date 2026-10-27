--
--  Adds the prescriber's gender to the eRx profile.
--
--  /v1/clinician/add rejects a registration without it ("User gender type is
--  mandatory") and OpenEMR's `users` table has no sex or gender column of its
--  own, so unlike phone, email and address there is nothing core to read it
--  from. The backend accepts M, F or O; MALE / FEMALE / U / X are refused with
--  "Invalid gender type".
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfMissingColumn logicoy_erx_provider_profile gender
ALTER TABLE `logicoy_erx_provider_profile`
    ADD COLUMN `gender` ENUM('M','F','O') NULL DEFAULT NULL AFTER `dob`;
#EndIf
