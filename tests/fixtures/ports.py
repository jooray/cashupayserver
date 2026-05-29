"""Free port allocation for ephemeral test daemons.

We bind to 127.0.0.1:0, let the kernel pick a high port, then release it just
before handing the port number to a subprocess. There is a TOCTOU window
between close() and the daemon's bind(); callers using `allocate(n)` get N
distinct ports in one shot, minimizing repeated allocation calls.
"""
from __future__ import annotations

import socket
from typing import Iterable


def free_port() -> int:
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]
    finally:
        s.close()


def allocate(n: int) -> tuple[int, ...]:
    """Allocate `n` distinct free ports. Brief race window before subprocess binds."""
    sockets: list[socket.socket] = []
    try:
        seen: set[int] = set()
        while len(seen) < n:
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.bind(("127.0.0.1", 0))
            port = s.getsockname()[1]
            if port in seen:
                s.close()
                continue
            seen.add(port)
            sockets.append(s)
        return tuple(s.getsockname()[1] for s in sockets)
    finally:
        for s in sockets:
            s.close()


def wait_listening(port: int, timeout_s: float = 30.0, host: str = "127.0.0.1") -> None:
    """Poll-connect to host:port until it accepts or timeout."""
    import time
    deadline = time.monotonic() + timeout_s
    last_err: Exception | None = None
    while time.monotonic() < deadline:
        try:
            with socket.create_connection((host, port), timeout=1.0):
                return
        except (ConnectionRefusedError, OSError) as e:
            last_err = e
            time.sleep(0.1)
    raise TimeoutError(f"{host}:{port} not listening after {timeout_s}s ({last_err})")
