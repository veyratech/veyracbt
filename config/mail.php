<?php
/**
 * Konfigurasi Mail / Email untuk CBT Online
 */

return [
    'mail_from'      => getenv('CBT_MAIL_FROM') ?: 'noreply@cbtonline.local',
    'mail_from_name' => getenv('CBT_MAIL_NAME') ?: 'CBT Online',
    'smtp_host'      => getenv('CBT_SMTP_HOST') ?: '',
    'smtp_port'      => (int)(getenv('CBT_SMTP_PORT') ?: 587),
    'smtp_user'      => getenv('CBT_SMTP_USER') ?: '',
    'smtp_pass'      => getenv('CBT_SMTP_PASS') ?: '',
    'use_smtp'       => !empty(getenv('CBT_SMTP_HOST')),
];

