<?php
/**
 * Created by PhpStorm.
 * User: mfr
 * Date: 19-9-23
 * Time: 下午3:41
 */

namespace App\Http\Controllers\v1_0;


use function Couchbase\defaultDecoder;

class ceshi
{
    //这是根据ip去区分的多人聊天
    private $address;  //这是ip
    private $port;     //这是端口号
    private $_sockets; //这是声明的保存socket信息的公共变量
    public function __construct($address = '' , $port = '')
    {
        if (!empty($address)){
            $this->address = $address;
        }
        if (!empty($port)){
            $this->port = $port;
        }
    }

    public function service()
    {
       $tcp = getprotobyname("tcp");  //获取tcp协议的信息
       $sock = socket_create(AF_INET, SOCK_STREAM, $tcp); //创建一个socket请求链接
       socket_set_option($sock,SOL_SOCKET, SO_REUSEADDR, 1); //对创建完的socket进行选项设置
       if($sock < 0){
           throw new Exception("failed to create socket: ".socket_strerror($sock)."\n");
       }
       socket_bind($sock, $this->address, $this->port); //把创建好的socket绑定在指定的ip和端口上
       socket_listen($sock,$this->port); //开始监听端口
       echo "listen on $this->address $this->port ... \n";
       $this->_sockets = $sock; //把创建好并且设置完选项和监听端口的socket赋给_sockets变量
       // 因为这个$this->_sockets变量不是用的数组 , 所以是单个两条
    }

    public function run()
    {
        $this->service(); //调用创建和监听的方法
        $clients[] = $this->_sockets; //在这里调用service()方法中用socket设置的数据赋值给_sockets这个全局变量
        while (true){
            $changes = $clients;
            $write = NULL;
            $except = NULL;
            //1.运行select()系统调用在给定阵列插座与指定的超时,作用是保存可以操作的socket链接
            //2.没有接收到数据就会一直处于阻塞状态,
            //3.若没有client过来,直阻塞进程,直到有client访问,返回1。
            //4.此时返回的changes,不是曾经的changes。虽然还只是一条记录,但已经不是服务器而是客户端

            /*select的特殊作用：！！！！！！！
            初始为array(0=>resource(2, Socket))
            1,初始状态返回为array(0=>resource(2, Socket))。但socket_accept可以得到resource(3, Socket)
            2,初始状态返回为array(0=>resource(2, Socket),1=>resource(3,Socket))。
            客户来的客户为resource(3,Socket)。则返回的数据为resource(3,Socket).!!!
            */
            socket_select($changes,  $write,  $except, NULL);
            foreach ($changes as $key => $_sock){
                if($this->_sockets == $_sock){ //这里是保存客户端发送的和服务端回复的信息
                    if(($newClient = socket_accept($_sock))  === false){ //如果错误的socket就报错
                        die('failed to accept socket: '.socket_strerror($_sock)."\n");
                    }
                    $line = trim(socket_read($newClient, 1024)); //按照指定数据长度length参数去取出客户端发给服务端socket里的数据
                    $this->handshaking($newClient, $line); //调用的这个自己封装的方法 , 把取出来的数据和header参数都缓存到socket中
                    //获取client ip
                    socket_getpeername ($newClient, $ip); //获取到客户端的主机ip地址,写进数组
                    $clients[$ip] = $newClient;
                    echo "Client ip:{$ip}    \n";
                    echo "Client msg:{$line} \n";
                } else {   //else里才是服务器和客户端通信的逻辑写在这
                    socket_recv($_sock, $buffer,  2048, 0); //读取消息
                    $msg = $this->message($buffer);
                    //在这里业务代码
                    echo "{$key} clinet msg:",$msg,"\n";
                    fwrite(STDOUT, 'Please input a argument:');
                    $response = trim(fgets(STDIN));
                    $this->send($_sock, $response);
                    echo "{$key} response to Client:".$response,"\n";
                }
            }
        }
    }

    /**
      * 握手处理
      * @param $newClient socket
      * @return int  接收到的信息
    */
    public function handshaking($newClient, $line)
    {

        $headers = array();
        $lines = preg_split("/\r\n/", $line);
        foreach($lines as $line)
        {
            $line = chop($line);
            if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
            {
                $headers[$matches[1]] = $matches[2];
            }
        }
        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "WebSocket-Origin: $this->address\r\n" .
            "WebSocket-Location: ws://$this->address:$this->port/websocket/websocket\r\n".
            "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
        return socket_write($newClient, $upgrade, strlen($upgrade));
    }

    /**
     * 解析接收数据
     * @param $buffer
     * @return null|string
    */
    public function message($buffer)
    {
        $len = $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127)  {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else  {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }

    /**
     * 发送数据
     * @param $newClinet 新接入的socket
     * @param $msg   要发送的数据
     * @return int|string
    */
    public function send($newClinet, $msg)
    {
        $msg = $this->frame($msg);
        socket_write($newClinet, $msg, strlen($msg));
    }

    public function frame($s)
    {
        $a = str_split($s, 125);
        if (count($a) == 1) {
            return "\x81" . chr(strlen($a[0])) . $a[0];
        }
        $ns = "";
        foreach ($a as $o) {
            $ns .= "\x81" . chr(strlen($o)) . $o;
        }
        return $ns;
    }

    /**
     * 关闭socket
     */
    public function close()
    {
        return socket_close($this->_sockets);
    }

}

$sock = new ceshi('127.0.0.1','9501');
$sock->run();