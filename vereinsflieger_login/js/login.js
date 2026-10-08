/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    "use strict";
    const init = () => {
        const pause = document.querySelector("[data-pause-seconds]");
        if (pause) {
            const until = Date.now() + Number(pause.dataset.pauseSeconds) * 1000;
            const update = () => {
                const seconds = Math.max(0, Math.ceil((until - Date.now()) / 1000));
                const time = String(Math.floor(seconds / 60)).padStart(2, "0") + ":" + String(seconds % 60).padStart(2, "0");
                pause.textContent = seconds > 0 ? pause.dataset.pauseMessage.replace("%s", time) : pause.dataset.pauseExpired;
                if (seconds === 0) window.clearInterval(timer);
            };
            const timer = window.setInterval(update, 1000);
            update();
        }
        const form = document.getElementById("vf-login-form");
        if (!form) return;
        const password = document.getElementById("vf-password");
        const toggle = document.getElementById("vf-password-toggle");
        toggle.hidden = false;
        const hidePassword = () => {
            password.type = "password";
            toggle.setAttribute("aria-pressed", "false");
            toggle.setAttribute("aria-label", toggle.dataset.show);
        };
        toggle.addEventListener("click", () => {
            const visible = password.type === "password";
            password.type = visible ? "text" : "password";
            toggle.setAttribute("aria-pressed", String(visible));
            toggle.setAttribute(
                "aria-label",
                visible ? toggle.dataset.hide : toggle.dataset.show,
            );
        });
        let submitted = false;
        form.addEventListener("submit", (event) => {
            if (submitted) {
                event.preventDefault();
                return;
            }
            submitted = true;
            hidePassword();
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            button.querySelector(".vf-submit-label").textContent =
                button.dataset.loading;
        });
        window.addEventListener("pageshow", (event) => {
            if (event.persisted) {
                password.value = "";
                window.location.reload();
            }
        });
    };
    if (document.readyState === "loading")
        document.addEventListener("DOMContentLoaded", init);
    else init();
})();
