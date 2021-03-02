<?php
/**
 * Pechkin v0.1
 * Created by Sergey Peshalov https://github.com/desfpc
 * Lite php SMTP mail send class
 * https://github.com/desfpc/Pechkin
 *
 */

namespace pechkin;

class pechkin
{

    //constants - you may edit this as you wish
    const LOCALHOST = 'localhost';
    const LINEBREAK = "\r\n";
    const CONTENT_TYPE = 'multipart/mixed';  //multipart/mixed || text/plain || text/html
    const CHARSET = '"utf-8"';
    const TRANSFER_ENCODEING = 'quoted-printable'; //quoted-printable || 8-bit

    //more data to send
    public $recipients = [];
    public $cc = [];
    public $bcc = [];
    public $attachments = [];

    //mail server data
    public $server;
    public $port;
    public $username;
    public $password;
    public $secure; //ssl, tls or none
    public $authorized = false; //connection timeout in seconds

    //if user authorized
    public $debug = false;

    //mail server connection socket
    public $altBody = '';

    //debug mode
    public $serverIp;

    //other technical params
    private $timeout;
    private $connection;

    public function __construct($server, $port, $username, $password, $secure = false, int $timeout = 60, bool $debug = false)
    {

        $this->serverIp = $_SERVER['SERVER_ADDR'];//exec("ifconfig | grep -Eo 'inet (addr:)?([0-9]*\.){3}[0-9]*' | grep -Eo '([0-9]*\.){3}[0-9]*' | grep -v '127.0.0.1'");

        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        if ($secure) {
            $this->secure = strtolower(trim($secure));
        } else {
            $this->secure = 'none';
        }
        $this->timeout = $timeout;
        $this->debug = $debug;

        if ($this->debug) {
            echo '<hr>mail settings: <br>' . $this->server . '
            <br>' . $this->port . '
            <br>' . $this->username . '
            <br>' . $this->password . '<hr>';
        }

        if (!$this->serverConnect()) return;
        if (!$this->serverAuthorize()) return;

    }

    //send e-mail letter

    public function serverConnect()
    {

        if ($this->debug) {
            echo '<br>Connecting...';
        }

        if ($this->secure == 'ssl') {
            $this->server = 'ssl://' . $this->server;
        }

        $this->connection = fsockopen($this->server, $this->port, $errno, $errstr, $this->timeout);
        if ($this->falseResponse($this->serverResponse(), 3, '220')) return false;

        return true;
    }

    //get email from string

    private function falseResponse($response, $len, $code)
    {
        if ($this->debug) {
            echo '<br>Response code: "' . substr($response, 0, $len), '"';
        }
        if (substr($response, 0, $len) != $code) return true;
        return false;
    }

    //mail server authorization

    private function serverResponse()
    {
        $out = '';
        while ($str = fgets($this->connection, 4096)) {
            $out .= $str;
            if (substr($str, 3, 1) == ' ') {
                break;
            }
        }
        if ($this->debug) {
            echo '<hr>mail server response: <br>' . $out . '<hr>';
        }
        return $out;
    }

    //mail server response

    private function serverAuthorize()
    {

        if ($this->debug) {
            echo '<br>Authorizing...';
        }

        fputs($this->connection, 'HELO ' . self::LOCALHOST . self::LINEBREAK);
        $this->serverResponse();

        if ($this->secure == 'tls') {
            fputs($this->connection, 'STARTTLS' . self::LINEBREAK);
            if ($this->falseResponse($this->serverResponse(), 3, '200')) return false;
            stream_socket_enable_crypto($this->connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fputs($this->connection, 'HELO ' . self::LOCALHOST . self::LINEBREAK);
            if ($this->falseResponse($this->serverResponse(), 3, '250')) return false;
        }

        if ($this->server != 'localhost') {
            fputs($this->connection, 'AUTH LOGIN' . self::LINEBREAK);
            if ($this->falseResponse($this->serverResponse(), 3, '334')) return false;

            fputs($this->connection, base64_encode($this->username) . self::LINEBREAK);
            if ($this->falseResponse($this->serverResponse(), 3, '334')) return false;

            fputs($this->connection, base64_encode($this->password) . self::LINEBREAK);
            if ($this->falseResponse($this->serverResponse(), 3, '235')) return false;
        }
        $this->authorized = true;
        return true;
    }

    //if mail server response is false

    public function send($from, $to, $subject, $message, $headers = null)
    {
        //letter header
        $out = 'Date: ' . date('D, j M Y G:i:s') . ' -0500' . self::LINEBREAK;
        $out .= 'From: ' . $from . self::LINEBREAK;
        $out .= 'Reply-To: ' . $from . self::LINEBREAK;
        $out .= $this->setRecipients($to);

        //add user headers
        if (!is_null($headers)) {
            $out .= $headers . self::LINEBREAK;
        }

        $out .= 'Subject: ' . $subject . self::LINEBREAK;
        $out .= 'MIME-Version: 1.0' . self::LINEBREAK;

        if (self::CONTENT_TYPE == 'multipart/mixed') {
            $boundary = $this->generateBoundary();
            $message = $this->multipartMessage($message, $boundary);
            $out .= 'Content-Type: ' . self::CONTENT_TYPE . ';' . self::LINEBREAK;
            $out .= '    boundary="' . $boundary . '"';
        } else {
            $out .= 'Content-Type: ' . self::CONTENT_TYPE . '; charset=' . self::CHARSET;
        }

        $out .= self::LINEBREAK . self::LINEBREAK . $message . self::LINEBREAK;
        $out .= '.' . self::LINEBREAK;
        
        fputs($this->connection, 'MAIL FROM: <' . $this->emailFrom($from) . '>' . self::LINEBREAK);
        $this->serverResponse();

        if ($to != '') {
            fputs($this->connection, 'RCPT TO: <' . $this->emailFrom($to) . '>' . self::LINEBREAK);
            $this->serverResponse();
        }

        //more recipients and copies
        $this->sendRecipients($this->recipients);
        $this->sendRecipients($this->cc);
        $this->sendRecipients($this->bcc);

        fputs($this->connection, 'DATA' . self::LINEBREAK);
        $this->serverResponse();

        fputs($this->connection, $out);
        if ($this->falseResponse($this->serverResponse(), 3, '250')) return false;
        return true;

    }

    private function setRecipients($to)
    {

        $out = 'To: ';
        if ($to != '') {
            $out .= $to . ',';
        }

        if (count($this->recipients) > 0) {
            for ($i = 0; $i < count($this->recipients); $i++) {
                $out .= $this->recipients[$i] . ',';
            }
        }

        $out = substr($out, 0, -1) . self::LINEBREAK;
        if (count($this->cc) > 0) {
            $out .= 'CC: ';
            for ($i = 0; $i < count($this->cc); $i++) {
                $out .= $this->cc[$i] . ',';
            }
            $out = substr($out, 0, -1) . self::LINEBREAK;
        }
        return $out;
    }

    private function generateBoundary()
    {
        $out = "--=_NextPart_000_";
        $out .= $this->randomID(4) . '_';
        $out .= $this->randomID(8) . '.';
        $out .= $this->randomID(8);
        return $out;
    }

    private function randomID($len)
    {
        $chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $out = '';
        for ($t = 0; $t < $len; $t++) {
            $r = rand(0, 61);
            $out = $out . substr($chars, $r, 1);
        }
        return $out;
    }

    private function multipartMessage($htmlpart, $boundary)
    {

        if ($this->altBody == '') {
            $this->altBody = pechkin::realStripTags($htmlpart);
        }

        $altBoundary = $this->generateBoundary();
        ob_start(); //Turn on output buffering

        $out = 'This is a multi-part message in MIME format.' . self::LINEBREAK . self::LINEBREAK;
        $out .= '--' . $boundary . self::LINEBREAK;

        $out .= 'Content-Type: multipart/alternative;' . self::LINEBREAK;
        $out .= '    boundary="' . $altBoundary . '"' . self::LINEBREAK . self::LINEBREAK;

        $out .= '--' . $altBoundary . self::LINEBREAK;
        $out .= 'Content-Type: text/plain; charset=' . self::CHARSET . self::LINEBREAK;
        $out .= 'Content-Transfer-Encoding: ' . self::TRANSFER_ENCODEING . self::LINEBREAK . self::LINEBREAK;
        $out .= $this->altBody . self::LINEBREAK . self::LINEBREAK;

        $out .= '--' . $altBoundary . self::LINEBREAK;
        $out .= 'Content-Type: text/html; charset=' . self::CHARSET . self::LINEBREAK;
        $out .= 'Content-Transfer-Encoding: ' . self::TRANSFER_ENCODEING . self::LINEBREAK . self::LINEBREAK;
        $out .= $htmlpart . self::LINEBREAK . self::LINEBREAK;

        $out .= '--' . $altBoundary . '--' . self::LINEBREAK . self::LINEBREAK;

        if (count($this->attachments) > 0) {
            for ($i = 0; $i < count($this->attachments); $i++) {
                $attachment = chunk_split(base64_encode(file_get_contents($this->attachments[$i])));
                $filename = basename($this->attachments[$i]);
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $out .= '--' . $boundary . self::LINEBREAK;
                $out .= 'Content-Type: application/' . $ext . '; name="' . $filename . '"' . self::LINEBREAK;
                $out .= 'Content-Transfer-Encoding: base64' . self::LINEBREAK;
                $out .= 'Content-Disposition: attachment; filename="' . $filename . '"' . self::LINEBREAK . self::LINEBREAK;
                $out .= $attachment . self::LINEBREAK;
            }
        }

        $out .= '--' . $boundary . '--';

        ob_get_clean();
        return $out;
    }

    public static function realStripTags($text)
    {
        $text = preg_replace(
            [
                // Remove invisible content
                '@<head[^>]*?>.*?</head>@siu',
                '@<style[^>]*?>.*?</style>@siu',
                '@<script[^>]*?.*?</script>@siu',
                '@<object[^>]*?.*?</object>@siu',
                '@<embed[^>]*?.*?</embed>@siu',
                '@<applet[^>]*?.*?</applet>@siu',
                '@<noframes[^>]*?.*?</noframes>@siu',
                '@<noscript[^>]*?.*?</noscript>@siu',
                '@<noembed[^>]*?.*?</noembed>@siu',
                /*'@<input[^>]*?>@siu',*/
                '@<form[^>]*?.*?</form>@siu',

                // Add line breaks before & after blocks
                '@<((br)|(hr))>@iu',
                '@</?((address)|(blockquote)|(center)|(del))@iu',
                '@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))@iu',
                '@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))@iu',
                '@</?((table)|(th)|(td)|(caption))@iu',
                '@</?((form)|(button)|(fieldset)|(legend)|(input))@iu',
                '@</?((label)|(select)|(optgroup)|(option)|(textarea))@iu',
                '@</?((frameset)|(frame)|(iframe))@iu',
            ],
            [
                " ", " ", " ", " ", " ", " ", " ", " ", " ", " ",
                " ", "\n\$0", "\n\$0", "\n\$0", "\n\$0", "\n\$0",
                "\n\$0", "\n\$0",
            ],
            $text);

        $text = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $text);
        $text = preg_replace("/\n( )*/", "\n", $text);
        return strip_tags($text);
    }

    private function emailFrom($from)
    {
        $out = $from;
        $sPos = strrpos($from, ' ');
        if ($sPos > 0) {
            $out = substr($from, $sPos + 1);
            $out = str_replace("<", "", $out);
            $out = str_replace(">", "", $out);
        }
        return $out;
    }

    private function sendRecipients($r)
    {
        if (empty($r)) {
            return;
        }

        for ($i = 0; $i < count($r); $i++) {
            fputs($this->connection, 'RCPT TO: <' . $this->emailFrom($r[$i]) . '>' . self::LINEBREAK);
            $this->serverResponse();
        }
    }

    public function addRecipient($recipient)
    {
        $this->recipients[] = $recipient;
    }

    public function clearRecipients()
    {
        $this->recipients = [];
    }

    public function addCC($c)
    {
        $this->cc[] = $c;
    }

    public function clearCC()
    {
        $this->cc = [];
    }

    public function addBCC($bc)
    {
        $this->bcc[] = $bc;
    }

    public function clearBCC()
    {
        $this->bcc = [];
    }

    public function addAttachment($filePath)
    {
        $this->attachments[] = $filePath;
    }

    public function clearAttachments()
    {
        $this->attachments = [];
    }

    function __destruct()
    {
        fputs($this->connection, 'QUIT' . self::LINEBREAK);
        $this->serverResponse();
        fclose($this->connection);
    }

}