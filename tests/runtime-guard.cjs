/* SPDX-License-Identifier: AGPL-3.0-or-later */
"use strict";
const fs = require("node:fs"),
    path = require("node:path"),
    assert = require("node:assert/strict");
const { chromium } = require("playwright");
const fixturePath = process.argv[2];
if (!fixturePath)
    throw new Error("Pass protected JSON with fixture uid/password/url");
const artifacts = path.dirname(path.resolve(fixturePath));
const f = JSON.parse(fs.readFileSync(fixturePath));
(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.VF_BROWSER_PATH,
    });
    let count = 0;
    const results = [];
    const check = (ok, label) => {
        assert.ok(ok, label);
        count++;
        results.push(label);
        console.log("PASS " + label);
    };
    try {
        for (const username of [f.uid, "fixture@vf-runtime.invalid"]) {
            const c = await browser.newContext();
            const p = await c.newPage();
            await p.goto(f.url + "/index.php/login?direct=1");
            await p.locator('input[name="user"]').fill(username);
            await p.locator('input[name="password"]').fill(f.password);
            const response = p.waitForResponse(
                (r) =>
                    r.request().method() === "POST" &&
                    r.url().includes("/login"),
            );
            await p.locator('button[type="submit"]').click();
            await response;
            await p.waitForLoadState("networkidle");
            check(
                p.url().includes("/login"),
                "valid local password rejected for SSO " +
                    (username === f.uid ? "account ID" : "email alias"),
            );
            const info = await c.request.get(
                f.url + "/ocs/v2.php/cloud/user?format=json",
                { headers: { "OCS-APIRequest": "true" } },
            );
            check(
                info.status() !== 200,
                "failed native password login leaves no authenticated user",
            );
            await c.close();
        }
        const basic = Buffer.from(f.uid + ":" + f.password).toString("base64");
        const direct = await browser.newContext();
        const denied = await direct.request.fetch(
            f.url + "/remote.php/dav/files/" + f.uid + "/",
            {
                method: "PROPFIND",
                headers: { Authorization: "Basic " + basic, Depth: "0" },
            },
        );
        console.log("DAV local password response: " + denied.status());
        check(
            [401, 403].includes(denied.status()),
            "WebDAV rejects local account password for SSO user",
        );
        await direct.close();
        const c = await browser.newContext();
        const p = await c.newPage();
        const login = await c.request.post(
            f.url + "/ocs-provider/vf_session_probe_20261007.php",
            { form: { key: f.password } },
        );
        check(
            login.status() === 200,
            "verified request-local SSO path passes native login guard",
        );
        const data = await login.json();
        await p.goto(new URL(data.url, f.url).href);
        await p.waitForURL(/\/apps\/(?!vereinsflieger_login)/);
        check(
            !p.url().includes("/login"),
            "SSO finalizer succeeds with native guard active",
        );
        const create = await c.request.post(
            f.url + "/index.php/settings/personal/authtokens",
            {
                data: { name: "VF runtime fixture" },
                headers: {
                    requesttoken: await p.evaluate(() => OC.requestToken),
                },
            },
        );
        check(
            create.status() === 200,
            "completed SSO session can create native app password without local password",
        );
        const app = await create.json();
        const token = app.token;
        check(
            typeof token === "string" && token.length > 10,
            "native app password generated",
        );
        const client = await browser.newContext();
        const auth = Buffer.from(app.loginName + ":" + token).toString(
            "base64",
        );
        const files = await client.request.fetch(
            f.url + "/remote.php/dav/files/" + f.uid + "/",
            {
                method: "PROPFIND",
                headers: { Authorization: "Basic " + auth, Depth: "0" },
            },
        );
        check(
            files.status() === 207,
            "WebDAV accepts native delegated app password for SSO account",
        );
        await client.close();
        const guest = await browser.newContext();
        const visual = await guest.newPage();
        await visual.goto(f.url + "/index.php/apps/vereinsflieger_login/login");
        const card = await visual.locator(".vf-login").evaluate((el) => {
            const s = getComputedStyle(el);
            return {
                bg: s.backgroundColor,
                pad: s.paddingTop,
                shadow: s.boxShadow,
            };
        });
        check(
            card.bg !== "rgba(0, 0, 0, 0)" &&
                card.pad !== "0px" &&
                card.shadow !== "none",
            "VF form has opaque framed card over background",
        );
        await visual.screenshot({
            path: path.join(artifacts, "dev1-login-framed.png"),
            fullPage: true,
        });
        await visual.setViewportSize({ width: 390, height: 844 });
        check(
            await visual.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            "framed VF login fits mobile width",
        );
        await visual.screenshot({
            path: path.join(artifacts, "dev1-login-framed-mobile.png"),
            fullPage: true,
        });
        await guest.close();
        fs.writeFileSync(
            path.join(artifacts, "guard-browser-results.json"),
            JSON.stringify({ checks: count, results, liveVfCalls: 0 }, null, 2),
        );
        console.log(
            count +
                " real password guard/app-password/login-card checks passed.",
        );
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e.message);
    process.exitCode = 1;
});
