
<?php
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_helios_notify', 'Helios – jednoduché napojení');
    $settings->add(new admin_setting_configtext('local_helios_notify/endpoint','Endpoint ERP','URL SOAP služby ERP','https://erp.example.com/ServiceGate', PARAM_URL));
    $settings->add(new admin_setting_configtext('local_helios_notify/profile','Profil','', 'H_prog'));
    $settings->add(new admin_setting_configtext('local_helios_notify/username','Uživatel','', 'moodle'));
    $settings->add(new admin_setting_configpasswordunmask('local_helios_notify/password','Heslo','', ''));
    $settings->add(new admin_setting_configtext('local_helios_notify/functionid','FUNCTIONID pro záznam školení','ID funkce v ERP','10095'));
    $ADMIN->add('localplugins', $settings);
}
