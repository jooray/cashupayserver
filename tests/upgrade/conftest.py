"""Fixtures for the upgrade tier.

Every test here starts from a REAL published release (the moving window in
fixtures/releases.py), served on the active backend with its auto-updater
live, and upgrades it to the build under test — the standalone zip built from
this working tree (or $CASHUPAY_STANDALONE_ZIP), published to the install
through a local GitHub-shaped release server.

Local suite only: tests.yml passes --skip-upgrade-tier (see tests/conftest.py).
The tier needs the regtest stack (payments are part of the acceptance
criteria) and reaches GitHub once per hour for the release listing; release
zips are cached under tests/bin/.cache/releases/.
"""
from __future__ import annotations

import hashlib
import os
import shutil
import subprocess
import uuid
from pathlib import Path
from typing import Callable, Iterator

import pytest

from conftest import SESSION_TMP, _maybe_remove_workdir, _test_went_green
from fixtures import binaries, releases
from fixtures.installed_payserver import (
    install_release_zip,
    point_updater_at,
    start_installed_payserver,
)
from fixtures.payserver import PayserverHandle
from fixtures.release_server import (
    CatalogRelease,
    ReleaseServer,
    ensure_standalone_zip,
    start_release_catalog,
    stop_release_server,
)
from fixtures.releases import Release

REPO_ROOT = Path(__file__).resolve().parent.parent.parent


def pytest_generate_tests(metafunc) -> None:
    """Parametrize `source_release` over the window: one test per release."""
    if "source_release" in metafunc.fixturenames:
        window = releases.resolve_window()
        metafunc.parametrize("source_release", window, ids=[r.tag for r in window])


def _candidate_sha() -> str:
    """The COMMIT_SHA to stamp into the candidate zip.

    build-standalone.sh stamps `git rev-parse HEAD`, but the updater decides
    "is there an update?" purely by comparing that string, and a working tree
    with uncommitted changes (the normal state while developing) would then be
    indistinguishable from the published build of the same commit — the old
    release would answer "already current" and nothing would be upgraded. A
    dirty tree therefore gets a suffix derived from its diff, so each distinct
    working tree is a distinct build to the updater."""
    head = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=REPO_ROOT, capture_output=True, text=True, check=True,
    ).stdout.strip()
    status = subprocess.run(
        ["git", "status", "--porcelain", "--untracked-files=all"],
        cwd=REPO_ROOT, capture_output=True, text=True, check=True,
    ).stdout
    if not status.strip():
        return head
    digest = hashlib.sha256()
    digest.update(status.encode())
    digest.update(subprocess.run(
        ["git", "diff", "HEAD"], cwd=REPO_ROOT, capture_output=True, check=True,
    ).stdout)
    return f"{head}-dirty.{digest.hexdigest()[:12]}"


@pytest.fixture(scope="session")
def candidate_zip() -> Path:
    """The build under test: the standalone zip of this working tree, built
    once per session with a COMMIT_SHA that identifies THIS tree (see
    _candidate_sha). $CASHUPAY_STANDALONE_ZIP short-circuits to a prebuilt
    zip, stamped however its builder stamped it."""
    env_zip = os.environ.get("CASHUPAY_STANDALONE_ZIP")
    if env_zip:
        return ensure_standalone_zip()
    sha = _candidate_sha()
    SESSION_TMP.mkdir(parents=True, exist_ok=True)
    target = SESSION_TMP / f"candidate-{sha[:19]}.zip"
    if target.is_file():
        return target
    php_exe = binaries.ensure(binaries.PHP)["php"]
    env = os.environ.copy()
    env.update({"PHP_BIN": str(php_exe), "COMMIT_SHA": sha, "CHANNEL": "candidate"})
    print(f"[upgrade] building the candidate zip ({sha}) via scripts/build-standalone.sh ...")
    subprocess.run(
        ["bash", str(REPO_ROOT / "scripts" / "build-standalone.sh")],
        cwd=str(REPO_ROOT), env=env, check=True, capture_output=True,
    )
    built = REPO_ROOT / "build" / "barebits.zip"
    if not built.is_file():
        raise RuntimeError("build-standalone.sh did not produce build/barebits.zip")
    shutil.copyfile(built, target)
    return target


@pytest.fixture(scope="session")
def candidate_info(candidate_zip: Path) -> dict[str, str]:
    info = releases.build_info_from_zip(candidate_zip)
    assert info.get("COMMIT_SHA"), f"candidate zip has no COMMIT_SHA in BUILD_INFO: {info}"
    info["TAG"] = f"v{info.get('VERSION', '0')}-candidate.{info['COMMIT_SHA'][:7]}"
    return info


@pytest.fixture(scope="session")
def candidate_catalog(candidate_zip: Path, candidate_info: dict[str, str]) -> Iterator[ReleaseServer]:
    """A release server whose only release is the candidate, published as
    stable so BOTH channels resolve to it: whatever channel the source
    release is on, "update" means "move to the build under test"."""
    rs = start_release_catalog([
        CatalogRelease(tag=candidate_info["TAG"], zip_path=candidate_zip, prerelease=False),
    ])
    yield rs
    stop_release_server(rs)


@pytest.fixture(scope="session")
def newest_stable() -> Release:
    return releases.newest_stable()


@pytest.fixture(scope="session")
def switch_catalog(
    candidate_zip: Path, candidate_info: dict[str, str], newest_stable: Release
) -> Iterator[ReleaseServer]:
    """The channel-switch shape: the candidate as the newest TESTING
    prerelease and the real newest stable as /releases/latest — what an
    install sees when it flips between the two channels today."""
    rs = start_release_catalog([
        CatalogRelease(tag=candidate_info["TAG"], zip_path=candidate_zip, prerelease=True),
        CatalogRelease(
            tag=newest_stable.tag, zip_path=releases.ensure_standalone_zip(newest_stable),
            prerelease=False,
        ),
    ])
    yield rs
    stop_release_server(rs)


InstallFactory = Callable[..., tuple[PayserverHandle, Path]]


@pytest.fixture
def install_release(request) -> Iterator[InstallFactory]:
    """Factory: install a published release and serve it with its updater
    pointed at a release server. Everything started is stopped at teardown;
    the workdir is kept only when the test failed."""
    started: list[tuple[PayserverHandle, Path]] = []

    def _install(
        release: Release, catalog: ReleaseServer, *, extra_env: dict[str, str] | None = None,
        label: str = "",
    ) -> tuple[PayserverHandle, Path]:
        zip_path = releases.ensure_standalone_zip(release)
        workdir = SESSION_TMP / f"upgrade-{label or release.tag}-{uuid.uuid4().hex[:8]}"
        install = install_release_zip(zip_path, workdir)
        point_updater_at(install, catalog.releases_base)
        handle = start_installed_payserver(
            install, workdir, releases_base=catalog.releases_base, extra_env=extra_env
        )
        started.append((handle, workdir))
        return handle, install

    yield _install

    green = _test_went_green(request)
    for handle, workdir in started:
        handle.server.stop(grace_s=10.0)
        _maybe_remove_workdir(workdir, green)


def copy_data_dir(src: Path, dest: Path) -> None:
    """A merchant's folder copy: every file in data/, sidecars included."""
    if dest.exists():
        shutil.rmtree(dest)
    shutil.copytree(src, dest)
