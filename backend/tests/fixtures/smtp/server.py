"""本地 SMTP 验收服务：不向外投递，不记录 AUTH 原文。"""
import base64
import json
import socket
import ssl
import sys
from pathlib import Path

mode, capture = sys.argv[1:3]
listener = socket.socket()
listener.bind(('127.0.0.1', 0))
listener.listen(5)
print(listener.getsockname()[1], flush=True)
context = None
if len(sys.argv) > 3:
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(sys.argv[3], sys.argv[4])
while True:
    conn, _ = listener.accept()
    try:
        if mode == 'ssl':
            conn = context.wrap_socket(conn, server_side=True)
        conn.settimeout(5)
        stream = conn.makefile('rwb', buffering=0)
        def reply(line):
            stream.write((line + '\r\n').encode())
        reply('220 localhost ESMTP fixture')
        authed = False
        to = ''
        sender = ''
        while True:
            raw = stream.readline()
            if not raw:
                break
            line = raw.decode().strip()
            verb = line.split(' ', 1)[0].upper()
            if verb in ('EHLO', 'HELO'):
                reply('250-localhost')
                if mode == 'tls' and not isinstance(conn, ssl.SSLSocket):
                    reply('250-STARTTLS')
                reply('250 AUTH LOGIN PLAIN')
            elif verb == 'STARTTLS':
                reply('220 Ready to start TLS')
                stream.close()
                conn = context.wrap_socket(conn, server_side=True)
                stream = conn.makefile('rwb', buffering=0)
            elif verb == 'AUTH':
                if 'LOGIN' in line:
                    reply('334 VXNlcm5hbWU6')
                    user = base64.b64decode(stream.readline().strip()).decode()
                    reply('334 UGFzc3dvcmQ6')
                    password = base64.b64decode(stream.readline().strip()).decode()
                else:
                    auth = line.split(' ', 2)
                    if len(auth) < 3:
                        reply('334 ')
                        value = stream.readline().strip()
                    else:
                        value = auth[2].encode()
                    _, user, password = base64.b64decode(value).decode().split('\0')
                authed = user == 'fixture-user' and password == 'fixture-secret'
                reply('535 Authentication failed' if mode == 'reject_auth' or not authed else '235 Authentication successful')
            elif verb == 'MAIL':
                sender = line[5:]
                reply('250 Sender accepted')
            elif verb == 'RCPT':
                to = line[5:]
                reply('250 Recipient accepted')
            elif verb == 'DATA':
                reply('354 End with dot')
                lines = []
                while True:
                    data = stream.readline()
                    if not data or data == b'.\r\n':
                        break
                    lines.append(data)
                mail = b''.join(lines).decode()
                previous = json.loads(Path(capture).read_text()) if Path(capture).exists() else []
                previous.append(dict(sender=sender, recipient=to, authenticated=authed, message=mail, encrypted=isinstance(conn, ssl.SSLSocket)))
                Path(capture).write_text(json.dumps(previous))
                if mode == 'drop_data':
                    break
                reply('550 Message rejected' if mode == 'reject_data' else '250 2.0.0 Accepted')
            elif verb == 'QUIT':
                reply('221 Bye')
                break
            elif verb == 'RSET':
                reply('250 Reset')
            else:
                reply('250 OK')
    except (OSError, ssl.SSLError, ValueError):
        pass
    finally:
        conn.close()
