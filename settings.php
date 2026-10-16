<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Admin settings.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_sis_notification', new lang_string('pluginname', 'local_sis_notification'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        // Fixed choices keep the values inside the range the delivery task supports.
        $batchsizes = [50, 100, 200, 500, 1000];
        $settings->add(new admin_setting_configselect(
            'local_sis_notification/batchsize',
            new lang_string('batchsize', 'local_sis_notification'),
            new lang_string('batchsize_desc', 'local_sis_notification'),
            200,
            array_combine($batchsizes, $batchsizes)
        ));

        $retention = [30, 90, 180, 365, 730];
        $settings->add(new admin_setting_configselect(
            'local_sis_notification/retentiondays',
            new lang_string('retentiondays', 'local_sis_notification'),
            new lang_string('retentiondays_desc', 'local_sis_notification'),
            180,
            array_combine($retention, $retention)
        ));
    }
}
