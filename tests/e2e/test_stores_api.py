"""Greenfield store CRUD: create / list / get / delete."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver


def test_list_stores_returns_setup_store(configured: ConfiguredPayserver) -> None:
    stores = configured.greenfield.list_stores()
    assert any(s["id"] == configured.store_id for s in stores)
    setup_store = next(s for s in stores if s["id"] == configured.store_id)
    assert setup_store["name"] == "Test Store"
    assert "createdTime" in setup_store


def test_get_store_returns_metadata(configured: ConfiguredPayserver) -> None:
    store = configured.greenfield.get_store(configured.store_id)
    assert store["id"] == configured.store_id
    assert store["name"] == "Test Store"


def test_get_nonexistent_store_returns_404(configured: ConfiguredPayserver) -> None:
    with pytest.raises(RuntimeError, match="404"):
        configured.greenfield.get_store("store_does_not_exist_xxx")


def test_create_and_delete_store_via_api(configured: ConfiguredPayserver) -> None:
    gc = configured.greenfield
    new_store = gc._post("/api/v1/stores", {"name": "Second Store"})
    assert new_store["name"] == "Second Store"
    assert new_store["id"].startswith("store_"), new_store

    stores = gc.list_stores()
    assert any(s["id"] == new_store["id"] for s in stores)

    gc._delete(f"/api/v1/stores/{new_store['id']}")
    stores_after = gc.list_stores()
    assert not any(s["id"] == new_store["id"] for s in stores_after)


def test_create_store_requires_name(configured: ConfiguredPayserver) -> None:
    with pytest.raises(RuntimeError, match="validation-error"):
        configured.greenfield._post("/api/v1/stores", {})
