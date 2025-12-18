const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:10005';
const COOKIE_STRING = process.env.WP_COOKIES;
const hasCookies = Boolean(COOKIE_STRING);

test.skip(!hasCookies, 'Set WP_COOKIES env (e.g. "name=value; name2=value2") to run authenticated tests.');

function parseCookieString(str) {
  return str
    .split(';')
    .map((segment) => segment.trim())
    .filter(Boolean)
    .map((segment) => {
      const eq = segment.indexOf('=');
      const name = segment.slice(0, eq);
      const value = segment.slice(eq + 1);
      return {
        name,
        value: decodeURIComponent(value),
        domain: 'localhost',
        path: '/',
      };
    });
}

const attachConsoleWatcher = (page) => {
  const errors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      errors.push(msg.text());
    }
  });
  return errors;
};

// Apply cookies to every context
test.beforeEach(async ({ context }) => {
  if (!hasCookies) return;
  await context.addCookies(parseCookieString(COOKIE_STRING));
});

test('map page renders without console errors and shows map root', async ({ page }) => {
  const errors = attachConsoleWatcher(page);
  const response = await page.goto(`${BASE_URL}/mapa/`, { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expect(page.locator('#db-map')).toBeVisible({ timeout: 15000 });
  await expect(page.locator('body')).toHaveClass(/db-map-app/);
  expect(page.url()).toContain('/mapa');
  expect(errors).toEqual([]);
});

test('nearby queue admin page loads', async ({ page }) => {
  const errors = attachConsoleWatcher(page);
  const res = await page.goto(`${BASE_URL}/wp-admin/tools.php?page=db-nearby-queue`, { waitUntil: 'domcontentloaded' });
  expect(res?.status()).toBe(200);
  expect(page.url()).not.toContain('wp-login.php');
  await expect(page.locator('body.wp-admin')).toBeVisible({ timeout: 15000 });
  await expect(page.locator('text=Nearby Queue')).toBeVisible({ timeout: 15000 });
  expect(errors).toEqual([]);
});
