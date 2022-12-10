<?php register_shutdown_function(function(){
   global $socket;
   if ( !is_object($socket) ) return;
   $err = socket_last_error($socket);
   echo "{$err} - ".socket_strerror($err)."\n";
});

$serverAddress = "127.0.0.1";
$serverPort    = "15777";
$replyJSON     = false;

#####################################################
# Use:
# php sf-query.php <PORT> <IP> <reply with JSON 1/0>
#####################################################

if ( isset($argv[1]) )
   $serverPort = $argv[1];

if ( isset($argv[2]) )
   $serverAddress = $argv[2];

if ( isset($argv[3]) && $argv[3] == 1 )
   $replyJSON = true;

if ( !$replyJSON )
   echo "Querying {$serverAddress}:{$serverPort}\n\n";

$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ( false === $socket ) die();
socket_connect($socket, $serverAddress, $serverPort);

$result = @socket_send($socket, str_pad("", 10, chr(0)), 10, 0);
if ( false === $result )
   die("Failed to send data: ");

do {
   $reads = [$socket];
} while( socket_select($reads, $__x, $__y, 0, 500) < 1 );

$readLength = @socket_recv($socket, $readData, 17, 0);
if ( false === $readLength )
   die("ERR: failed to read from socket: ");
if ( 17 != $readLength )
   die("ERR: failed to read exactly 17 bytes, read {$readLength} bytes instead\n");

$readData = unpack("CID/CProtocolVersion/QIgnore/CServerState/VServerVersion/vBeaconPort", $readData);
if ( 1 != $readData['ID'] ){
   die("ERR: reply ID != 1\n");
   socket_close($socket); $socket = null; die();
}

if ( 0 != $readData['ProtocolVersion'] ){
   echo "ERR: unknown protocol version {$readData['ProtocolVersion']} != 0\n";
   socket_close($socket); $socket = null; die();
}

unset($readData['Ignore'], $readData['ID'], $readData['ProtocolVersion']);
switch($readData['ServerState']){
   case 1:
      $readData['ServerState'] = "Idle";
   break;

   case 2:
      $readData['ServerState'] = "Loading";
   break;

   case 3:
      $readData['ServerState'] = "Playing";
   break;

   default:
      $readData['ServerState'] = "UNKNOWN ({$readData['ServerState']})";
   break;
}

if ( $replyJSON ){
   echo json_encode($readData);
} else  foreach($readData as $field => $value)
   echo "{$field}: {$value}\n";

socket_close($socket); $socket = null; die();
