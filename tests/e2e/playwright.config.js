const { defineConfig } = require('@playwright/test');
const path = require('path');

const cftArm = '/Users/ondraplas/Library/Caches/ms-playwright/chromium-1200/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing';

module.exports = defineConfig({
  testDir: path.join(__dirname),
  projects: [
    {
      name: 'chrome-for-testing-arm',
      use: {
        browserName: 'chromium',
        headless: true,
        executablePath: cftArm,
        args: ['--no-sandbox', '--disable-crashpad', '--disable-crash-reporter'],
      },
    },
  ],
});
