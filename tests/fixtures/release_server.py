"""Local stand-in for the GitHub releases API.

The WordPress plugin's "install BareBits alongside WordPress" flow downloads
the latest stable release from api.github.com. Tests point the plugin at this
fixture instead (CASHUPAY_RELEASE_API_BASE, threaded through the WP fixture's
router wrapper) so the install path runs against the standalone zip built
from the working tree — hermetic, and exercising the exact artifact CI
publishes when $CASHUPAY_STANDALONE_ZIP is set.

Serves:
    GET /releases/latest      GitHub-shaped JSON with the zip + SHA256SUMS assets
                              (404 when the release is a prerelease — GitHub's
                              /releases/latest never returns prereleases, which
                              is exactly the trap the plugin's channel logic
                              exists to avoid)
    GET /releases             GitHub-shaped listing (newest first) — what the
                              plugin's testing channel reads
    GET /assets/<name>        the asset bytes
"""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
import threading
from dataclasses import dataclass
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from . import binaries, ports

REPO_ROOT = Path(__file__).resolve().parent.parent.parent

RELEASE_TAG = "v0.0-test"
ZIP_ASSET_NAME = f"barebits-{RELEASE_TAG}.zip"


def ensure_standalone_zip() -> Path:
    """The standalone server zip — the exact artifact the release workflow
    publishes. CI passes it via $CASHUPAY_STANDALONE_ZIP (built by the
    build-artifacts job); a local run builds it once via
    scripts/build-standalone.sh (needs composer + npm the first time)."""
    env_zip = os.environ.get("CASHUPAY_STANDALONE_ZIP")
    if env_zip:
        p = Path(env_zip)
        if p.is_file():
            return p
        raise RuntimeError(
            f"CASHUPAY_STANDALONE_ZIP={env_zip!r} but that file does not exist"
        )

    php_exe = binaries.ensure(binaries.PHP)["php"]
    script = REPO_ROOT / "scripts" / "build-standalone.sh"
    env = os.environ.copy()
    env["PHP_BIN"] = str(php_exe)
    print(f"[release] building standalone zip via {script.name} ...")
    subprocess.run(["bash", str(script)], cwd=str(REPO_ROOT), env=env, check=True)
    zip_path = REPO_ROOT / "build" / "barebits.zip"
    if not zip_path.is_file():
        raise RuntimeError("build-standalone.sh did not produce build/barebits.zip")
    return zip_path


@dataclass
class ReleaseServer:
    server: ThreadingHTTPServer
    thread: threading.Thread
    port: int

    @property
    def api_base(self) -> str:
        """Value for the WordPress installer's CASHUPAY_RELEASE_API_BASE."""
        return f"http://127.0.0.1:{self.port}"

    @property
    def releases_base(self) -> str:
        """The releases-collection URL for the server-side updater
        (Updater::$releaseApiUrlBase / CASHUPAY_RELEASE_API_BASE): it appends
        /latest and ?per_page= itself."""
        return f"http://127.0.0.1:{self.port}/releases"


def start_release_server(
    zip_path: Path, *, with_sums: bool = True, tamper: bool = False,
    prerelease: bool = False,
) -> ReleaseServer:
    """Serve `zip_path` as the newest release. with_sums=False omits the
    SHA256SUMS asset, modeling an older release without checksums. tamper=True
    computes SHA256SUMS from the pristine zip but serves a corrupted one — the
    shape of a modified-in-transit (or compromised-mirror) download.
    prerelease=True publishes it as a testing prerelease: it heads /releases
    but /releases/latest answers 404, exactly like GitHub when a repository's
    newest (or only) release is a prerelease."""
    zip_bytes = zip_path.read_bytes()
    sums_body = (
        f"{hashlib.sha256(zip_bytes).hexdigest()}  {ZIP_ASSET_NAME}\n".encode()
    )
    if tamper:
        zip_bytes = zip_bytes[:-1] + bytes([zip_bytes[-1] ^ 0xFF])
    port = ports.allocate(1)[0]
    base = f"http://127.0.0.1:{port}"

    assets: dict[str, tuple[bytes, str]] = {
        ZIP_ASSET_NAME: (zip_bytes, "application/zip"),
    }
    release_assets = [
        {"name": ZIP_ASSET_NAME, "browser_download_url": f"{base}/assets/{ZIP_ASSET_NAME}"},
        # Realistic clutter the plugin must skip over.
        {"name": "BUILD_INFO", "browser_download_url": f"{base}/assets/BUILD_INFO"},
        {
            "name": f"barebits-windows-{RELEASE_TAG}.zip",
            "browser_download_url": f"{base}/assets/barebits-windows-{RELEASE_TAG}.zip",
        },
        {
            "name": f"barebits_wordpress_plugin-{RELEASE_TAG}.zip",
            "browser_download_url": f"{base}/assets/barebits_wordpress_plugin-{RELEASE_TAG}.zip",
        },
    ]
    assets["BUILD_INFO"] = (b"COMMIT_SHA=test\n", "text/plain")
    if with_sums:
        assets["SHA256SUMS"] = (sums_body, "text/plain")
        release_assets.append(
            {"name": "SHA256SUMS", "browser_download_url": f"{base}/assets/SHA256SUMS"}
        )

    release_obj = {
        "tag_name": RELEASE_TAG,
        "prerelease": prerelease,
        "draft": False,
        "assets": release_assets,
    }
    latest = json.dumps(release_obj).encode()
    listing = json.dumps([release_obj]).encode()

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802 (http.server API)
            path = self.path.split("?", 1)[0]
            if path == "/releases/latest":
                if prerelease:
                    # GitHub's /releases/latest never returns prereleases.
                    self.send_response(404)
                    self.end_headers()
                    return
                self._respond(latest, "application/json")
                return
            if path == "/releases":
                self._respond(listing, "application/json")
                return
            if path.startswith("/assets/"):
                name = path[len("/assets/"):]
                if name in assets:
                    body, ctype = assets[name]
                    self._respond(body, ctype)
                    return
            self.send_response(404)
            self.end_headers()

        def _respond(self, body: bytes, ctype: str) -> None:
            self.send_response(200)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *args) -> None:  # keep pytest output clean
            pass

    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return ReleaseServer(server=server, thread=thread, port=port)


@dataclass
class CatalogRelease:
    """One release in a served catalog (see start_release_catalog)."""

    tag: str
    zip_path: Path
    prerelease: bool = False
    # BUILD_INFO served as the stable-named asset the updater reads first;
    # None = lift barebits/BUILD_INFO out of the zip.
    build_info: bytes | None = None
    with_sums: bool = True

    @property
    def zip_asset_name(self) -> str:
        return f"barebits-{self.tag}.zip"


def start_release_catalog(releases: list[CatalogRelease]) -> ReleaseServer:
    """Serve several releases at once, GitHub-shaped, newest FIRST in the
    given order — what the server-side auto-updater (includes/updater.php)
    reads, as opposed to start_release_server() which models the single
    release the WordPress installer downloads.

        GET /releases            every release, in order (the 'testing'
                                 channel takes the first one)
        GET /releases/latest     the first non-prerelease, 404 if none
                                 (the 'main' channel; GitHub semantics)
        GET /assets/<tag>/<name> that release's asset bytes

    Each release carries exactly the assets the updater looks for: a
    stable-named BUILD_INFO, barebits-<tag>.zip, plus SHA256SUMS and the
    realistic sibling assets (windows/wordpress zips) it must skip over.
    """
    import zipfile

    port = ports.allocate(1)[0]
    base = f"http://127.0.0.1:{port}"
    assets: dict[str, tuple[bytes, str]] = {}  # "<tag>/<name>" -> (body, ctype)
    objs: list[dict] = []
    for rel in releases:
        zip_bytes = rel.zip_path.read_bytes()
        build_info = rel.build_info
        if build_info is None:
            with zipfile.ZipFile(rel.zip_path) as zf:
                build_info = zf.read("barebits/BUILD_INFO")
        prefix = f"{rel.tag}/"
        assets[prefix + rel.zip_asset_name] = (zip_bytes, "application/zip")
        assets[prefix + "BUILD_INFO"] = (build_info, "text/plain")
        names = [rel.zip_asset_name, "BUILD_INFO",
                 f"barebits-windows-{rel.tag}.zip", f"barebits_wordpress_plugin-{rel.tag}.zip"]
        if rel.with_sums:
            sums = f"{hashlib.sha256(zip_bytes).hexdigest()}  {rel.zip_asset_name}\n".encode()
            assets[prefix + "SHA256SUMS"] = (sums, "text/plain")
            names.append("SHA256SUMS")
        objs.append({
            "tag_name": rel.tag,
            "prerelease": rel.prerelease,
            "draft": False,
            "assets": [
                {"name": n, "browser_download_url": f"{base}/assets/{rel.tag}/{n}"} for n in names
            ],
        })
    listing = json.dumps(objs).encode()
    stable = next((o for o in objs if not o["prerelease"]), None)
    latest = json.dumps(stable).encode() if stable is not None else None

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802 (http.server API)
            path = self.path.split("?", 1)[0]
            if path == "/releases/latest":
                if latest is None:
                    self.send_response(404)
                    self.end_headers()
                    return
                self._respond(latest, "application/json")
                return
            if path == "/releases":
                self._respond(listing, "application/json")
                return
            if path.startswith("/assets/"):
                key = path[len("/assets/"):]
                if key in assets:
                    body, ctype = assets[key]
                    self._respond(body, ctype)
                    return
            self.send_response(404)
            self.end_headers()

        def _respond(self, body: bytes, ctype: str) -> None:
            self.send_response(200)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *args) -> None:  # keep pytest output clean
            pass

    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return ReleaseServer(server=server, thread=thread, port=port)


def stop_release_server(rs: ReleaseServer) -> None:
    rs.server.shutdown()
    rs.server.server_close()
