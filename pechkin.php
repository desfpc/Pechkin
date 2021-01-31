<?php
/**
 * Pechkin v0.1
 * Created by Sergey Peshalov https://github.com/desfpc
 * Lite php SMTP mail send class
 * https://github.com/desfpc/Pechkin
 *
 */

namespace pechkin;

class pechkin {

    const LOCALHOST = 'localhost';
    const LINEBREAK = '\r\n';

    //mail server data
    public $server;
    public $port;
    public $username;
    public $password;
    public $secure; //ssl, tls or none
    private $timeout; //connection timeout in seconds

    //if user authorized
    public $authorized = false;

    //mail server connection socket
    private $connection;

    //debug mode
    public $debug = false;

    public function __construct($server, $port, $username, $password, $secure=false, int $timeout = 60, bool $debug = false) {

        $this->server = $server;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        if($secure){
            $this->secure = strtolower(trim($secure));
        }
        else{
            $this->secure = 'none';
        }
        $this->timeout = $timeout;
        $this->debug = $debug;

        if(!$this->serverConnect()) return;
        if(!$this->serverAuthorize()) return;

    }

    //mail server authorization
    private function serverAuthorize(){

        fputs($this->connection, 'HELO '.self::LOCALHOST.self::LINEBREAK);
        $this->serverResponse();

        if($this->secure == 'tls'){
            fputs($this->connection, 'STARTTLS'.self::LINEBREAK);
            if($this->falseResponse($this->serverResponse(), 3, '200')) return false;
            stream_socket_enable_crypto($this->connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fputs($this->connection, 'HELO '.self::LOCALHOST.self::LINEBREAK);
            if($this->falseResponse($this->serverResponse(), 3, '250')) return false;
        }

        if($this->server != 'localhost'){
            fputs($this->connection, 'AUTH LOGIN'.self::LINEBREAK);
            if($this->falseResponse($this->serverResponse(), 3, '334')) return false;

            fputs($this->connection, base64_encode($this->username).self::LINEBREAK);
            if($this->falseResponse($this->serverResponse(), 3, '334')) return false;

            fputs($this->connection, base64_encode($this->password).self::LINEBREAK);
            if($this->falseResponse($this->serverResponse(), 3, '235')) return false;
        }
        $this->authorized = true;
        return true;
    }

    //mail server response
    private function serverResponse(){
        $out = '';
        while($str = fgets($this->connection, 4096)){
            $out.=$str;
            if(substr($str,3,1) == ' '){
                break;
            }
        }
        if($this->debug){
            echo '<hr>mail server response: <br>'.$out.'<hr>';
        }
        return $out;
    }

    //if mail server response is false
    private function falseResponse($response, $len, $code){
        if(substr($response, 0, $len) != $code) return true;
        return false;
    }

    //connect to mail server
    public function serverConnect(){

        if($this->secure == 'ssl'){
            $this->server = 'ssl://'.$this->server;
        }

        $this->connection = fsockopen($this->server, $this->port, $errno, $errstr, $this->timeout);
        if($this->falseResponse($this->serverResponse(), 3, '220')) return false;

        return true;
    }

}