/* SPDX-License-Identifier: AGPL-3.0-or-later */
"use strict";
const fs = require("node:fs");
const path = require("node:path");
const assert = require("node:assert/strict");
const { chromium } = require("playwright");
(async () => {
    const fixturePath = process.argv[2];
    if (!fixturePath)
        throw new Error(
            "Pass protected JSON with temporary admin uid/password/url",
        );
    const artifacts = path.dirname(path.resolve(fixturePath));
    const fixture = JSON.parse(fs.readFileSync(fixturePath));
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.VF_BROWSER_PATH,
    });
    let count = 0;
    const results = [];
    const check = (condition, label) => {
        assert.ok(condition, label);
        results.push(label);
        count++;
        console.log("PASS " + label);
    };
    try {
        const context = await browser.newContext({ locale: "de-DE" });
        const page = await context.newPage();
        const errors = [];
        page.on("pageerror", (e) => errors.push(e.message));
        await page.goto(fixture.url + "/index.php/login?direct=1");
        await page.locator('input[name="user"]').fill(fixture.uid);
        await page.locator('input[name="password"]').fill(fixture.password);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL(/apps\//);
        check(
            !page.url().includes("/login"),
            "native local admin login succeeds over HTTPS",
        );
        await page.goto(
            fixture.url + "/index.php/settings/admin/vereinsflieger_login",
        );
        await page.locator("#vf-admin").waitFor();
        check(
            (await page.locator("#vf-admin h2").textContent()) ===
                "Anmeldung & Vereinsrollen",
            "admin page renders inside real Nextcloud",
        );
        check(
            (await page.locator('input[name="createAccounts"]').count()) ===
                1 &&
                (await page.locator('input[name="defaultLogin"]').count()) ===
                    1,
            "account creation and default login options available",
        );
        check(
            (await page
                .locator('input[name="linkExistingByEmail"]')
                .count()) === 1 &&
                (await page
                    .locator('input[name="showLocalLoginLink"]')
                    .count()) === 1,
            "new migration and local login link options render in native admin UI",
        );
        check(
            (await page
                .locator('#vf-protection input[type="number"]')
                .count()) === 8,
            "all configurable protection fields render in native admin UI",
        );
        check(
            (await page
                .locator('select[name="excludedAccounts"] option')
                .filter({ hasText: fixture.uid })
                .count()) === 1,
            "existing local admin selectable for exclusion",
        );
        check(
            (await page.locator("#vf-add-role").isDisabled()) ||
                (await page.locator("#vf-role-map input").count()) === 0,
            "real role catalog has no free text role entry",
        );
        check(
            (await page.locator(".vf-usage strong").textContent()).includes(
                "API-Aufrufe",
            ),
            "real provider usage visible",
        );
        // The operator may configure the app concurrently. Never replace their settings.
        const state = await page
            .locator("#vf-admin")
            .evaluate((el) => JSON.parse(el.dataset.state));
        const saveUrl = new URL(state.saveUrl, fixture.url).href;
        const checkUrl = new URL(state.checkUrl, fixture.url).href;
        const unauthorized = await context.request.post(saveUrl, {
            data: { settings: state.settings },
        });
        check(
            unauthorized.status() === 412 || unauthorized.status() === 403,
            "missing CSRF token cannot save admin configuration",
        );
        const invalid = await page.request.post(saveUrl, {
            data: { settings: { ...state.settings, cid: "0" } },
            headers: {
                requesttoken: await page.evaluate(() => OC.requestToken),
            },
        });
        check(
            invalid.status() === 400,
            "authenticated admin save validates without replacing current settings",
        );
        const badcheck = await page.request.post(checkUrl, {
            data: {},
            headers: {
                requesttoken: await page.evaluate(() => OC.requestToken),
            },
        });
        check(
            [200, 400].includes(badcheck.status()),
            "local check returns configuration result without provider call",
        );
        await page.locator("#vf-check").click();
        await page.waitForFunction(() =>
            ["success", "error"].includes(
                document.getElementById("vf-check-result").dataset.state,
            ),
        );
        check(
            (await page.locator("#vf-check-result").isVisible()) &&
                /^(OK|Fehler):/.test(
                    await page.locator("#vf-check-result").textContent(),
                ),
            "actual local check shows visible OK or error beside button",
        );
        check(
            (await page
                .locator("#vf-check-result")
                .getAttribute("data-state")) ===
                (badcheck.status() === 200 ? "success" : "error"),
            "visible check result agrees with native server validation",
        );
        await page.goto(
            fixture.url +
                "/index.php/settings/admin/vereinsflieger_login?forceLanguage=en",
        );
        check(
            (await page.locator("#vf-admin h2").textContent()) ===
                "Login & club roles" &&
                (await page.locator("#vf-check").textContent()) ===
                    "Check saved configuration",
            "native admin UI follows an English language selection",
        );
        const englishCheck = await page.request.post(
            checkUrl + "?forceLanguage=en",
            {
                data: {},
                headers: {
                    requesttoken: await page.evaluate(() => OC.requestToken),
                },
            },
        );
        check(
            !/Bitte|Konfiguration|Vereins-ID/.test(
                (await englishCheck.json()).message,
            ),
            "native check response translates validation and status messages into English",
        );
        await page.goto(
            fixture.url + "/index.php/settings/admin/vereinsflieger_login",
        );
        await page.screenshot({
            path: path.join(artifacts, "admin.png"),
            fullPage: true,
        });
        await page.setViewportSize({ width: 390, height: 844 });
        check(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
            "real mobile admin page has no horizontal overflow",
        );
        await page.screenshot({
            path: path.join(artifacts, "admin-mobile.png"),
            fullPage: true,
        });
        const guest = await browser.newContext({ locale: "de-DE" });
        const guestPage = await guest.newPage();
        await guestPage.goto(
            fixture.url + "/index.php/apps/vereinsflieger_login/login",
        );
        check(
            (await guestPage.locator(".vf-login").count()) === 1,
            "provider login form renders over HTTPS",
        );
        const nativeLogin = await guest.newPage();
        await nativeLogin.goto(fixture.url + "/index.php/login?direct=1");
        await nativeLogin.locator('input[name="user"]').waitFor();
        const nativeBox = await nativeLogin
            .locator(".login-box")
            .evaluate((el) => {
                const s = getComputedStyle(el);
                return {
                    width: el.getBoundingClientRect().width,
                    padding: s.padding,
                    borderRadius: s.borderRadius,
                    background: s.backgroundColor,
                };
            });
        const vfBox = await guestPage.locator(".vf-login").evaluate((el) => {
            const s = getComputedStyle(el);
            return {
                width: el.getBoundingClientRect().width,
                padding: s.padding,
                borderRadius: s.borderRadius,
                background: s.backgroundColor,
            };
        });
        check(
            JSON.stringify(nativeBox) === JSON.stringify(vfBox),
            "provider card uses the same width padding radius and themed background as native login",
        );
        await nativeLogin.close();
        check(
            (await guestPage.locator(".vf-login h2").textContent()) ===
                "Anmelden mit Vereinsflieger" &&
                (await guestPage.locator("#lost-password").count()) === 0,
            "localized provider headline and no local password-reset control",
        );
        if (state.settings.enabled) {
            await guestPage
                .locator('input[name="password"]')
                .fill("Synthetic-password");
            await guestPage.locator("#vf-password-toggle").click();
            check(
                (await guestPage
                    .locator('input[name="password"]')
                    .getAttribute("type")) === "text",
                "provider password visibility works in native browser",
            );
            await guestPage.locator("#vf-password-toggle").click();
            check(
                (await guestPage
                    .locator('input[name="password"]')
                    .getAttribute("type")) === "password",
                "provider password can be hidden again",
            );
            const inputResponse = await guest.request.post(
                new URL(
                    await guestPage
                        .locator("#vf-login-form")
                        .getAttribute("action"),
                    fixture.url,
                ).href,
                {
                    headers: { "Accept-Language": "de-DE" },
                    form: {
                        username: "bad\ninput",
                        password: "Synthetic-password",
                        requesttoken: await guestPage
                            .locator('input[name="requesttoken"]')
                            .inputValue(),
                    },
                },
            );
            const inputBody = await inputResponse.text();
            check(
                inputResponse.status() === 403 &&
                    inputBody.includes(
                        "ohne Vereinsflieger-Anfrage",
                    ),
                "malformed login is rejected by real controller before contacting provider",
            );
        }
        await guestPage.screenshot({
            path: path.join(artifacts, "provider-login.png"),
            fullPage: true,
        });
        const englishGuest = await browser.newContext({ locale: "en-US" });
        const englishPage = await englishGuest.newPage();
        await englishPage.goto(
            fixture.url + "/index.php/apps/vereinsflieger_login/login",
        );
        check(
            (await englishPage.locator(".vf-login h2").textContent()) ===
                "Log in with Vereinsflieger",
            "provider login follows the guest browser English language",
        );
        await englishGuest.close();
        const localLinks = guestPage.locator(".vf-local-login");
        if (state.settings.showLocalLoginLink) {
            check(
                (await localLinks.getAttribute("href")).includes("direct=1"),
                "configured visible local escape link is present in real login form",
            );
        } else {
            check(
                (await localLinks.count()) === 0,
                "configured hidden local escape link is absent from real login form",
            );
        }
        const guestAdmin = await guest.request.post(saveUrl, {
            data: { settings: state.settings },
        });
        check(
            guestAdmin.status() !== 200,
            "anonymous request cannot administer app",
        );
        check(
            errors.length === 0,
            "no app JavaScript errors in real admin browser",
        );
        await guest.close();
        fs.writeFileSync(
            path.join(artifacts, "browser-runtime-results.json"),
            JSON.stringify(
                { checks: count, results, url: fixture.url, liveVfCalls: 0 },
                null,
                2,
            ),
        );
        console.log(
            count + " real Nextcloud browser checks passed. No live VF calls.",
        );
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e.message);
    process.exitCode = 1;
});
