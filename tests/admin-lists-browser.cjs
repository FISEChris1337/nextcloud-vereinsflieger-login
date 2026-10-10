/* SPDX-License-Identifier: AGPL-3.0-or-later */
"use strict";
const fs = require("node:fs");
const assert = require("node:assert/strict");
const { chromium } = require("playwright");
(async () => {
    const fixture = JSON.parse(fs.readFileSync(process.argv[2], "utf8"));
    const browser = await chromium.launch({ headless: true, executablePath: process.env.VF_BROWSER_PATH });
    let checks = 0;
    const check = (ok, label) => { assert.ok(ok, label); checks++; };
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        const login = async (target, uid, password) => {
            await target.goto(fixture.url + "/index.php/login?direct=1");
            await target.locator('input[name="user"]').fill(uid);
            await target.locator('input[name="password"]').fill(password);
            await target.locator('button[type="submit"]').click();
            await target.waitForURL(/apps\//);
        };
        await login(page, fixture.uid, fixture.password);
        await page.goto(fixture.url + "/index.php/settings/admin/vereinsflieger_login");
        await page.locator("#vf-admin").waitFor();
        await page.waitForFunction(() => [...document.querySelectorAll(".vf-list-viewport")].length === 4 && [...document.querySelectorAll(".vf-list-viewport")].every((el) => el.getAttribute("aria-busy") === "false"));
        const state = await page.locator("#vf-admin").evaluate((el) => JSON.parse(el.dataset.state));
        const listUrl = new URL(state.listUrl, fixture.url).href;
        const token = await page.evaluate(() => OC.requestToken);
        check(state.settings.snapshots.length === 0 && state.settings.provisionedLinks.length === 0, "initial page does not embed every record");
        for (const kind of ["identities", "snapshots", "warnings", "pauses"]) {
            const response = await context.request.post(listUrl, { data: { kind, page: 1 }, headers: { requesttoken: token } });
            const data = await response.json();
            check(response.status() === 200 && Array.isArray(data.items) && data.items.length <= 25, "admin endpoint pages " + kind);
        }
        const noToken = await context.request.post(listUrl, { data: { kind: "snapshots" }, maxRedirects: 0 });
        check([403, 412].includes(noToken.status()), "list endpoint requires CSRF");
        const invalid = await context.request.post(listUrl, { data: { kind: "snapshots", page: -1 }, headers: { requesttoken: token } });
        check(invalid.status() === 400, "list endpoint validates pagination");
        const guest = await browser.newContext();
        const anonymous = await guest.request.post(listUrl, { data: { kind: "identities" }, maxRedirects: 0 });
        check(anonymous.status() !== 200, "list endpoint rejects guests");
        const member = await browser.newContext();
        const memberPage = await member.newPage();
        await login(memberPage, fixture.memberUid, fixture.memberPassword);
        const memberToken = await memberPage.evaluate(() => OC.requestToken);
        const denied = await member.request.post(listUrl, { data: { kind: "identities" }, headers: { requesttoken: memberToken }, maxRedirects: 0 });
        check([403, 412].includes(denied.status()), "list endpoint rejects non-admins with valid CSRF");
        await page.locator('input[name="cid"]').fill("999999");
        await page.locator("#vf-snapshots input[type=search]").fill("nonexistent-account");
        await page.locator("#vf-snapshots input[type=search]").press("Enter");
        await page.waitForFunction(() => document.querySelector("#vf-snapshots .vf-list-viewport").getAttribute("aria-busy") === "false");
        check((await page.locator('input[name="cid"]').inputValue()) === "999999", "native search preserves unsaved settings");
        await page.setViewportSize({ width: 390, height: 844 });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), "native mobile admin has no horizontal overflow");
        check(errors.length === 0, "native admin has no JavaScript errors");
        console.log(JSON.stringify({ checks, liveVfCalls: 0 }));
        await member.close();
        await guest.close();
    } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
