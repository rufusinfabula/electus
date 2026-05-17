<?php

declare(strict_types=1);

namespace Electus\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): bool {
        $cfg  = require ROOT . '/config/config.php';
        $mail = $cfg['mail'];

        $m = new PHPMailer(true);

        try {
            $m->isSMTP();
            $m->Host       = $mail['host'];
            $m->Port       = (int) $mail['port'];
            $m->Username   = $mail['username'];
            $m->Password   = $mail['password'];
            $m->SMTPSecure = $mail['encryption'];
            $m->SMTPAuth   = !empty($mail['username']);
            $m->CharSet    = 'UTF-8';

            $m->setFrom($mail['from_email'], $mail['from_name']);
            $m->addAddress($toEmail, $toName);

            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $htmlBody;
            $m->AltBody = $textBody ?: strip_tags($htmlBody);

            $m->send();
            return true;
        } catch (Exception) {
            return false;
        }
    }
}
