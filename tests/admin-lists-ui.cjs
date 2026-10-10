/* SPDX-License-Identifier: AGPL-3.0-or-later */
"use strict";
const { chromium } = require("playwright");
const { pathToFileURL } = require("node:url");
const path = require("node:path");
const assert = require("node:assert/strict");
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.VF_BROWSER_PATH || undefined });
    let checks = 0;
    const check = (condition, label) => { assert.ok(condition, label); checks++; };
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: "de-DE" });
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        await page.addInitScript(() => {
            document.addEventListener("DOMContentLoaded", () => {
                const root = document.getElementById("vf-admin");
                const state = JSON.parse(root.dataset.state);
                state.settings.links = Array.from({ length: 60 }, (_, i) => ({ vfUid: String(i + 1), nextcloudUid: "member_" + i }));
                root.dataset.state = JSON.stringify(state);
                const rows = Array.from({ length: 61 }, (_, i) => ({ uid: "member_" + String(i).padStart(3, "0"), vfUid: String(i + 1), capturedAt: "2026-10-10T06:10:05+00:00", names: ["Mitglied", "<img src=x onerror=window.__unsafe=true>"] }));
                window.__listRequests = [];
                window.__failList = false;
                window.fetch = async (url, options) => {
                    const body = JSON.parse(options.body);
                    if (url !== state.listUrl) {
                        window.__savedSettings = body.settings;
                        return { ok: false, json: async () => ({ message: "Save capture" }) };
                    }
                    window.__listRequests.push(body);
                    if (window.__failList) throw new Error("Synthetic network failure");
                    const term = body.search.toLocaleLowerCase("de");
                    const roleRows = rows.map((item, i) => ({ name: "role_" + String(i).padStart(3, "0") + " · <img src=x onerror=window.__unsafe=true>" }));
                    const filtered = body.kind === "roles" ? roleRows.filter((item) => item.name.toLocaleLowerCase("de").includes(term)) : rows.filter((item) => item.uid.toLocaleLowerCase("de").includes(term));
                    const items = body.kind === "pauses" ? [] : filtered.slice((body.page - 1) * 25, body.page * 25);
                    return { ok: true, json: async () => ({ items, page: body.page, hasMore: body.kind !== "pauses" && filtered.length > body.page * 25 }) };
                };
            });
        });
        await page.goto(pathToFileURL(path.resolve(process.argv[2])).href);
        const roles = page.locator("#vf-received-roles");
        await roles.locator(".vf-role-row").first().waitFor();
        check(await roles.locator(".vf-role-row").count() === 25, "only 25 role rows in DOM");
        check(await page.locator("#vf-identities .vf-identity-row").count() === 25, "identities are paginated");
        check(await page.locator("#vf-sync-warnings .vf-snapshot").count() === 25, "warnings are paginated");
        check(await roles.locator(".vf-list-viewport").evaluate((el) => el.clientHeight <= 362 && el.scrollHeight > el.clientHeight), "role panel height is bounded");
        check(await page.locator("#vf-links").evaluate((el) => el.clientHeight <= 362 && el.scrollHeight > el.clientHeight), "editable account links have bounded height");
        check(await roles.locator("img").count() === 0 && !(await page.evaluate(() => window.__unsafe)), "role names cannot execute HTML");
        check(await roles.locator("strong, time, input[type=checkbox]").count() === 0, "role catalog has no accounts timestamps or selections");
        check(!(await page.locator("#vf-sync-warnings time").first().textContent()).includes("T06:"), "warning date uses readable local format");
        check((await page.locator("#vf-source-url").getAttribute("href")) === "https://github.com/FISEChris1337/nextcloud-vereinsflieger-login", "maintenance links directly to GitHub");
        check((await page.locator("#vf-local-login-url").getAttribute("href")) === "/login?direct=1" && (await page.locator("#vf-remember-lifetime").textContent()).includes("15 T"), "maintenance shows local URL and configured cookie lifetime");
        const prev = roles.getByRole("button", { name: "Zurück", exact: true });
        const next = roles.getByRole("button", { name: "Weiter", exact: true });
        check(await prev.isDisabled(), "first-page previous button disabled");
        await next.click();
        await roles.locator('[role="status"]').filter({ hasText: "Seite 2" }).waitFor();
        check((await roles.locator(".vf-role-row").first().textContent()).startsWith("role_025"), "next page fetches next records");
        await next.click();
        await roles.locator('[role="status"]').filter({ hasText: "Seite 3" }).waitFor();
        check(await roles.locator(".vf-role-row").count() === 11 && await next.isDisabled(), "last page contains remainder");
        await page.locator('input[name="cid"]').fill("57");
        await roles.getByRole("searchbox").fill("role_060");
        await roles.getByRole("searchbox").press("Enter");
        await roles.locator('[role="status"]').filter({ hasText: "Seite 1 · 1 Einträge" }).waitFor();
        check((await roles.locator(".vf-role-row").textContent()).startsWith("role_060") && (await page.locator('input[name="cid"]').inputValue()) === "57", "search resets pagination and preserves unsaved settings");
        check(!(await page.evaluate(() => window.__savedSettings)), "search Enter does not submit configuration");
        check((await roles.getByRole("searchbox").getAttribute("placeholder")) === "Rolle suchen", "role search explains name matching");
        await roles.getByRole("searchbox").fill("ROLE_");
        await roles.getByRole("searchbox").press("Enter");
        await roles.locator('[role="status"]').filter({ hasText: "Seite 1 · 25 Einträge" }).waitFor();
        check(await roles.locator(".vf-role-row").count() === 25 && (await page.evaluate(() => window.__listRequests.at(-1).search)) === "ROLE_", "role search is sent to server and keeps pagination");
        await roles.getByRole("searchbox").fill("role_060");
        await roles.getByRole("searchbox").press("Enter");
        await roles.locator('[role="status"]').filter({ hasText: "Seite 1 · 1 Einträge" }).waitFor();
        await page.getByRole("button", { name: "Einstellungen speichern", exact: true }).click();
        await page.waitForFunction(() => window.__savedSettings);
        check((await page.evaluate(() => window.__savedSettings.links.length)) === 60, "save retains editable links outside scroll viewport");
        await page.evaluate(() => { window.__failList = true; });
        await roles.getByRole("button", { name: "Suchen", exact: true }).click();
        await roles.locator('[role="status"]').filter({ hasText: "Die Liste konnte nicht geladen werden." }).waitFor();
        check(await roles.locator(".vf-role-row").count() === 1, "failed request preserves previous page");
        await page.evaluate(() => { window.__failList = false; });
        await roles.getByRole("searchbox").fill("");
        await roles.getByRole("button", { name: "Suchen", exact: true }).click();
        await roles.locator('[role="status"]').filter({ hasText: "Seite 1 · 25 Einträge" }).waitFor();
        check(await roles.locator(".vf-role-row").count() === 25, "failed list request can be retried");
        await page.setViewportSize({ width: 390, height: 844 });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), "mobile panels have no horizontal overflow");
        check(errors.length === 0, "no browser JavaScript errors");
        console.log(`${checks} paginated admin UI checks passed.`);
    } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
