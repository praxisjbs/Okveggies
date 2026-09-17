<?php
/**
 * scripts/tests/fake/smtp_sink.php
 * -----------------------------------------------------------------------------
 * OK Veggies. A stand-in SMTP server, for tests only.
 *
 * The application sends real mail through PHPMailer, so proving that a customer
 * receipt, a payment reminder or a staff alert is actually delivered means
 * having something at the other end of an SMTP conversation. This captures the
 * conversation and writes each message to disk, so a suite can read the subject,
 * the recipient and the body rather than inferring a send from a return value.
 *
 * It is never reachable in production: it lives under scripts/, which the web
 * server denies, and it is only ever started by the test runner.
 *
 * It answers the dialogue PHPMailer actually speaks, in the order it speaks it:
 *
 *   EHLO                  -> capabilities, including AUTH LOGIN PLAIN
 *   AUTH LOGIN            -> 334 challenge, username, 334 challenge, password
 *   AUTH PLAIN <payload>  -> 235
 *   MAIL FROM / RCPT TO   -> 250
 *   DATA ... .            -> 250, and the message is written to SMTP_SINK_DIR
 *   QUIT / RSET / NOOP    -> the obvious answers
 *
 * Run it with:
 *   php scripts/tests/fake/smtp_sink.php
 *
 * Environment:
 *   SMTP_SINK_HOST         default 127.0.0.1
 *   SMTP_SINK_PORT         default 2525
 *   SMTP_SINK_DIR          default <tmp>/okv-smtp-sink
 *   SMTP_SINK_MAX_SECONDS  default 3600, so a stuck runner cannot hang forever
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$host = (string) (getenv('SMTP_SINK_HOST') ?: '127.0.0.1');
$port = (int) (getenv('SMTP_SINK_PORT') ?: 2525);
$dir  = (string) (getenv('SMTP_SINK_DIR') ?: (sys_get_temp_dir() . '/okv-smtp-sink'));
$maxSeconds = (int) (getenv('SMTP_SINK_MAX_SECONDS') ?: 3600);

if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
    fwrite(STDERR, "[sink] cannot create $dir\n");
    exit(2);
}

$server = @stream_socket_server("tcp://$host:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "[sink] cannot listen on $host:$port: $errstr\n");
    exit(2);
}
stream_set_blocking($server, false);

fwrite(STDOUT, "[sink] listening on $host:$port, writing to $dir\n");
fflush(STDOUT);

/**
 * One SMTP conversation. Returns 1 if a message was captured, otherwise 0.
 */
function okv_sink_conversation($conn, string $dir, int $count): int
{
    $from = '';
    $to   = [];
    $reply = static function (string $line) use ($conn): void {
        fwrite($conn, $line . "\r\n");
    };
    $read = static function () use ($conn): string {
        $line = @fgets($conn, 8192);
        return $line === false ? '' : rtrim($line, "\r\n");
    };

    $reply('220 okv-sink ESMTP ready');

    while (true) {
        $line = $read();
        if ($line === '') {
            return 0;
        }
        $upper = strtoupper($line);

        if (str_starts_with($upper, 'EHLO')) {
            // Capabilities are advertised one per line, ending with a bare 250.
            fwrite($conn, "250-okv-sink\r\n");
            fwrite($conn, "250-AUTH LOGIN PLAIN\r\n");
            fwrite($conn, "250-8BITMIME\r\n");
            fwrite($conn, "250 SIZE 26214400\r\n");
            continue;
        }
        if (str_starts_with($upper, 'HELO')) {
            $reply('250 okv-sink');
            continue;
        }
        if (str_starts_with($upper, 'AUTH LOGIN')) {
            $reply('334 ' . base64_encode('Username:'));
            $user = $read();
            $reply('334 ' . base64_encode('Password:'));
            $pass = $read();
            if ($user === '' || $pass === '') {
                $reply('535 5.7.8 Authentication failed');
                continue;
            }
            $reply('235 2.7.0 Authentication successful');
            continue;
        }
        if (str_starts_with($upper, 'AUTH PLAIN')) {
            $payload = trim(substr($line, 10));
            if ($payload === '') {
                $reply('334 ');
                $read();
            }
            $reply('235 2.7.0 Authentication successful');
            continue;
        }
        if (str_starts_with($upper, 'AUTH')) {
            $reply('504 5.5.4 Unrecognised authentication type');
            continue;
        }
        if (str_starts_with($upper, 'MAIL FROM')) {
            $from = trim(substr($line, 10));
            $reply('250 2.1.0 Sender ok');
            continue;
        }
        if (str_starts_with($upper, 'RCPT TO')) {
            $to[] = trim(substr($line, 8));
            $reply('250 2.1.5 Recipient ok');
            continue;
        }
        if ($upper === 'DATA') {
            $reply('354 End data with <CR><LF>.<CR><LF>');
            $body = '';
            while (true) {
                $chunk = @fgets($conn, 8192);
                if ($chunk === false) {
                    return 0;
                }
                $trimmed = rtrim($chunk, "\r\n");
                if ($trimmed === '.') {
                    break;
                }
                // A line that is a single dot is escaped by doubling on the wire.
                if (str_starts_with($trimmed, '..')) {
                    $trimmed = substr($trimmed, 1);
                }
                $body .= $trimmed . "\n";
            }
            $count++;
            $file = $dir . '/message-' . date('Ymd-His') . '-' . $count . '.eml';
            $header = "X-OKV-SINK-FROM: $from\nX-OKV-SINK-RCPT: " . implode(', ', $to) . "\n";
            @file_put_contents($file, $header . $body);
            fwrite(STDOUT, sprintf(
                "[sink] message %d captured from=%s to=%s bytes=%d file=%s\n",
                $count, $from, implode(',', $to), strlen($body), basename($file)
            ));
            fflush(STDOUT);
            $reply('250 2.0.0 Message accepted');
            continue;
        }
        if ($upper === 'RSET') {
            $from = '';
            $to = [];
            $reply('250 2.0.0 Ok');
            continue;
        }
        if ($upper === 'NOOP') {
            $reply('250 2.0.0 Ok');
            continue;
        }
        if ($upper === 'QUIT') {
            $reply('221 2.0.0 Bye');
            return 0;
        }
        $reply('502 5.5.2 Command not implemented');
    }
}

$started = time();
$captured = 0;

while (true) {
    if ((time() - $started) > $maxSeconds) {
        fwrite(STDOUT, "[sink] max runtime reached after {$maxSeconds}s, {$captured} message(s) captured\n");
        break;
    }
    $conn = @stream_socket_accept($server, 1);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 10);
    okv_sink_conversation($conn, $dir, $captured);
    // The count is incremented inside, so recount from the directory to stay honest.
    $captured = count(glob($dir . '/message-*.eml') ?: []);
    @fclose($conn);
}

@fclose($server);
exit(0);
