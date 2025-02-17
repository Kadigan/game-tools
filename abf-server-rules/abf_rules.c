#include <cstdio>
#include <string>
#include <cstring>
#include <arpa/inet.h>
#include <unistd.h>

int main(int argc, char *argv[]) {
	struct sockaddr_in serverAddress;
	struct timeval timeout;
	unsigned int pairCount;
	uint port = 27015;
	int answerLength, socketHandle, socketLength = sizeof(sockaddr_in);
	unsigned char reply[1400], replyString[1400], requestPacket[9] = {0xFF, 0xFF, 0xFF, 0xFF, 0x56, 0xFF, 0xFF, 0xFF, 0xFF};

	memset(&serverAddress, 0, socketLength);
	memset(&reply, 0, 1400);
	serverAddress.sin_family = AF_INET;

	timeout.tv_sec = 10; // timeout in seconds
	timeout.tv_usec = 0;

	if ( argc < 2 ){
		printf("Usage: %s IP <Port>\n", argv[0]);
		return 1;
	}

	// verify that we got an actual IP address here (and set it while we are at it)
	if ( 0 >= inet_pton(AF_INET, argv[1], &serverAddress.sin_addr) ){
		printf("Usage: %s IP <Port>\n", argv[0]);
		return 1;
	}

	// get the port, if specified
	if ( argc >= 3 ){
		// we got a port, too!
		char *p;
		port = strtol(argv[2], &p, 10);
		if ( 0 == port ){
			printf("Usage: %s IP <Port>\n", argv[0]);
			return 1;
		}
	}
	// set the port
	serverAddress.sin_port = htons(port);

	socketHandle = socket(AF_INET, SOCK_DGRAM, 0);
	if ( 0 > socketHandle ){
		printf("{\"result\":\"error\",\"msg\":\"Failed to create socket\"}");
		return 1;
	}

	// set our recvfrom() timeout
	setsockopt(socketHandle, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout));

	// send our initial query
	if ( 0 > sendto(socketHandle, requestPacket, sizeof(requestPacket), 0, (sockaddr *)&serverAddress, socketLength) ){
		printf("{\"result\":\"error\",\"msg\":\"Failed to send query\"}");
		close(socketHandle);
		return 1;
	}

	// get the reply
	answerLength = recvfrom(socketHandle, reply, 1400, 0, NULL, NULL);
	if ( 0 > answerLength ){
		printf("{\"result\":\"error\",\"msg\":\"Failed to receive reply (1)\"}");
		close(socketHandle);
		return 1;
	}

	// check if we got a challenge number to use
	if ( 9 == answerLength && 0x41 == reply[4] ){
		// we did!
		for(uint i = 0; i < 4; i++)
			requestPacket[i+5] = reply[i+5];

		// re-send with the provided challenge number
		if ( 0 > sendto(socketHandle, requestPacket, sizeof(requestPacket), 0, (sockaddr *)&serverAddress, socketLength) ){
			printf("{\"result\":\"error\",\"msg\":\"Failed to send query with challenge number\"}");
			close(socketHandle);
			return 1;
		}

		// get our reply after re-sending with the provided challenge number
		answerLength = recvfrom(socketHandle, reply, 1400, 0, NULL, NULL);
		if ( 0 > answerLength ){
			printf("{\"result\":\"error\",\"msg\":\"Failed to receive reply (2)\"}");
			close(socketHandle);
			return 1;
		}
	}

	// did we get a 0x45?
	if ( 5 > answerLength || 0x45 != reply[4] ){
		printf("{\"result\":\"error\",\"msg\":\"Unrecognized server reply\"}");
		close(socketHandle);
		return 1;
	}

	// we did! that's our A2S_RULES packet, now we need to parse it
	pairCount = (reply[6]<<8) | reply[5];
	// printf("Answer length: %i, string count: %i", answerLength, pairCount);
	// printHex(reply, answerLength);

	// here be hackery XD
	printf("{\"result\":\"success\",\"payload\":{");
	unsigned int stringPosition = 7;
	for(unsigned int i = 0; i < pairCount; i++){
		if ( stringPosition >= answerLength )
			break;

		printf("\"");
		for(unsigned int j = stringPosition; j < answerLength; j++){
			if ( 0x00 == reply[j] ){
				stringPosition = j+1;
				break;
			}
			printf("%c", reply[j]);
		}
		printf("\":\"");
		for(unsigned int j = stringPosition; j < answerLength; j++){
			if ( 0x00 == reply[j] ){
				stringPosition = j+1;
				break;
			}
			printf("%c", reply[j]);
		}
		printf("\"");

		if ( i+1 < pairCount )
			printf(",");
	}
	printf("}}");

	close(socketHandle);
	return 0;
}
