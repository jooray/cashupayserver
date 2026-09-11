"""Multi-backend serving layer for the app-under-test fixtures.

CASHUPAY_TEST_BACKEND selects how the payserver / WordPress trees are served:

  phps    php -S with a router-wrapper script (default; no docker needed).
  apache  php:8.3-apache (mod_php) in docker — the real .htaccess semantics.
  nginx   nginx + php-fpm in one docker container — the canonical
          docker/nginx-site.conf semantics.

Container invariants that keep every existing test working unchanged:
  - --network=host: the server binds 127.0.0.1:{port} exactly like php -S, and
    every loopback sink (mint, webhook receiver, mocks, Playwright) keeps
    working with no address translation.
  - --user <host uid>:<gid>: files the server writes (sqlite WAL, uploads) are
    read/writable by pytest and vice versa (password-reset sentinel, etc.).
  - a single identical-path bind mount of the repo root: workdirs and data
    dirs live under tests/.tmp inside the repo, so DOCUMENT_ROOT, realpath
    checks (Database::isDataDirOutsideWebroot) and URLs match php -S exactly.

Docker is invoked as `sudo -n docker`, the suite's house style (see
tests/fixtures/boltz_regtest.py). When the backend needs docker and it is not
available, tests are skipped at collection time by tests/conftest.py; direct
callers get a RuntimeError from here as a backstop.
"""
from __future__ import annotations

import atexit
import hashlib
import os
import shutil
import signal
import subprocess
import time
import uuid
from dataclasses import dataclass
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
TESTS_DIR = REPO_ROOT / "tests"
DOCKER_DIR = TESTS_DIR / "docker"
NGINX_CANONICAL_CONF = REPO_ROOT / "docker" / "nginx-site.conf"

BACKENDS = ("phps", "apache", "nginx")

# One id per pytest (or script) process; labels every container we start so a
# session-start sweep can remove strays from previous killed runs without ever
# touching our own.
SESSION_ID = uuid.uuid4().hex[:12]

# Environment prefixes forwarded into containers. The phps backend inherits the
# full parent environment (parity with the pre-backend fixture); a container
# deliberately gets only what the app reads.
_ENV_FORWARD_PREFIXES = ("CASHUPAY_", "CASHU_", "PHP_")
_ENV_FORWARD_EXACT = ("TZ",)


def current_backend() -> str:
    backend = os.environ.get("CASHUPAY_TEST_BACKEND", "phps")
    if backend not in BACKENDS:
        raise RuntimeError(
            f"CASHUPAY_TEST_BACKEND={backend!r} — expected one of {BACKENDS}"
        )
    return backend


def backend_needs_docker() -> bool:
    return current_backend() != "phps"


# --------------------------------------------------------------------------
# docker plumbing


def _docker_cmd(*args: str) -> list[str]:
    return ["sudo", "-n", "docker", *args]


def _docker(*args: str, check: bool = True, timeout: float = 120.0) -> subprocess.CompletedProcess:
    return subprocess.run(
        _docker_cmd(*args),
        check=check,
        capture_output=True,
        text=True,
        timeout=timeout,
    )


_docker_reason: str | None = None
_docker_probed = False


def docker_unavailable_reason() -> str | None:
    """None when `sudo -n docker` works; otherwise a human-readable reason.
    Mirrors tests/fixtures/boltz_regtest.py's probe."""
    global _docker_reason, _docker_probed
    if _docker_probed:
        return _docker_reason
    _docker_probed = True
    if shutil.which("sudo") is None:
        _docker_reason = "sudo not found"
    elif shutil.which("docker") is None:
        _docker_reason = "docker not found"
    else:
        try:
            probe = subprocess.run(
                ["sudo", "-n", "docker", "version"],
                capture_output=True, text=True, timeout=30,
            )
            if probe.returncode != 0:
                _docker_reason = (
                    "`sudo -n docker version` failed: "
                    + (probe.stderr or probe.stdout).strip()[:200]
                )
        except (OSError, subprocess.TimeoutExpired) as e:
            _docker_reason = f"`sudo -n docker version` errored: {e}"
    return _docker_reason


def _require_docker() -> None:
    reason = docker_unavailable_reason()
    if reason is not None:
        raise RuntimeError(
            f"CASHUPAY_TEST_BACKEND={current_backend()} needs docker: {reason}"
        )


_swept = False


def _sweep_stale_containers() -> None:
    """Remove containers left behind by previous killed runs. Runs before this
    session starts its first container, so everything carrying our label is by
    definition stale (same lesson as the stale php -S fixture-process sweeps)."""
    global _swept
    if _swept:
        return
    _swept = True
    listing = _docker(
        "ps", "-aq", "--filter", "label=barebits-test=1", check=False
    )
    ids = [line for line in listing.stdout.split() if line]
    if ids:
        _docker("rm", "-f", *ids, check=False)


# --------------------------------------------------------------------------
# test images: built once per session, tagged by content hash


_IMAGE_SOURCES = {
    "apache": ("Dockerfile.apache-test",),
    "nginx": ("Dockerfile.nginx-test", "nginx-fpm-entrypoint.sh", "nginx-test.conf"),
}
_image_tags: dict[str, str] = {}


def ensure_image(kind: str) -> str:
    """Build (once) and return the tag of the test image for `kind`.
    Tag embeds a hash of the image sources, so editing a Dockerfile
    automatically produces a rebuild while warm runs cost one `image inspect`."""
    if kind in _image_tags:
        return _image_tags[kind]
    _require_docker()
    hasher = hashlib.sha256()
    for name in _IMAGE_SOURCES[kind]:
        hasher.update((DOCKER_DIR / name).read_bytes())
    tag = f"barebits-test-{kind}:{hasher.hexdigest()[:12]}"
    inspect = _docker("image", "inspect", tag, check=False)
    if inspect.returncode != 0:
        build = subprocess.run(
            _docker_cmd(
                "build",
                "-t", tag,
                "-f", str(DOCKER_DIR / _IMAGE_SOURCES[kind][0]),
                str(DOCKER_DIR),
            ),
            capture_output=True, text=True, timeout=900,
        )
        if build.returncode != 0:
            raise RuntimeError(
                f"docker build of {tag} failed:\n{build.stdout[-2000:]}\n{build.stderr[-2000:]}"
            )
    _image_tags[kind] = tag
    return tag


# --------------------------------------------------------------------------
# ManagedServer: one stop/poll interface over Popen and containers


_live_streamers: list[subprocess.Popen] = []


def _reap_streamers() -> None:
    for proc in _live_streamers:
        if proc.poll() is None:
            proc.terminate()


atexit.register(_reap_streamers)


@dataclass
class ManagedServer:
    kind: str                                # "phps" | "apache" | "nginx"
    popen: subprocess.Popen | None = None    # phps only
    container: str | None = None             # docker backends
    log_streamer: subprocess.Popen | None = None

    def poll(self) -> int | None:
        """None while running, an exit-code-ish int once stopped."""
        if self.kind == "phps":
            assert self.popen is not None
            return self.popen.poll()
        state = _docker(
            "inspect", "-f", "{{.State.Running}}", self.container, check=False
        )
        if state.returncode == 0 and state.stdout.strip() == "true":
            return None
        return 0

    def stop(self, grace_s: float = 10.0) -> None:
        if self.kind == "phps":
            assert self.popen is not None
            if self.popen.poll() is None:
                self.popen.send_signal(signal.SIGTERM)
                try:
                    self.popen.wait(timeout=grace_s)
                except subprocess.TimeoutExpired:
                    self.popen.kill()
                    self.popen.wait()
            return
        # docker stop = SIGTERM to pid 1, SIGKILL after the grace — the same
        # contract stop_payserver has always given php -S.
        _docker("stop", "-t", str(int(grace_s)), self.container, check=False)
        if self.log_streamer is not None:
            # let `docker logs -f` drain the final lines before reaping it
            try:
                self.log_streamer.wait(timeout=2)
            except subprocess.TimeoutExpired:
                self.log_streamer.terminate()
        _docker("rm", "-f", self.container, check=False)


# --------------------------------------------------------------------------
# per-instance generated config


def _write_prepend(conf_dir: Path, defines: dict[str, str]) -> Path:
    lines = ["<?php"]
    for key, value in defines.items():
        quoted = "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"
        lines.append(f"if (!defined('{key}')) {{ define('{key}', {quoted}); }}")
    path = conf_dir / "prepend.php"
    path.write_text("\n".join(lines) + "\n")
    return path


def _write_php_ini(conf_dir: Path, prepend: Path | None, ini_overrides: dict[str, str]) -> Path:
    lines = [
        "; generated per test instance by tests/fixtures/webserver.py",
        "display_errors = Off",
        "log_errors = On",
        "error_log = /proc/self/fd/2",
    ]
    if prepend is not None:
        lines.append(f'auto_prepend_file = "{prepend}"')
    for key, value in ini_overrides.items():
        lines.append(f'{key} = "{value}"')
    path = conf_dir / "zz-test.ini"
    path.write_text("\n".join(lines) + "\n")
    return path


def parse_extra_php_args(extra_php_args: list[str] | None) -> dict[str, str]:
    """Translate the fixture's `-d key=value` CLI flags (the only form any test
    uses) into ini entries a container can mount. Anything else is refused
    loudly rather than silently dropped."""
    overrides: dict[str, str] = {}
    args = list(extra_php_args or [])
    while args:
        flag = args.pop(0)
        if flag != "-d" or not args:
            raise RuntimeError(
                f"extra_php_args entry {flag!r} is not supported on containerized "
                "backends — only '-d key=value' pairs translate to php.ini"
            )
        key, sep, value = args.pop(0).partition("=")
        if not sep:
            raise RuntimeError(f"malformed -d argument: {key!r}")
        overrides[key] = value
    return overrides


def _setenv_lines(env: dict[str, str]) -> str:
    lines = []
    for key, value in sorted(env.items()):
        escaped = value.replace("\\", "\\\\").replace('"', '\\"')
        lines.append(f'    SetEnv {key} "{escaped}"')
    return "\n".join(lines)


def _write_apache_conf(
    conf_dir: Path,
    *,
    port: int,
    docroot: Path,
    allow_override: bool,
    workers: int | None,
    env: dict[str, str],
) -> dict[Path, str]:
    """Returns {host_path: container_path} mounts."""
    (conf_dir / "ports.conf").write_text(f"Listen 127.0.0.1:{port}\n")
    override = "All" if allow_override else "None"
    # The rewrite-hostile shape (AllowOverride None) still models a stock
    # WordPress host: URLs that map to no real file must land in WordPress's
    # front controller (nginx-hostile does this via try_files ... /index.php),
    # not in Apache's own 404. FallbackResource is that front controller when
    # .htaccess rewrites are off; the friendly shape gets it from .htaccess.
    fallback = "" if allow_override else "    FallbackResource /index.php\n"
    (conf_dir / "site.conf").write_text(
        f"""<VirtualHost 127.0.0.1:{port}>
    ServerName 127.0.0.1
    DocumentRoot "{docroot}"
    <Directory "{docroot}">
        Options FollowSymLinks
        AllowOverride {override}
        Require all granted
    </Directory>
{fallback}    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
{_setenv_lines(env)}
</VirtualHost>
"""
    )
    mounts = {
        conf_dir / "ports.conf": "/etc/apache2/ports.conf",
        conf_dir / "site.conf": "/etc/apache2/sites-enabled/000-default.conf",
    }
    if workers is not None:
        # mod_php forces mpm_prefork: one request per process, so capping the
        # process pool models a starved worker pool the same way
        # PHP_CLI_SERVER_WORKERS=N does under php -S. Faithful mapping is N+1
        # serving slots: under php -S the master process serves requests too
        # (see tests/wordpress/test_wp_onboarding_tight_worker_pool.py).
        slots = workers + 1
        (conf_dir / "mpm.conf").write_text(
            f"""<IfModule mpm_prefork_module>
    StartServers {slots}
    MinSpareServers 1
    MaxSpareServers {slots}
    ServerLimit {slots}
    MaxRequestWorkers {slots}
</IfModule>
"""
        )
        mounts[conf_dir / "mpm.conf"] = "/etc/apache2/conf-enabled/zz-test-mpm.conf"
    return mounts


_FPM_SOCKET = "/tmp/fpm/fpm.sock"
FPM_SOCKET = _FPM_SOCKET  # public: wordpress.py renders site configs against it


def render_nginx_site(
    *, port: int, docroot: Path, fastcgi_pass: str = f"unix:{_FPM_SOCKET}"
) -> str:
    """The canonical docker/nginx-site.conf with its TEST-SUBST anchors
    substituted for this instance. Failing when an anchor is missing is the
    drift guard between the shipped config and what the tests exercise."""
    text = NGINX_CANONICAL_CONF.read_text()
    replacements = {
        "listen": f"    listen 127.0.0.1:{port};",
        "root": f"    root {docroot};",
        "fastcgi_pass": f"        fastcgi_pass {fastcgi_pass};",
    }
    for anchor, line in replacements.items():
        marker = f"# TEST-SUBST: {anchor}"
        matching = [ln for ln in text.splitlines() if marker in ln]
        if len(matching) != 1:
            raise RuntimeError(
                f"docker/nginx-site.conf must contain exactly one '{marker}' "
                f"anchor (found {len(matching)}) — tests derive their config "
                "from the canonical file"
            )
        text = text.replace(matching[0], f"{line}  {marker}")
    return text


def _write_fpm_pool(conf_dir: Path, workers: int | None) -> Path:
    # Faithful mapping is N+1 serving slots: under php -S with
    # PHP_CLI_SERVER_WORKERS=N the master process serves requests too.
    n = (workers + 1) if workers is not None else 7
    path = conf_dir / "fpm-pool.conf"
    path.write_text(
        f"""[www]
listen = {_FPM_SOCKET}
clear_env = no
catch_workers_output = yes
pm = static
pm.max_children = {n}
"""
    )
    return path


# --------------------------------------------------------------------------
# the one entry point the fixtures call


def start_php_site(
    *,
    port: int,
    docroot: Path,
    workdir: Path,
    env: dict[str, str],
    role: str,
    log_path: Path,
    prepend_defines: dict[str, str] | None = None,
    ini_overrides: dict[str, str] | None = None,
    workers: int | None = None,
    phps_router: Path | None = None,
    phps_cwd: Path | None = None,
    phps_binary: str | None = None,
    apache_allow_override: bool = True,
    nginx_site_conf: str | None = None,
    extra_bind_dirs: tuple[Path, ...] = (),
) -> ManagedServer:
    """Start one HTTP server for `docroot` on 127.0.0.1:{port} using the active
    backend. `env` is the fully merged environment (the phps backend passes it
    verbatim to Popen; containers receive the CASHUPAY_*/CASHU_*/PHP_*/TZ
    subset). `workers` caps the request-worker pool on every backend
    (PHP_CLI_SERVER_WORKERS / prefork MaxRequestWorkers / fpm pm.max_children).
    """
    backend = current_backend()

    if backend == "phps":
        assert phps_router is not None and phps_binary is not None
        run_env = dict(env)
        if workers is not None:
            run_env["PHP_CLI_SERVER_WORKERS"] = str(workers)
        cli_args: list[str] = []
        for key, value in (ini_overrides or {}).items():
            cli_args += ["-d", f"{key}={value}"]
        log = log_path.open("ab")
        proc = subprocess.Popen(
            [
                phps_binary,
                *cli_args,
                "-S", f"127.0.0.1:{port}",
                "-t", str(docroot),
                str(phps_router),
            ],
            env=run_env,
            cwd=str(phps_cwd or docroot),
            stdout=log,
            stderr=subprocess.STDOUT,
        )
        return ManagedServer(kind="phps", popen=proc)

    _require_docker()
    _sweep_stale_containers()
    image = ensure_image(backend)

    conf_dir = workdir / "server-conf"
    if conf_dir.exists():
        shutil.rmtree(conf_dir)
    conf_dir.mkdir(parents=True)

    container_env = {
        k: v
        for k, v in env.items()
        if k.startswith(_ENV_FORWARD_PREFIXES) or k in _ENV_FORWARD_EXACT
    }

    prepend = _write_prepend(conf_dir, prepend_defines) if prepend_defines else None
    ini = _write_php_ini(conf_dir, prepend, ini_overrides or {})
    mounts: dict[Path, str] = {ini: "/usr/local/etc/php/conf.d/zz-test.ini"}

    if backend == "apache":
        mounts.update(
            _write_apache_conf(
                conf_dir,
                port=port,
                docroot=docroot,
                allow_override=apache_allow_override,
                workers=workers,
                env=container_env,
            )
        )
    else:
        site = nginx_site_conf if nginx_site_conf is not None else render_nginx_site(
            port=port, docroot=docroot
        )
        (conf_dir / "site.conf").write_text(site)
        mounts[conf_dir / "site.conf"] = "/etc/nginx/test-sites/site.conf"
        mounts[_write_fpm_pool(conf_dir, workers)] = (
            "/usr/local/etc/php-fpm.d/zz-test.conf"
        )

    name = f"barebits-test-{role}-{port}-{uuid.uuid4().hex[:8]}"
    cmd = _docker_cmd(
        "run", "-d",
        "--name", name,
        "--label", "barebits-test=1",
        "--label", f"barebits-test-session={SESSION_ID}",
        "--network", "host",
        "--user", f"{os.getuid()}:{os.getgid()}",
        "--tmpfs", "/tmp:exec,mode=1777",
        "-v", f"{REPO_ROOT}:{REPO_ROOT}",
        # Paths the app must share with the host that live OUTSIDE the repo
        # mount (e.g. a test's outside-webroot CASHUPAY_DATA_DIR under the
        # host's /tmp — which the tmpfs above would otherwise shadow). Docker
        # mounts deeper destinations after shallower ones, so these bind over
        # the tmpfs correctly.
        *[arg for p in extra_bind_dirs for arg in ("-v", f"{p}:{p}")],
        *[arg for host, target in mounts.items() for arg in ("-v", f"{host}:{target}:ro")],
        *[arg for k, v in sorted(container_env.items()) for arg in ("-e", f"{k}={v}")],
        image,
    )
    run = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
    if run.returncode != 0:
        raise RuntimeError(
            f"docker run for {role} failed: {run.stderr.strip()[:2000]}"
        )

    log = log_path.open("ab")
    streamer = subprocess.Popen(
        _docker_cmd("logs", "-f", name), stdout=log, stderr=subprocess.STDOUT
    )
    _live_streamers.append(streamer)
    return ManagedServer(kind=backend, container=name, log_streamer=streamer)


def cleanup_session_containers() -> None:
    """Best-effort removal of anything this session's label still owns."""
    if docker_unavailable_reason() is not None:
        return
    listing = _docker(
        "ps", "-aq", "--filter", f"label=barebits-test-session={SESSION_ID}",
        check=False,
    )
    ids = [line for line in listing.stdout.split() if line]
    if ids:
        _docker("rm", "-f", *ids, check=False)
