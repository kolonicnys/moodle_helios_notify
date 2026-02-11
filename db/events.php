
<?php
defined('MOODLE_INTERNAL') || die();

$observers = [

    // dokončení kurzu
    [
        'eventname'   => '\core\event\course_completed',
        'callback'    => '\local_helios_notify\observer::course_completed',
        'includefile' => '/local/helios_notify/classes/observer.php',
        'internal'    => false,
        'priority'    => 1000,
    ],

];
