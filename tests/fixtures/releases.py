"""The published-release window the upgrade tier tests against.

The upgrade tier (tests/upgrade/) installs REAL past releases of BareBits and
upgrades them to the build under test. Which releases is a moving window
resolved from the GitHub releases API at run time:

    the N newest stable releases  (default 5; the auto-updater's 'main' channel)
  + the newest testing prerelease (the 'testing' channel — older testing
                                   prereleases are pruned by the release
                                   workflow, so only one ever exists)

Everything fetched is cached under tests/bin/.cache/releases/ so repeated runs
never re-download: release zips are immutable per tag and kept forever; the
listing itself is kept for LISTING_TTL_S and, when GitHub is unreachable or
rate-limited, a stale listing is used rather than failing the run.

Overrides:
    CASHUPAY_UPGRADE_FROM=v1.8,v1.7        pin the window to these tags
                                           (debugging one release, offline use)
    CASHUPAY_UPGRADE_STABLE_COUNT=3        window size (default 5)
    GITHUB_TOKEN / GH_TOKEN                used for the API call when set
                                           (lifts the 60/hour anonymous limit)
"""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import sys
import tempfile
import time
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from pathlib import Path

from . import binaries

GH_REPO = "BareBits/cashupayserver"
API_LISTING_URL = f"https://api.github.com/repos/{GH_REPO}/releases?per_page=50"
CACHE_DIR = binaries.BIN_DIR / ".cache" / "releases"
LISTING_CACHE = CACHE_DIR / "listing.json"
LISTING_TTL_S = 3600
DEFAULT_STABLE_COUNT = 5
_UA = "cashupayserver-tests/upgrade-tier"


@dataclass
class Release:
    tag: str
    prerelease: bool
    published_at: str
    assets: dict[str, str] = field(default_factory=dict)  # asset name -> download URL

    @property
    def version(self) -> str:
        """The in-tree CASHUPAY_VERSION the release was built from: the tag
        without its v prefix and without a prerelease suffix — a testing
        prerelease v1.8-testing.92 ships BUILD_INFO VERSION=1.8."""
        base = self.tag[1:] if self.tag.startswith("v") else self.tag
        return base.split("-", 1)[0]

    @property
    def standalone_asset(self) -> str:
        return f"barebits-{self.tag}.zip"

    @property
    def windows_asset(self) -> str:
        return f"barebits-windows-{self.tag}.zip"

    @property
    def channel(self) -> str:
        return "testing" if self.prerelease else "main"


# --------------------------------------------------------------------------
# listing


def _http_get(url: str, *, timeout: float = 60) -> bytes:
    headers = {"User-Agent": _UA, "Accept": "application/vnd.github+json"}
    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    if token and "api.github.com" in url:
        headers["Authorization"] = f"Bearer {token}"
    req = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read()


def fetch_release_listing(*, force: bool = False) -> list[dict]:
    """The repo's releases, newest first, as GitHub returns them. Cached for
    LISTING_TTL_S; a stale cache is used when the network or the rate limit
    says no (with a note on stderr), so an offline rerun still works."""
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    if not force and LISTING_CACHE.is_file():
        age = time.time() - LISTING_CACHE.stat().st_mtime
        if age < LISTING_TTL_S:
            return json.loads(LISTING_CACHE.read_text())
    try:
        body = _http_get(API_LISTING_URL)
        listing = json.loads(body)
        if not isinstance(listing, list):
            raise RuntimeError(f"unexpected releases payload: {str(listing)[:200]}")
    except (urllib.error.URLError, OSError, ValueError, RuntimeError) as e:
        if LISTING_CACHE.is_file():
            print(
                f"[releases] GitHub listing unavailable ({e}); using the cached "
                f"listing from {time.ctime(LISTING_CACHE.stat().st_mtime)}",
                file=sys.stderr,
            )
            return json.loads(LISTING_CACHE.read_text())
        raise RuntimeError(
            f"cannot fetch {API_LISTING_URL} and no cached listing exists: {e}. "
            "Set CASHUPAY_UPGRADE_FROM=<tag,...> to pin the window, or retry online."
        ) from e
    tmp = LISTING_CACHE.with_suffix(".json.partial")
    tmp.write_bytes(body)
    tmp.replace(LISTING_CACHE)
    return listing


def _to_release(raw: dict) -> Release:
    return Release(
        tag=str(raw["tag_name"]),
        prerelease=bool(raw.get("prerelease")),
        published_at=str(raw.get("published_at") or ""),
        assets={
            str(a["name"]): str(a["browser_download_url"])
            for a in raw.get("assets", [])
            if a.get("name") and a.get("browser_download_url")
        },
    )


def resolve_window(stable_count: int | None = None) -> list[Release]:
    """The releases the upgrade tier must start from: the newest `stable_count`
    stable releases plus the newest prerelease, newest first. Drafts and
    releases without a standalone zip are skipped. CASHUPAY_UPGRADE_FROM pins
    an explicit tag list instead."""
    if stable_count is None:
        stable_count = int(os.environ.get("CASHUPAY_UPGRADE_STABLE_COUNT", DEFAULT_STABLE_COUNT))
    listing = [
        _to_release(r) for r in fetch_release_listing() if not r.get("draft")
    ]
    by_tag = {r.tag: r for r in listing}

    pinned = os.environ.get("CASHUPAY_UPGRADE_FROM", "").strip()
    if pinned:
        out = []
        for tag in [t.strip() for t in pinned.split(",") if t.strip()]:
            if tag not in by_tag:
                raise RuntimeError(
                    f"CASHUPAY_UPGRADE_FROM names {tag!r}, which is not a published release "
                    f"(known: {', '.join(sorted(by_tag))})"
                )
            out.append(by_tag[tag])
        return out

    usable = [r for r in listing if r.standalone_asset in r.assets]
    stable = [r for r in usable if not r.prerelease][:stable_count]
    testing = [r for r in usable if r.prerelease][:1]
    window = stable + testing
    if not window:
        raise RuntimeError("the GitHub releases listing has no usable releases")
    return window


def newest_stable() -> Release:
    window = resolve_window()
    stable = [r for r in window if not r.prerelease]
    if not stable:
        raise RuntimeError("no stable release in the window")
    return stable[0]


# --------------------------------------------------------------------------
# assets


def _sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _download_to(url: str, dest: Path) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    print(f"[releases] downloading {dest.name} ...", file=sys.stderr)
    req = urllib.request.Request(url, headers={"User-Agent": _UA})
    with tempfile.NamedTemporaryFile(dir=dest.parent, delete=False, suffix=".partial") as tmp:
        with urllib.request.urlopen(req, timeout=300) as resp:
            shutil.copyfileobj(resp, tmp)
        tmp_path = Path(tmp.name)
    tmp_path.replace(dest)


def _expected_sha(release: Release, asset_name: str) -> str | None:
    """The asset's digest from the release's SHA256SUMS, or None when the
    release publishes no checksums (pre-checksum releases)."""
    url = release.assets.get("SHA256SUMS")
    if not url:
        return None
    sums = CACHE_DIR / release.tag / "SHA256SUMS"
    if not sums.is_file():
        _download_to(url, sums)
    for line in sums.read_text().splitlines():
        parts = line.split()
        if len(parts) == 2 and parts[1].lstrip("*") == asset_name:
            return parts[0].lower()
    return None


def ensure_asset(release: Release, asset_name: str) -> Path:
    """The named release asset, downloaded once into the cache and verified
    against the release's SHA256SUMS whenever it lists the asset."""
    url = release.assets.get(asset_name)
    if not url:
        raise RuntimeError(f"release {release.tag} has no asset {asset_name!r}")
    dest = CACHE_DIR / release.tag / asset_name
    expected = _expected_sha(release, asset_name)
    if dest.is_file():
        if expected is None or _sha256_file(dest) == expected:
            return dest
        print(f"[releases] cached {dest.name} fails its checksum; re-downloading", file=sys.stderr)
        dest.unlink()
    _download_to(url, dest)
    if expected is not None:
        actual = _sha256_file(dest)
        if actual != expected:
            dest.unlink(missing_ok=True)
            raise RuntimeError(
                f"checksum mismatch for {release.tag}/{asset_name}: expected {expected}, got {actual}"
            )
    return dest


def ensure_standalone_zip(release: Release) -> Path:
    return ensure_asset(release, release.standalone_asset)


def ensure_windows_zip(release: Release) -> Path:
    return ensure_asset(release, release.windows_asset)


def build_info_from_zip(zip_path: Path) -> dict[str, str]:
    """Parse barebits/BUILD_INFO out of a standalone release zip."""
    import zipfile

    with zipfile.ZipFile(zip_path) as zf:
        raw = zf.read("barebits/BUILD_INFO").decode("utf-8", "replace")
    out: dict[str, str] = {}
    for line in raw.splitlines():
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip()
    return out
