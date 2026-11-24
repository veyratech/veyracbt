<?php
/**
 * Mailer — simple email sender using PHP mail() or optional SMTP via stream_socket_client
 */

require_once __DIR__ . '/MailConfig.php';

class Mailer {

    /**
     * Kirim email via SMTP (jika diaktifkan di config)
     */
    private static function sendViaSmtp(string $to, string $subject, string $body, string $headers): bool {
        $config = MailConfig::get();
        $host = $config['smtp_host'] ?? '';
        $port = (int)($config['smtp_port'] ?? 587);
        $user = $config['smtp_user'] ?? '';
        $pass = $config['smtp_pass'] ?? '';

        if (empty($host) || empty($user) || empty($pass)) {
            return false;
        }

        $socket = stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT
        );
        if (!$socket) {
            return false;
        }

        stream_set_timeout($socket, 30);

        $response = '';
        $lastCode = null;

        $sendCommand = function(string $cmd) use ($socket, &$response, &$lastCode) {
            fwrite($socket, $cmd . "\r\n");
            $response = fread($socket, 4096);
            $lastCode = (int)substr($response, 0, 3);
            return $lastCode;
        };

        //EHLO
        $sendCommand('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($lastCode !== 220 && $lastCode !== 250) {
            fclose($socket);
            return false;
        }

        //STARTTLS
        $sendCommand('STARTTLS');
        if ($lastCode !== 220) {
            fclose($socket);
            return false;
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        //EHLO ulang setelah TLS
        $sendCommand('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        //AUTH LOGIN
        $sendCommand('AUTH LOGIN');
        if ($lastCode !== 334) {
            fclose($socket);
            return false;
        }
        $sendCommand(base64_encode($user));
        if ($lastCode !== 334) {
            fclose($socket);
            return false;
        }
        $sendCommand(base64_encode($pass));
        if ($lastCode !== 235) {
            fclose($socket);
            return false;
        }

        //MAIL FROM
        $fromMatch = [];
        preg_match('/From:.*?<(.+?)>/i', $headers, $fromMatch);
        $fromEmail = $fromMatch[1] ?? $user;
        $sendCommand("MAIL FROM:<{$fromEmail}>");
        if ($lastCode !== 250) {
            fclose($socket);
            return false;
        }

        //RCPT TO
        $sendCommand("RCPT TO:<{$to}>");
        if ($lastCode !== 250 && $lastCode !== 251) {
            fclose($socket);
            return false;
        }

        //DATA
        $sendCommand('DATA');
        if ($lastCode !== 354) {
            fclose($socket);
            return false;
        }

        $data = "From: {$headers}" . "\r\n" . "To: {$to}\r\n" . "Subject: {$subject}\r\n\r\n" . $body . "\r\n.\r\n";
        fwrite($socket, $data);
        $sendCommand('QUIT');
        fclose($socket);

        return true;
    }

    /**
     * Kirim email sederhana via PHP mail() atau SMTP
     */
    public static function send(string $to, string $subject, string $body, bool $isHtml = false): bool {
        $config = MailConfig::get();

        if (!empty($config['use_smtp']) && !empty($config['smtp_host'])) {
            if (self::sendViaSmtp($to, $subject, $body, '')) {
                return true;
            }
        }

        $from   = $config['mail_from'] ?? 'noreply@localhost';
        $name   = $config['mail_from_name'] ?? 'CBT Online';

        if ($isHtml) {
            $contentType = 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        } else {
            $contentType = 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        }

        $headers = [
            'From: ' . $name . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: CBTOnline PHP/' . phpversion(),
            'MIME-Version: 1.0',
            $contentType,
        ];

        $headers = array_filter(array_map('trim', $headers));
        $headers = implode("\r\n", $headers);

        return (bool) mail($to, $subject, $body, $headers);
    }

    /**
     * Kirim notifikasi hasil ujian ke siswa
     *
     * @param string $studentEmail
     * @param string $studentName
     * @param string $schoolName
     * @param string $examTitle
     * @param float  $score
     * @return bool
     */
    public static function sendExamResult(
        string $studentEmail,
        string $studentName,
        string $schoolName,
        string $examTitle,
        float $score
    ): bool {
        $config   = MailConfig::get();
        $from     = $config['mail_from'] ?? 'noreply@localhost';
        $fromName = $config['mail_from_name'] ?? 'CBT Online';

        $subject = 'Hasil Ujian - ' . $examTitle;

        $body = '<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><title>Hasil Ujian</title>
<style>
  body { font-family: Arial, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
  .wrap { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.1); overflow: hidden; }
  .header { background: #2c3e50; color: #fff; padding: 24px 20px; text-align: center; }
  .header h1 { margin: 0; font-size: 22px; }
  .content { padding: 24px 20px; color: #333; line-height: 1.6; }
  .content p { margin: 0 0 12px; }
  .score-box { background: #eaf2ff; border-left: 4px solid #2c3e50; padding: 16px; margin: 16px 0; border-radius: 4px; }
  .score-box .label { font-size: 13px; color: #666; margin-bottom: 4px; }
  .score-box .value { font-size: 32px; font-weight: bold; color: #2c3e50; }
  .footer { background: #f8f9fa; padding: 14px 20px; text-align: center; font-size: 12px; color: #888; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>Hasil Ujian ' . strtoupper(htmlspecialchars($examTitle)) . '</h1>
  </div>
  <div class="content">
    <p>Halo <strong>' . htmlspecialchars($studentName) . '</strong>,</p>
    <p>Berikut adalah hasil ujian Anda di <strong>' . htmlspecialchars($schoolName) . '</strong>.</p>
    <div class="score-box">
      <div class="label">Nilai Anda</div>
      <div class="value">' . number_format($score, 2) . '</div>
    </div>
    <p>Ujian ini telah diselesaikan secara otomatis. Jika ada pertanyaan, silakan hubungi guru mata pelajaran.</p>
  </div>
  <div class="footer">
    <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($fromName) . '. Email ini dikirim secara otomatis.</p>
  </div>
</div>
</body>
</html>';

        return self::send($studentEmail, $subject, $body, true);
    }
}
