"""Playwright browser fixture.

We don't use pytest-playwright to keep the plugin set small. The synchronous
playwright API is fine for our needs: one browser per session, fresh context
per test for cookie isolation.
"""
from __future__ import annotations

from typing import Iterator

import pytest


@pytest.fixture(scope="session")
def playwright_instance():
    from playwright.sync_api import sync_playwright

    with sync_playwright() as p:
        yield p


@pytest.fixture(scope="session")
def browser(playwright_instance):
    browser = playwright_instance.chromium.launch(
        headless=True,
        args=["--no-sandbox", "--disable-dev-shm-usage"],
    )
    yield browser
    browser.close()


@pytest.fixture
def page(browser):
    context = browser.new_context()
    page = context.new_page()
    yield page
    context.close()
