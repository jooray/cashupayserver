"""A loopback SMTP sink for tests that need a real SMTP round-trip.

The setup wizard's e-mail screen refuses to advance until a test e-mail has
actually been delivered, so a mock at the EmailSender layer would not
exercise the thing under test. This is a deliberately tiny RFC 5321 server:
plaintext only (no STARTTLS, so PHPMailer must be told encryption=none on a
non-587 port), optional AUTH LOGIN/PLAIN that accepts exactly one
credential pair, and every accepted message is kept in memory for the test
to inspect. Python 3.12 dropped the stdlib `smtpd` module, hence the
hand-rolled loop.
"""
from __future__ import annotations

import base64
import socket
import threading
from dataclasses import dataclass, field

from . import ports


@dataclass
class SmtpMessage:
    mail_from: str
    rcpt_to: list[str]
    data: str

    @property
    def subject(self) -> str:
        for line in self.data.splitlines():
            if line.lower().startswith("subject:"):
                return line.split(":", 1)[1].strip()
        return ""


@dataclass
class SmtpSink:
    port: int
    username: str
    password: str
    messages: list[SmtpMessage] = field(default_factory=list)
    auth_attempts: list[tuple[str, str]] = field(default_factory=list)
    _sock: socket.socket | None = None
    _thread: threading.Thread | None = None
    _stop: threading.Event = field(default_factory=threading.Event)

    @property
    def host(self) -> str:
        return "127.0.0.1"

    def wait_for_messages(self, n: int, timeout_s: float = 10.0) -> list[SmtpMessage]:
        import time

        deadline = time.monotonic() + timeout_s
        while len(self.messages) < n and time.monotonic() < deadline:
            time.sleep(0.05)
        return list(self.messages)


def start_smtp_sink(username: str = "mailer", password: str = "mail-secret") -> SmtpSink:
    port = ports.allocate(1)[0]
    sink = SmtpSink(port=port, username=username, password=password)
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", port))
    srv.listen(5)
    srv.settimeout(0.5)
    sink._sock = srv

    def serve() -> None:
        while not sink._stop.is_set():
            try:
                conn, _ = srv.accept()
            except socket.timeout:
                continue
            except OSError:
                break
            threading.Thread(target=_handle, args=(sink, conn), daemon=True).start()

    sink._thread = threading.Thread(target=serve, daemon=True)
    sink._thread.start()
    return sink


def stop_smtp_sink(sink: SmtpSink) -> None:
    sink._stop.set()
    if sink._sock is not None:
        try:
            sink._sock.close()
        except OSError:
            pass
    if sink._thread is not None:
        sink._thread.join(timeout=3)


def _handle(sink: SmtpSink, conn: socket.socket) -> None:
    conn.settimeout(15)
    f = conn.makefile("rwb", buffering=0)

    def send(line: str) -> None:
        f.write((line + "\r\n").encode("utf-8"))

    def recv() -> str:
        raw = f.readline()
        if not raw:
            raise ConnectionError("client closed")
        return raw.decode("utf-8", "replace").rstrip("\r\n")

    authed = False
    mail_from = ""
    rcpts: list[str] = []
    try:
        send("220 smtp-sink ESMTP")
        while True:
            line = recv()
            verb = line.split(" ", 1)[0].upper()
            arg = line[len(verb):].strip()
            if verb in ("EHLO", "HELO"):
                send("250-smtp-sink")
                send("250-AUTH LOGIN PLAIN")
                send("250 8BITMIME")
            elif verb == "AUTH":
                mech, _, initial = arg.partition(" ")
                mech = mech.upper()
                if mech == "PLAIN":
                    if not initial:
                        send("334 ")
                        initial = recv()
                    try:
                        parts = base64.b64decode(initial).split(b"\0")
                        user, pw = parts[-2].decode(), parts[-1].decode()
                    except Exception:
                        user, pw = "", ""
                elif mech == "LOGIN":
                    send("334 VXNlcm5hbWU6")
                    user = base64.b64decode(recv()).decode("utf-8", "replace")
                    send("334 UGFzc3dvcmQ6")
                    pw = base64.b64decode(recv()).decode("utf-8", "replace")
                else:
                    send("504 unrecognized authentication type")
                    continue
                sink.auth_attempts.append((user, pw))
                if user == sink.username and pw == sink.password:
                    authed = True
                    send("235 2.7.0 Authentication successful")
                else:
                    send("535 5.7.8 Authentication credentials invalid")
            elif verb == "MAIL":
                if not authed:
                    send("530 5.7.0 Authentication required")
                    continue
                mail_from = arg.split(":", 1)[1].strip().strip("<>") if ":" in arg else arg
                rcpts = []
                send("250 OK")
            elif verb == "RCPT":
                rcpts.append(arg.split(":", 1)[1].strip().strip("<>") if ":" in arg else arg)
                send("250 OK")
            elif verb == "DATA":
                send("354 End data with <CR><LF>.<CR><LF>")
                lines: list[str] = []
                while True:
                    dl = recv()
                    if dl == ".":
                        break
                    lines.append(dl[1:] if dl.startswith("..") else dl)
                sink.messages.append(SmtpMessage(mail_from=mail_from, rcpt_to=list(rcpts), data="\n".join(lines)))
                send("250 OK queued")
            elif verb == "RSET":
                mail_from, rcpts = "", []
                send("250 OK")
            elif verb == "NOOP":
                send("250 OK")
            elif verb == "QUIT":
                send("221 Bye")
                break
            else:
                send("502 command not implemented")
    except (ConnectionError, socket.timeout, OSError):
        pass
    finally:
        try:
            conn.close()
        except OSError:
            pass
