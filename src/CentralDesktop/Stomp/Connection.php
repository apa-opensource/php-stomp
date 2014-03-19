<?php
/**
 *
 * Copyright 2005-2006 The Apache Software Foundation
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace CentralDesktop\Stomp;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;


/**
 * A Stomp Connection
 *
 *
 * @package Stomp
 * @author  Hiram Chirino <hiram@hiramchirino.com>
 * @author  Dejan Bosanac <dejan@nighttale.net>
 * @author  Michael Caplan <mcaplan@labnet.net>
 * @version $Revision: 43 $
 */
class Connection implements LoggerAwareInterface {
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Perform request synchronously
     *
     * @var boolean
     */
    public $sync = false;


    /**
     * Default prefetch size
     *
     * @var int
     */
    public $prefetchSize = 1;

    /**
     * Client id used for durable subscriptions
     *
     * @var string
     */
    public $clientId = null;

    protected $_brokerUri = null;
    protected $_socket = null;
    protected $_hosts = array();
    protected $_params = array();
    protected $_subscriptions = array();
    protected $_defaultPort = 61613;
    protected $_currentHost = -1;
    protected $_attempts = 10;
    protected $_username = '';
    protected $_password = '';
    protected $_sessionId;
    protected $_read_timeout_seconds = 60;
    protected $_read_timeout_milliseconds = 0;
    protected $_connect_timeout_seconds = 0.25;
    protected $_tcp_buffer_size = 1024;

    private $message_count = 0;
    private $read_buffer = '';

    /**
     * Constructor
     *
     * @param string $brokerUri Broker URL
     *
     * @throws Exception
     */
    public
    function __construct($brokerUri) {
        $this->_brokerUri = $brokerUri;
        $this->_init();

        $this->logger = new NullLogger();
    }

    /**
     * Initialize connection
     *
     * @throws Exception
     */
    protected
    function _init() {
        $pattern = "|^(([a-zA-Z]+)://)+\(*([a-zA-Z0-9\.:/i,-]+)\)*\??([a-zA-Z0-9=]*)$|i";
        if (preg_match($pattern, $this->_brokerUri, $regs)) {
            $scheme = $regs[2];
            $hosts  = $regs[3];
            $params = $regs[4];
            if ($scheme != "failover") {
                $this->_processUrl($this->_brokerUri);
            }
            else {
                $urls = explode(",", $hosts);
                foreach ($urls as $url) {
                    $this->_processUrl($url);
                }
            }
            if ($params != null) {
                parse_str($params, $this->_params);
            }
        }
        else {
            throw new Exception("Bad Broker URL {$this->_brokerUri}");
        }
    }

    /**
     * Process broker URL
     *
     * @param string $url Broker URL
     *
     * @throws Exception
     * @return boolean
     */
    protected
    function _processUrl($url) {
        $parsed = parse_url($url);
        if ($parsed) {
            array_push($this->_hosts, array($parsed['host'], $parsed['port'], $parsed['scheme']));
        }
        else {
            throw new Exception("Bad Broker URL $url");
        }
    }

    /**
     * Make socket connection to the server
     *
     * @throws Exception
     */
    protected
    function _makeConnection() {
        if (count($this->_hosts) == 0) {
            throw new Exception("No broker defined");
        }

        // force disconnect, if previous established connection exists
        $this->disconnect();

        $i              = $this->_currentHost;
        $att            = 0;
        $connected      = false;
        $connect_errno  = null;
        $connect_errstr = null;

        while (!$connected && $att++ < $this->_attempts) {
            if (isset($this->_params['randomize']) && $this->_params['randomize'] == 'true') {
                $i = rand(0, count($this->_hosts) - 1);
            }
            else {
                $i = ($i + 1) % count($this->_hosts);
            }
            $broker = $this->_hosts[$i];
            $host   = $broker[0];
            $port   = $broker[1];
            $scheme = $broker[2];
            if ($port == null) {
                $port = $this->_defaultPort;
            }
            if ($this->_socket != null) {
                fclose($this->_socket);
                $this->_socket = null;
            }

            $this->connectedHost = $host;
            $this->_socket       = @fsockopen($scheme . '://' . $host, $port, $connect_errno, $connect_errstr, $this->_connect_timeout_seconds);
            if (!is_resource($this->_socket) && $att >= $this->_attempts && !array_key_exists($i + 1, $this->_hosts)) {
                throw new Exception("Could not connect to $host:$port ($att/{$this->_attempts})");
            }
            elseif (is_resource($this->_socket)) {
                $connected          = true;
                $this->_currentHost = $i;
                break;
            }

        }
        if (!$connected) {
            throw new Exception("Could not connect to a broker");
        }
    }

    /**
     * Connect to server
     *
     * @param string $username
     * @param string $password
     *
     * @return boolean
     * @throws Exception
     */
    public
    function connect($username = '', $password = '', $version = '1.0') {
        $this->_makeConnection();
        if ($username != '') {
            $this->_username = $username;
        }
        if ($password != '') {
            $this->_password = $password;
        }
        $headers = array('login' => $this->_username, 'passcode' => $this->_password);
        if ($this->clientId != null) {
            $headers["client-id"] = $this->clientId;
        }

        if ($version != '1.0') {
            $headers['accept-version'] = $version;
            $headers['host']           = $this->connectedHost;
        }

        $frame = new Frame("CONNECT", $headers);
        $this->_writeFrame($frame);
        $frame = $this->readFrame();
        if ($frame instanceof Frame && $frame->command == 'CONNECTED') {
            $this->_sessionId = $frame->headers['session'];
            $this->_version = array_key_exists('version', $frame->headers) ?
                ($frame->headers['version']+0.0) :
                1.0;

            return true;
        }
        elseif ($frame instanceof Frame) {
            throw new Exception("Unexpected command: {$frame->command}", 0, $frame->body);
        }
        else {
            throw new Exception("Connection not acknowledged");
        }
    }

    /**
     * Check if client session has ben established
     *
     * @return boolean
     */
    public
    function isConnected() {
        return !empty($this->_sessionId) && is_resource($this->_socket);
    }

    /**
     * Current stomp session ID
     *
     * @return string
     */
    public
    function getSessionId() {
        return $this->_sessionId;
    }

    /**
     * Send a message to a destination in the messaging system
     *
     * @param string            $destination Destination queue
     * @param string|Frame      $msg         Message
     * @param array             $properties
     * @param boolean           $sync        Perform request synchronously
     *
     * @return boolean
     */
    public
    function send($destination, $msg, $properties = array(), $sync = null) {
        $this->logger->debug("Sending message to $destination");

        if ($msg instanceof Frame) {
            $msg->headers['destination'] = $destination;
            if (is_array($properties)) $msg->headers = array_merge($msg->headers, $properties);
            $frame = $msg;
        }
        else {
            $headers                = $properties;
            $headers['destination'] = $destination;
            $frame                  = new Frame('SEND', $headers, $msg);
        }
        $this->_prepareReceipt($frame, $sync);


        $this->_writeFrame($frame);


        return $this->_waitForReceipt($frame, $sync);
    }

    /**
     * Prepair frame receipt
     *
     * @param Frame      $frame
     * @param boolean    $sync
     */
    protected
    function _prepareReceipt(Frame $frame, $sync) {
        $receive = $this->sync;
        if ($sync !== null) {
            $receive = $sync;
        }
        if ($receive == true) {
            $frame->headers['receipt'] = md5(microtime());
        }
    }

    /**
     * Wait for receipt
     *
     * @param Frame      $frame
     * @param boolean    $sync
     *
     * @return boolean
     * @throws Exception
     */
    protected
    function _waitForReceipt(Frame $frame, $sync) {

        $receive = $this->sync;
        if ($sync !== null) {
            $receive = $sync;
        }
        if ($receive == true) {
            $id = (isset($frame->headers['receipt'])) ? $frame->headers['receipt'] : null;
            if ($id == null) {
                return true;
            }
            $frame = $this->readFrame();
            if ($frame instanceof Frame && $frame->command == 'RECEIPT') {
                if ($frame->headers['receipt-id'] == $id) {
                    return true;
                }
                else {
                    throw new Exception("Unexpected receipt id {$frame->headers['receipt-id']}", 0, $frame->body);
                }
            }
            else {
                if ($frame instanceof Frame) {
                    throw new Exception("Unexpected command {$frame->command}", 0, $frame->body);
                }
                else {
                    throw new Exception("Receipt not received");
                }
            }
        }

        return true;
    }

    /**
     * Register to listen to a given destination
     *
     * @param string  $destination Destination queue
     * @param array   $properties
     * @param boolean $sync        Perform request synchronously
     *
     * @return boolean
     * @throws Exception
     */
    public
    function subscribe($destination, $properties = null, $sync = null) {
        $headers = array(
            'ack' => 'client-individual',
            'id'  => 0,
        );

        $headers['activemq.prefetchSize'] = $this->prefetchSize;
        if ($this->clientId != null) {
            $headers["activemq.subcriptionName"] = $this->clientId;
        }
        if (isset($properties)) {
            foreach ($properties as $name => $value) {
                $headers[$name] = $value;
            }
        }
        $headers['destination'] = $destination;
        $frame                  = new Frame('SUBSCRIBE', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);
        if ($this->_waitForReceipt($frame, $sync) == true) {
            $this->_subscriptions[$destination] = $properties;

            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Remove an existing subscription
     *
     * @param string  $destination
     * @param array   $properties
     * @param boolean $sync Perform request synchronously
     *
     * @return boolean
     * @throws Exception
     */
    public
    function unsubscribe($destination, $properties = null, $sync = null) {
        $headers = array();
        if (isset($properties)) {
            foreach ($properties as $name => $value) {
                $headers[$name] = $value;
            }
        }
        $headers['destination'] = $destination;
        $frame                  = new Frame('UNSUBSCRIBE', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);
        if ($this->_waitForReceipt($frame, $sync) == true) {
            unset($this->_subscriptions[$destination]);

            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Start a transaction
     *
     * @param string  $transactionId
     * @param boolean $sync Perform request synchronously
     *
     * @return boolean
     * @throws Exception
     */
    public
    function begin($transactionId = null, $sync = null) {
        $headers = array();
        if (isset($transactionId)) {
            $headers['transaction'] = $transactionId;
        }
        $frame = new Frame('BEGIN', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);

        return $this->_waitForReceipt($frame, $sync);
    }

    /**
     * Commit a transaction in progress
     *
     * @param string  $transactionId
     * @param boolean $sync Perform request synchronously
     *
     * @return boolean
     * @throws Exception
     */
    public
    function commit($transactionId = null, $sync = null) {
        $headers = array();
        if (isset($transactionId)) {
            $headers['transaction'] = $transactionId;
        }
        $frame = new Frame('COMMIT', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);

        return $this->_waitForReceipt($frame, $sync);
    }

    /**
     * Roll back a transaction in progress
     *
     * @param string  $transactionId
     * @param boolean $sync Perform request synchronously
     */
    public
    function abort($transactionId = null, $sync = null) {
        $headers = array();
        if (isset($transactionId)) {
            $headers['transaction'] = $transactionId;
        }
        $frame = new Frame('ABORT', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);

        return $this->_waitForReceipt($frame, $sync);
    }

    /**
     * Acknowledge consumption of a message from a subscription
     * Note: This operation is always asynchronous
     *
     * @param string|Frame      $messageMessage ID
     * @param string            $transactionId
     *
     * @return boolean
     * @throws Exception
     */
    public
    function ack($message, $transactionId = null) {
        if ($message instanceof Frame) {
            $headers = $message->headers;

            $ack_headers = array(
                'subscription' => $headers['subscription'],
                'message-id'   => $headers['message-id']
            );

            if ($this->_version > 1.1) {
                $ack_headers['id'] = $headers['ack'];
            }

            if (isset($transactionId)) {
                $ack_headers['transaction'] = $transactionId;
            }

            $this->logger->info("ACK Frame for -> ", $ack_headers);
            $frame = new Frame('ACK', $ack_headers);
            $this->_writeFrame($frame);

            return true;
        }
        else {
            $headers = array();
            if (isset($transactionId)) {
                $headers['transaction'] = $transactionId;
            }

            $headers['message-id'] = $message;
            $this->logger->info("ACK ID -> ", $headers);

            $frame = new Frame('ACK', $headers);
            $this->_writeFrame($frame);

            return true;
        }
    }


    /**
     * DON'T Acknowledge consumption of a message from a subscription
     *
     * @param string|Frame $messageMessage ID
     * @param string       $transactionId
     *
     * @return boolean
     * @throws Exception
     */
    public
    function nack($message, $transactionId = null) {
        if ($message instanceof Frame) {
            $headers = $message->headers;
            if (isset($transactionId)) {
                $headers['transaction'] = $transactionId;
            }
            $frame = new Frame('NACK', $headers);
            $this->_writeFrame($frame);

            return true;
        }
        else {
            $headers = array();
            if (isset($transactionId)) {
                $headers['transaction'] = $transactionId;
            }
            $headers['message-id'] = $message;
            $frame                 = new Frame('NACK', $headers);
            $this->_writeFrame($frame);

            return true;
        }
    }


    /**
     * Graceful disconnect from the server
     *
     */
    public
    function disconnect() {
        $headers = array();

        if ($this->clientId != null) {
            $headers["client-id"] = $this->clientId;
        }

        if (is_resource($this->_socket)) {
            $this->_writeFrame(new Frame('DISCONNECT', $headers), false);
            fclose($this->_socket);
        }
        $this->_socket        = null;
        $this->_sessionId     = null;
        $this->_currentHost   = -1;
        $this->_subscriptions = array();
        $this->_username      = '';
        $this->_password      = '';
    }

    /**
     * Write frame to server
     *
     * @param Frame $stompFrame
     */
    protected
    function _writeFrame(Frame $stompFrame, $reconnect = true) {
        if (!is_resource($this->_socket)) {
            throw new Exception('Socket connection hasn\'t been established');
        }

        $data = $stompFrame->__toString();

        $this->logger->debug("Sending Frame", array('frame' => $data));
        $r = fputs($this->_socket, $data, strlen($data));

        if (($r === false || $r == 0) && $reconnect) {
            $this->_reconnect();
            $this->_writeFrame($stompFrame);
        }
    }

    /**
     * Set timeout to wait for content to read
     *
     * @param int $seconds_to_wait  Seconds to wait for a frame
     * @param int $milliseconds     Milliseconds to wait for a frame
     */
    public
    function setReadTimeout($seconds, $milliseconds = 0) {
        $this->_read_timeout_seconds      = $seconds;
        $this->_read_timeout_milliseconds = $milliseconds;
    }

    /**
     * Set the TCP buffer size, this should match the buffer size of your STOMP server
     *
     * @param int $bytes The size of your TCP buffer
     */
    public
    function setBufferSize($bytes) {
        $this->_tcp_buffer_size = $bytes;
    }

    /**
     * Read response frame from server
     *
     * @return Frame False when no frame to read
     */
    public
    function readFrame() {
        if (!$this->hasFrameToRead()) {
            return false;
        }

        $rb = $this->_tcp_buffer_size;

        $read = "";

        do {
            if($read === "" && feof($this->_socket)) {
                $this->_reconnect();

                return $this->readFrame();
            }

            $this->_appendToBuffer(fgets($this->_socket, $rb));

            if($this->_bufferContainsMessage())
                break;
        } while(1);

        $data = $this->_extractNextMessage();

        $this->logger->debug("Read frame", array('frame' => $data));


        list ($header, $body) = explode("\n\n", $data, 2);
        $header  = explode("\n", $header);
        $headers = array();
        $command = null;
        foreach ($header as $v) {

            if (isset($command)) {
                list ($name, $value) = explode(':', $v, 2);
                $headers[$name] = $value;
            }
            else {
                $command = $v;
            }
        }
        $frame = new Frame($command, $headers, trim($body));

        if (isset($frame->headers['transformation']) &&
            ($frame->headers['transformation'] == 'jms-map-xml' ||
             $frame->headers['transformation'] == 'jms-map-json')
        ) {
            return new Message\Map($frame, $headers);
        }
        else {
            return $frame;
        }

        return $frame;
    }

    /**
     * This should only be called by unit tests.
     * Don't use it the buffer manipulation functions, because the buffer is potentially a large string.
     *
     * @return string
     */
    public
    function _getBuffer() {
        return ($this->read_buffer);
    }

    /**
     * This is for debugging, since I can't error log Ascii NUL.
     *
     * @return string
     */
    /**
     * Append a new packet to the read buffer
     *
     * @param string Raw bytes received from the MQ server
     */
    public
    function _appendToBuffer($packet) {
        $this->read_buffer .= $packet;
    }

    /**
     * Does the read buffer contain a complete message?
     *
     * @return boolean
     */
    public
    function _bufferContainsMessage() {
        return (strpos($this->read_buffer, "\x00") !== false);
    }

    /**
     * Return the next message in the buffer.  The message is removed from the buffer.
     *
     * @return string The next message, or '' if there isn't a message in the buffer.
     */
    public
    function _extractNextMessage() {
        $message = '';
        if ($this->_bufferContainsMessage()) {
            $end_of_message    = strpos($this->read_buffer, "\x00");
            $message           = substr($this->read_buffer, 0, $end_of_message); // Fetch the message, leave the Ascii NUL
            $message           = ltrim($message, "\n");
            $this->read_buffer = substr($this->read_buffer, $end_of_message + 1); // Delete the message, including the Ascii NUL
        }

        return ($message);
    }

    /**
     * Check if there is a frame to read
     *
     * @return boolean
     */
    public
    function hasFrameToRead() {
        $read   = array($this->_socket);
        $write  = null;
        $except = null;

        $has_frame_to_read = @stream_select($read, $write, $except, $this->_read_timeout_seconds, $this->_read_timeout_milliseconds);

        if ($has_frame_to_read !== false) {
            $has_frame_to_read = count($read);
        }
        if ($has_frame_to_read === false) {
            throw new Exception('Check failed to determine if the socket is readable');
        }
        else {
            if ($has_frame_to_read > 0) {
                return true;
            }
            else {
                return false;
            }
        }
    }

    /**
     * Reconnects and renews subscriptions (if there were any)
     * Call this method when you detect connection problems
     */
    protected
    function _reconnect() {
        $subscriptions = $this->_subscriptions;

        $this->connect($this->_username, $this->_password);
        foreach ($subscriptions as $dest => $properties) {
            $this->subscribe($dest, $properties);
        }
    }

    /**
     * Graceful object desruction
     *
     */
    public
    function __destruct() {
        $this->disconnect();
    }

    /**
     * Sets a logger instance on the object
     *
     * @param LoggerInterface $logger
     *
     * @return null
     */
    public
    function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }
}

?>
