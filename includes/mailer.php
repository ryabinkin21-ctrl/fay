<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendMail(string $toEmail, string $toName, string $subject, string $body): bool
{
    $env = parse_ini_file(__DIR__ . '/../.env');

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $env['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $env['MAIL_USERNAME'];
        $mail->Password   = $env['MAIL_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)$env['MAIL_PORT'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($env['MAIL_FROM'], $env['MAIL_FROM_NAME']);
        $mail->addAddress($toEmail, $toName);

        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $mail->ErrorInfo);
        return false;
    }
}
