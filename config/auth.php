<?php

return [
    /*
     * Active authentication provider.
     * Supported: 'local'
     * Future:    'ldap', 'imap'
     */
    'provider' => 'local',

    'session' => [
        'name'     => 'netmon_session',
        'lifetime' => 7200,    // seconds (2 hours)
        'secure'   => false,   // set true when serving over HTTPS in production
    ],
];
