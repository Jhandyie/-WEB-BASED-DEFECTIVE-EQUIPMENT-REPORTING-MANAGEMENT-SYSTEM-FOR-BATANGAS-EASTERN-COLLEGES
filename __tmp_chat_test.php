<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$payload = json_encode(['messages' => [['role' => 'user', 'content' => 'hello']]]);
$stream = fopen('php://memory', 'r+');
fwrite($stream, $payload);
rewind($stream);
class MockPhpStream {
    public $context;
    private static $data = '';
    private $index = 0;
    public static function setData($data) { self::$data = $data; }
    public function stream_open($path, $mode, $options, &$opened_path) { $this->index = 0; return true; }
    public function stream_read($count) { $ret = substr(self::$data, $this->index, $count); $this->index += strlen($ret); return $ret; }
    public function stream_eof() { return $this->index >= strlen(self::$data); }
    public function stream_stat() { return []; }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'MockPhpStream');
MockPhpStream::setData($payload);
ob_start();
include 'c:\xampp\htdocs\bec_equipment\chat_proxy.php';
$output = ob_get_clean();
stream_wrapper_restore('php');
echo $output;
?>
