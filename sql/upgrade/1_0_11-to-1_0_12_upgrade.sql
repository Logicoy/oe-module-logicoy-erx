--
--  The fill indicator asked for after each send.
--
--  Right after a prescription is sent, the module asks the backend
--  (/rx-change-fill-indicator) to have the pharmacy report its dispensing
--  events back, as RX_FILL notifications. These columns remember which
--  indicator code was asked for and whether the request was queued, set at
--  the backend, or refused, so the sent prescription can say why no pharmacy
--  status ever arrived. Codes only; no prescription content.
--
--  license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfMissingColumn logicoy_erx_prescription fill_indicator
ALTER TABLE `logicoy_erx_prescription`
    ADD COLUMN `fill_indicator` VARCHAR(2) NULL DEFAULT NULL AFTER `fill_status`;
#EndIf

#IfMissingColumn logicoy_erx_prescription fill_indicator_status
ALTER TABLE `logicoy_erx_prescription`
    ADD COLUMN `fill_indicator_status` VARCHAR(16) NULL DEFAULT NULL AFTER `fill_indicator`;
#EndIf

#IfMissingColumn logicoy_erx_prescription fill_indicator_detail
ALTER TABLE `logicoy_erx_prescription`
    ADD COLUMN `fill_indicator_detail` VARCHAR(500) NULL DEFAULT NULL AFTER `fill_indicator_status`;
#EndIf
