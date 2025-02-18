<?php declare(strict_types=1);

$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ( false === $s )
	exit("Failed creating UDP socket\n");

echo "Connecting to server... ";
if ( !socket_connect($s, "10.0.1.22", 25010) ){ // string Address, int QueryPort
	$err = socket_last_error($s);
	socket_close($s);
	exit("ERR: ({$err}) ".socket_strerror($err)."\n");
}
echo "OK\n";

echo "Sending A2S_RULES query with empty challenge number... ";
$written = socket_write($s, pack("VaV", 0xFFFFFFFF, "V", 0xFFFFFFFF));
if ( !$written ){
	socket_close($s);
	exit("ERR: failed to write\n");
}
echo "OK\n";

$reply = socket_read($s, 1400);
if ( substr($reply, 4, 1) == "A" ){
	// received a challenge number!
	$challengeNumber = unpack("V", substr($reply, 5, 4))[1];
	echo "Received challenge number {$challengeNumber}.\n";

	echo "Resending A2S_RULES query with the challenge number... ";
	$written = socket_write($s, pack("VaV", 0xFFFFFFFF, "V", $challengeNumber));
	if ( !$written ){
		socket_close($s);
		exit("ERR: failed to write\n");
	}
	echo "OK\n";

	$reply = socket_read($s, 1400);
}
print_hex($reply);
socket_close($s);
exit();

##########################################################################################################################################################
function print_hex(string $s) : void {
	$pos = 0;
	foreach(str_split($s, 16) as $line){
		echo str_pad(dechex($pos), 4, "0", STR_PAD_LEFT)."   ";
		$hex = [[], []];
		$str = ["", ""];
		foreach(str_split($line, 8) as $setNum => $byteSet){
			for($i = 0; $i < strlen($byteSet); $i++){
				$char = substr($byteSet, $i, 1);
				$hex[$setNum][] = str_pad(strtoupper(dechex(ord( $char ))), 2, "0", STR_PAD_LEFT);
				$str[$setNum] .= (preg_match('/[^\x20-\x7e]/', $char) ? "." : $char);
			}
		}
		echo str_pad(implode(" ", $hex[0]), 23, " ")."   ".str_pad(implode(" ", $hex[1]), 23, " ")."   ";
		echo str_pad($str[0], 8, " ")."  ".str_pad($str[1], 8, " ")."\n";
		$pos += 16;
	}
	return;
}
