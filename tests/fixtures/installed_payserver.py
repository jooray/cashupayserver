"""Serve a BareBits install unpacked from a release zip.

`start_payserver` (fixtures/payserver.py) serves the working tree with a data
dir off to the side — right for testing the code under development. The
upgrade tier needs the other shape: a REAL past release, installed from its
published zip into a directory of its own with data/ inside it, exactly like a
merchant's web host, served by the same php -S / Apache / nginx backends, and
with its auto-updater LIVE (every other fixture kills it).

Pieces:
    install_release_zip()       unpack barebits-<tag>.zip into <root>/install
    point_updater_at()          make that install's updater fetch releases from
                                a local release server instead of GitHub
    start_installed_payserver() serve it; returns the usual PayserverHandle
"""
from __future__ import annotations

import os
import re
import shutil
import zipfile
from pathlib import Path

from . import ports, webserver
from .payserver import (
    ROUTER_WRAPPER_TEMPLATE,
    PayserverHandle,
    _ensure_php,
    _seed_ipgeo_database,
)

# The updater's post-apply health probe is an HTTP self-request, and the
# admin "Update now" nudge is another: a single-worker server would deadlock
# on either, so an install under upgrade test always gets a small pool.
UPGRADE_WORKERS = 3

_SEAM_LINE = re.compile(
    r"^(\s*)public static \?string \$releaseApiUrlBase = null;", re.M
)


def install_release_zip(zip_path: Path, root: Path) -> Path:
    """Unpack a standalone release zip (which wraps everything in barebits/)
    into <root>/install and return that path. data/ exists afterwards exactly
    as the zip ships it (deny-all .htaccess + index.html)."""
    root.mkdir(parents=True, exist_ok=True)
    install = root / "install"
    if install.exists():
        shutil.rmtree(install)
    with zipfile.ZipFile(zip_path) as zf:
        names = zf.namelist()
        if not any(n.startswith("barebits/") for n in names):
            raise RuntimeError(f"{zip_path} does not wrap its tree in barebits/")
        zf.extractall(root)
        # Preserve the executable bits the zip recorded (ZipFile does not).
        for info in zf.infolist():
            mode = (info.external_attr >> 16) & 0o777
            if mode:
                target = root / info.filename
                if target.exists():
                    target.chmod(mode)
    (root / "barebits").rename(install)
    (install / "data").mkdir(exist_ok=True)
    return install


def point_updater_at(install: Path, releases_base: str) -> str:
    """Make the install's updater read releases from `releases_base` (a
    local release server's /releases URL) instead of GitHub.

    Releases that know CASHUPAY_RELEASE_API_BASE need nothing on disk — the
    env var does it (returns 'env'). Older releases have no runtime seam, so
    the ONE default-value line of their test seam is rewritten in place
    (returns 'patched'); everything else about the old updater — asset
    matching, download, backup, overlay, health verify — runs unmodified.
    """
    updater = install / "includes" / "updater.php"
    src = updater.read_text()
    if "CASHUPAY_RELEASE_API_BASE" in src:
        return "env"
    if not _SEAM_LINE.search(src):
        raise RuntimeError(
            f"{updater} has neither the CASHUPAY_RELEASE_API_BASE override nor the "
            "$releaseApiUrlBase seam — cannot point this release at a local release server"
        )
    quoted = releases_base.replace("\\", "\\\\").replace("'", "\\'")
    patched = _SEAM_LINE.sub(
        lambda m: f"{m.group(1)}public static ?string $releaseApiUrlBase = '{quoted}';",
        src,
        count=1,
    )
    updater.write_text(patched)
    return "patched"


def start_installed_payserver(
    install: Path,
    workdir: Path,
    *,
    releases_base: str | None = None,
    extra_env: dict[str, str] | None = None,
    workers: int = UPGRADE_WORKERS,
) -> PayserverHandle:
    """Serve `install` (its own data/ inside) on the active backend with the
    auto-updater enabled. `releases_base` is exported as
    CASHUPAY_RELEASE_API_BASE for releases that honour it; older ones must
    have been point_updater_at()'ed beforehand."""
    php = _ensure_php()
    workdir.mkdir(parents=True, exist_ok=True)
    data_dir = install / "data"
    data_dir.mkdir(exist_ok=True)
    _seed_ipgeo_database(data_dir)

    router_wrapper = workdir / "router-wrapper.php"
    router_wrapper.write_text(
        ROUTER_WRAPPER_TEMPLATE.format(router_path=str(install / "router.php"))
    )
    port = ports.allocate(1)[0]

    env = os.environ.copy()
    env["CASHUPAY_DATA_DIR"] = str(data_dir)
    # The whole point of this fixture: the updater must RUN. An outer shell
    # (iterate.py, another fixture) may export the kill switch — override it
    # explicitly ("0" means not disabled) and never drop the sentinel file.
    env["CASHUPAY_UPDATER_DISABLED"] = "0"
    env.setdefault("CASHUPAY_DEV_FEE_BRANCH_ENDPOINT", "disabled")
    env.setdefault("CASHUPAY_USAGE_STATS_ENDPOINT", "disabled")
    # A lived-in store accrues real dev-fee debt as its invoices settle, and
    # once the debt covers an invoice the fee redirect points that invoice's
    # Lightning rail at the dev LNURL — a MAINNET address in the real world.
    # An unresolvable LNURL keeps the rail redirect-ineligible (same trick as
    # tests/fixtures/xpub_endpoint.py), so every invoice stays payable from
    # the regtest payer; the debt itself is still tracked and settled by cron.
    env.setdefault("CASHUPAY_DEV_FEE_LNURL", "fees@fee-lnurl.invalid")
    # The release server, the mints and bitcoind all live on 127.0.0.1, and
    # the updater only fetches plain-http releases under this opt-in.
    env.setdefault("CASHUPAY_ALLOW_PRIVATE_ENDPOINTS", "1")
    if releases_base:
        env["CASHUPAY_RELEASE_API_BASE"] = releases_base
    if extra_env:
        env.update(extra_env)

    server = webserver.start_php_site(
        port=port,
        docroot=install,
        workdir=workdir,
        env=env,
        role="upgrade",
        log_path=workdir / "php-server.log",
        prepend_defines={"CASHUPAY_DATA_DIR": str(data_dir)},
        workers=workers,
        phps_router=router_wrapper,
        phps_cwd=install,
        phps_binary=php,
    )
    handle = PayserverHandle(server=server, port=port, data_dir=data_dir, workdir=workdir)
    try:
        handle.wait_ready()
    except Exception:
        server.stop(grace_s=5.0)
        raise
    return handle


def read_build_info(install: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    path = install / "BUILD_INFO"
    if not path.is_file():
        return out
    for line in path.read_text().splitlines():
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip()
    return out
