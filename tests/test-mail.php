<?php
require_once '../config/config.php';
require_once '../config/functions.php';

// ADMIN ONLY (test page - do not deploy to production)
requireRole('admin');

$to = 'reconcliffy@gmail.com';
$subject = 'Test Email';
$message = 'This is a test email from Hostinger';
$headers = 'From: noreply@ics-dev.io';

if (mail($to, $subject, $message, $headers)) {
    echo 'Email sent successfully!';
} else {
    echo 'Email sending failed!';
}
?>