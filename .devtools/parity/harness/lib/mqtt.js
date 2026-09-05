'use strict';

// A minimal MQTT 3.1.1 subscriber.
//
// Hand-rolled rather than a dependency, for the same reason `.devtools/mqtt/` hand-rolls
// its stand-in: this needs to CONNECT, SUBSCRIBE with a wildcard, and read retained
// PUBLISH frames, which is about eighty lines, and adding an npm dependency to a test
// harness is a supply-chain input for something a fixed-length header can do.
//
// Lifted out of run-side-effects.js unchanged so that the year phase can ask the same
// questions of the broker at a checkpoint that the side-effects phase asks once. The two
// want different things from it — one booking against thousands — and neither wants its own
// copy of a packet encoder.

const net = require('net');

function encodeRemainingLength(n) {
	const bytes = [];
	do {
		let byte = n % 128;
		n = Math.floor(n / 128);
		if (n > 0) byte |= 0x80;
		bytes.push(byte);
	} while (n > 0);
	return Buffer.from(bytes);
}

function encodeString(s) {
	const body = Buffer.from(s, 'utf8');
	const length = Buffer.alloc(2);
	length.writeUInt16BE(body.length, 0);
	return Buffer.concat([length, body]);
}

function connectPacket(clientId) {
	const payload = Buffer.concat([
		encodeString('MQTT'),
		Buffer.from([0x04]),        // protocol level 4 = 3.1.1
		Buffer.from([0x02]),        // clean session
		Buffer.from([0x00, 0x3c]),  // keepalive 60s
		encodeString(clientId)
	]);
	return Buffer.concat([Buffer.from([0x10]), encodeRemainingLength(payload.length), payload]);
}

function subscribePacket(topicFilter, packetId) {
	const payload = Buffer.concat([
		Buffer.from([(packetId >> 8) & 0xff, packetId & 0xff]),
		encodeString(topicFilter),
		Buffer.from([0x00]) // QoS 0
	]);
	return Buffer.concat([Buffer.from([0x82]), encodeRemainingLength(payload.length), payload]);
}

// Reads frames until `quietMs` passes with nothing new. Retained messages arrive
// immediately on subscribe, so a short quiet period is the correct end condition — waiting
// a fixed time would make the suite slower for no extra evidence.
function collectRetained(host, port, topicFilter, quietMs = 1500, hardTimeoutMs = 15000) {
	return new Promise((resolve, reject) => {
		const messages = [];
		let buffer = Buffer.alloc(0);
		let quietTimer = null;
		const socket = net.createConnection({ host, port });

		const finish = () => {
			clearTimeout(quietTimer);
			clearTimeout(hardTimer);
			socket.destroy();
			resolve(messages);
		};
		const bump = () => {
			clearTimeout(quietTimer);
			quietTimer = setTimeout(finish, quietMs);
		};
		const hardTimer = setTimeout(finish, hardTimeoutMs);

		socket.on('error', (e) => {
			clearTimeout(quietTimer);
			clearTimeout(hardTimer);
			reject(e);
		});

		socket.on('connect', () => {
			socket.write(connectPacket(`parity-suite-${process.pid}`));
		});

		socket.on('data', (chunk) => {
			buffer = Buffer.concat([buffer, chunk]);

			for (;;) {
				if (buffer.length < 2) break;

				// Decode the variable-length remaining-length field.
				let multiplier = 1;
				let remaining = 0;
				let i = 1;
				let byte;
				do {
					if (i >= buffer.length) return;
					byte = buffer[i++];
					remaining += (byte & 127) * multiplier;
					multiplier *= 128;
				} while ((byte & 0x80) !== 0);

				const total = i + remaining;
				if (buffer.length < total) break;

				const type = buffer[0] >> 4;
				const flags = buffer[0] & 0x0f;
				const frame = buffer.subarray(i, total);
				buffer = buffer.subarray(total);

				if (type === 2) {            // CONNACK
					socket.write(subscribePacket(topicFilter, 1));
					bump();
				} else if (type === 9) {     // SUBACK
					bump();
				} else if (type === 3) {     // PUBLISH
					const topicLength = frame.readUInt16BE(0);
					const topic = frame.subarray(2, 2 + topicLength).toString('utf8');
					// QoS 0 only — the publisher uses it, so there is no packet id here.
					const payload = frame.subarray(2 + topicLength).toString('utf8');
					messages.push({ topic, payload, retained: (flags & 0x01) === 1 });
					bump();
				}
			}
		});
	});
}

module.exports = { collectRetained, connectPacket, subscribePacket, encodeString, encodeRemainingLength };
