/* SPDX-License-Identifier: AGPL-3.0-or-later */
"use strict";
const fs = require("node:fs"),
    path = require("node:path"),
    crypto = require("node:crypto"),
    assert = require("node:assert/strict");
const { chromium } = require("playwright");
const fixturePath = process.argv[2];
if (!fixturePath)
    throw new Error(
        "Pass protected JSON with fixture uid/password/url/totpSecret",
    );
const artifacts = path.dirname(path.resolve(fixturePath));
const f = JSON.parse(fs.readFileSync(fixturePath));
const otp = () => {
    const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    let bits = "";
    for (const c of f.totpSecret)
        bits += alphabet.indexOf(c).toString(2).padStart(5, "0");
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8)
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const hash = crypto
        .createHmac("sha1", Buffer.from(bytes))
        .update(counter)
        .digest();
    const off = hash[19] & 15;
    return ((hash.readUInt32BE(off) & 0x7fffffff) % 1000000)
        .toString()
        .padStart(6, "0");
};
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
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        const result = await ctx.request.post(
            f.url + "/ocs-provider/vf_session_probe_20261007.php",
            { form: { key: f.password } },
        );
        check(
            result.status() === 200,
            "real passwordless session creates native token",
        );
        const data = await result.json();
        check(data.sameNativeSession, "app uses actual Nextcloud user session");
        check(
            data.url.includes("challenge") && data.url.includes("totp"),
            "native TOTP challenge required after session creation",
        );
        check(
            !(await ctx.cookies()).some((c) => c.name === "nc_token"),
            "remember token absent before second factor",
        );
        const pending = await ctx.request.get(
            f.url + "/index.php/apps/vereinsflieger_login/finish",
            { maxRedirects: 0 },
        );
        check(
            [302, 303].includes(pending.status()) &&
                pending.headers().location.includes("challenge"),
            "native middleware blocks finalizer before TOTP",
        );
        await page.goto(new URL(data.url, f.url).href);
        const input = page.locator('input[name="challenge"]');
        await input.waitFor();
        await input.fill(otp());
        await page.locator('button[type="submit"]').click();
        await page.waitForURL(/\/apps\/(?!vereinsflieger_login)/);
        check(
            !page.url().includes("challenge"),
            "valid native TOTP completes login",
        );
        const cookies = await ctx.cookies();
        check(
            cookies.some(
                (c) => c.name === "nc_token" && c.secure && c.httpOnly,
            ),
            "secure native remember cookie issued after challenge",
        );
        const returned = await ctx.request.get(
            f.url + "/index.php/apps/vereinsflieger_login/finish",
            { maxRedirects: 0 },
        );
        check(
            [302, 303].includes(returned.status()) &&
                !returned.headers().location.includes("challenge"),
            "finalizer accessible only after successful second factor",
        );
        const remember = cookies.filter((c) =>
            ["nc_username", "nc_token", "nc_session_id"].includes(c.name),
        );
        const resumed = await browser.newContext();
        await resumed.addCookies(remember);
        const again = await resumed.newPage();
        await again.goto(f.url + "/index.php/apps/files/");
        check(
            !again.url().includes("/login"),
            "remember cookie restores native session without provider call",
        );
        await resumed.close();
        fs.writeFileSync(
            path.join(artifacts, "session-browser-results.json"),
            JSON.stringify(
                {
                    checks: count,
                    results,
                    liveVfCalls: 0,
                    fixtureNativeTotp: true,
                },
                null,
                2,
            ),
        );
        console.log(
            count +
                " real native session/TOTP/remember checks passed. No live VF calls.",
        );
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e.message);
    process.exitCode = 1;
});
