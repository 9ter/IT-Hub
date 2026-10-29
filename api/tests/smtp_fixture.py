"""Local-only SMTP sink for approval integration tests. Never relays mail."""
import email
import email.policy
import json
import re
import socket
import sys

listener = socket.socket()
listener.bind(('127.0.0.1', 0))
listener.listen()
print(listener.getsockname()[1], flush=True)
while True:
    conn, _ = listener.accept()
    with conn, conn.makefile('rwb') as stream:
        def reply(line):
            stream.write((line + '\r\n').encode())
            stream.flush()
        reply('220 localhost test sink')
        while raw := stream.readline():
            command = raw.decode().strip().upper()
            if command.startswith('EHLO'):
                reply('250-localhost\r\n250-AUTH PLAIN\r\n250 SIZE 1000000')
            elif command.startswith('AUTH'):
                if command == 'AUTH PLAIN':
                    reply('334 ')
                    stream.readline()
                reply('235 authenticated fixture')
            elif command == 'DATA':
                reply('354 send data')
                lines = []
                while (line := stream.readline()) not in (b'.\r\n', b''):
                    lines.append(line[1:] if line.startswith(b'..') else line)
                message = email.message_from_bytes(b''.join(lines), policy=email.policy.default)
                body = message.get_content()
                token = re.search(r'/approve#([a-f0-9]{64})', body)
                with open(sys.argv[1], 'w') as capture:
                    json.dump({'token': token[1] if token else None, 'to': str(message['To']), 'from': str(message['From']), 'subject': str(message['Subject']), 'body': body}, capture)
                reply('250 accepted by local sink')
            elif command == 'QUIT':
                reply('221 bye')
                break
            else:
                reply('250 OK')
