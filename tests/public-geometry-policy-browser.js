'use strict';
// Browser CSS parser plus actual PHP block output with simulated WordPress data.
// No Theme checkout is needed. Fixtures do not establish deployed acceptance.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright-core');
const root = path.resolve(__dirname, '..');
function cssFiles(dir) {
    return fs.readdirSync(dir, {withFileTypes:true}).flatMap(entry => {
        const file = path.join(dir, entry.name);
        return entry.isDirectory() ? cssFiles(file) : entry.name.endsWith('.css') ? [file] : [];
    });
}
const files = cssFiles(path.join(root, 'assets/css'));
assert.ok(files.length, 'Public stylesheet inventory must not be empty');
const markup = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname,'public-blocks-fixture.php')], {encoding:'utf8'});
(async () => {
    const executablePath = process.env.LUNARA_BROWSER_EXECUTABLE || process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe','/usr/bin/google-chrome'].find(fs.existsSync);
    const browser = await chromium.launch({headless:true, ...(executablePath ? {executablePath} : {})});
    try {
        const page = await browser.newPage();
        const inspect = css => page.evaluate(css => {
            const sheet = new CSSStyleSheet(); sheet.replaceSync(css); const errors = [];
            function walk(rules) { for (const rule of rules) {
                if (rule.style) for (const property of rule.style) {
                    if (!/^border(?:-[a-z]+)*-radius$/.test(property) && !/^--.*radius/.test(property)) continue;
                    const value = rule.style.getPropertyValue(property).trim();
                    if (/^(?:0(?:px|rem|em|%)?\s*)+$/.test(value)) continue;
                    errors.push(`${rule.selectorText || rule.cssText.split('{')[0]} ${property}: ${value}`);
                }
                if (rule.cssRules) walk(rule.cssRules);
            }}
            walk(sheet.cssRules); return errors;
        }, css);
        for (const file of files) assert.deepEqual(await inspect(fs.readFileSync(file,'utf8')), [], path.relative(root,file));
        assert.equal((await inspect('@media(max-width:600px){.new-component:hover{border-start-start-radius:12px}}')).length,1);
        assert.equal((await inspect('.new-component{--surface-radius:999px}')).length,1);
        assert.equal((await inspect('.new-component{border-radius:0}')).length,0);
        const css = files.map(file => fs.readFileSync(file,'utf8')).join('\n');
        const selector = '.lunara-journal-card, .lunara-journal-card__image, .lunara-journal-card__image img, .lunara-journal-lane';
        let checks = 0;
        for (const width of [320,768,1440]) {
            await page.setViewportSize({width,height:900});
            await page.setContent(`<style>*{box-sizing:border-box}body{margin:0;padding:16px;background:#0a1520;color:#fff}section{margin-bottom:32px}${css}</style>${markup}`);
            assert.equal(await page.locator('[data-layout]').count(),5);
            assert.equal(await page.locator('.lunara-journal-card').count(),12);
            assert.equal(await page.locator('.lunara-journal-card__image img').count(),8, 'Absent artwork is represented without a fake frame');
            assert.equal(await page.locator('.lunara-journal-lane').getAttribute('href'),'/journal/type/news/');
            for (const state of ['default','hover','focus']) {
                if (state === 'hover') await page.locator('.lunara-journal-lane').hover();
                if (state === 'focus') await page.locator('.lunara-journal-lane').focus();
                const failures = await page.locator(selector).evaluateAll(nodes => nodes.flatMap(node => {
                    const style = getComputedStyle(node);
                    return ['borderTopLeftRadius','borderTopRightRadius','borderBottomLeftRadius','borderBottomRightRadius'].filter(key => parseFloat(style[key]) !== 0).map(key => `${node.className} ${key}=${style[key]}`);
                }));
                assert.deepEqual(failures,[],`${width}px ${state}`);
                checks++;
            }
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),`${width}px fits viewport`);
        }
        // Mutation proves the rendered check can see a curve introduced by a cascade.
        await page.addStyleTag({content:'.lunara-journal-lane:focus{border-radius:8px}'});
        assert.equal(await page.locator('.lunara-journal-lane').evaluate(node => getComputedStyle(node).borderTopLeftRadius),'8px');
        console.log(`Dispatch geometry: ${files.length} public stylesheets, three CSS mutations, five real renderer variants at three widths, ${checks} state checks and one cascade mutation passed.`);
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
