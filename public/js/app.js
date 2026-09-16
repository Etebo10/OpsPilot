"use strict";

const themeToggle =
    document.querySelector("[data-theme-toggle]");

function syncThemeIcon() {
    if (!themeToggle) {
        return;
    }

    const isLight =
        document.documentElement.dataset.theme === "light";

    const icon = themeToggle.querySelector("i");

    if (icon) {
        icon.className = isLight
            ? "fa-solid fa-moon"
            : "fa-solid fa-sun";
    }

    themeToggle.setAttribute(
        "aria-label",
        isLight ? "Switch to dark theme" : "Switch to light theme"
    );
}

syncThemeIcon();

if (themeToggle) {
    themeToggle.addEventListener("click", () => {
        const nextTheme =
            document.documentElement.dataset.theme === "light"
                ? "dark"
                : "light";

        document.documentElement.dataset.theme = nextTheme;
        localStorage.setItem("opspilot-theme", nextTheme);

        syncThemeIcon();
    });
}


/*
|--------------------------------------------------------------------------
| Password visibility
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        "[data-password-toggle]"
    )
    .forEach(button => {

        button.addEventListener(
            "click",
            () => {

                const inputId =
                    button.dataset.passwordToggle;

                const input =
                    document.getElementById(
                        inputId
                    );

                if (!input) return;

                const icon =
                    button.querySelector("i");

                if (
                    input.type === "password"
                ) {

                    input.type =
                        "text";

                    button.setAttribute(
                        "aria-label",
                        "Hide password"
                    );

                    if (icon) {

                        icon.classList.remove(
                            "fa-eye"
                        );

                        icon.classList.add(
                            "fa-eye-slash"
                        );
                    }

                } else {

                    input.type =
                        "password";

                    button.setAttribute(
                        "aria-label",
                        "Show password"
                    );

                    if (icon) {

                        icon.classList.remove(
                            "fa-eye-slash"
                        );

                        icon.classList.add(
                            "fa-eye"
                        );
                    }
                }
            }
        );
    });


/*
|--------------------------------------------------------------------------
| Mobile navigation
|--------------------------------------------------------------------------
*/

const mobileMenu =
    document.getElementById(
        "mobileMenu"
    );

const sidebar =
    document.getElementById(
        "sidebar"
    );

const sidebarBackdrop =
    document.querySelector(
        "[data-sidebar-backdrop]"
    );

if (
    mobileMenu &&
    sidebar
) {

    mobileMenu.addEventListener(
        "click",
        () => {

            const isOpen =
                sidebar.classList.toggle(
                    "open"
                );

            sidebarBackdrop?.classList.toggle(
                "open",
                isOpen
            );

            mobileMenu.setAttribute(
                "aria-expanded",
                String(isOpen)
            );
        }
    );


    document.addEventListener(
        "click",
        event => {

            if (!sidebar.classList.contains("open")) {
                return;
            }

            if (
                !sidebar.contains(event.target) &&
                !mobileMenu.contains(event.target)
            ) {

                sidebar.classList.remove(
                    "open"
                );

                sidebarBackdrop?.classList.remove(
                    "open"
                );

                mobileMenu.setAttribute(
                    "aria-expanded",
                    "false"
                );
            }
        }
    );

    sidebarBackdrop?.addEventListener(
        "click",
        () => {
            sidebar.classList.remove("open");
            sidebarBackdrop.classList.remove("open");
            mobileMenu.setAttribute("aria-expanded", "false");
        }
    );
}


/*
|--------------------------------------------------------------------------
| Command palette
|--------------------------------------------------------------------------
*/

const commandOverlay =
    document.getElementById(
        "commandOverlay"
    );

const commandInput =
    document.getElementById(
        "commandInput"
    );

const searchTrigger =
    document.getElementById(
        "searchTrigger"
    );

const aiCommand =
    document.getElementById(
        "aiCommand"
    );

const aiAction =
    document.getElementById(
        "aiAction"
    );

const paletteTriggers =
    document.querySelectorAll(
        "[data-command-palette]"
    );


function openCommandPalette() {

    if (!commandOverlay) {
        return;
    }

    commandOverlay.classList.add(
        "open"
    );

    commandOverlay.setAttribute(
        "aria-hidden",
        "false"
    );

    requestAnimationFrame(
        () => {

            if (commandInput) {
                commandInput.focus();
            }
        }
    );
}


function closeCommandPalette() {

    if (!commandOverlay) {
        return;
    }

    commandOverlay.classList.remove(
        "open"
    );

    commandOverlay.setAttribute(
        "aria-hidden",
        "true"
    );

    if (commandInput) {
        commandInput.value = "";
    }
}


if (searchTrigger) {

    searchTrigger.addEventListener(
        "click",
        openCommandPalette
    );
}


if (aiCommand) {

    aiCommand.addEventListener(
        "click",
        openCommandPalette
    );
}


if (aiAction) {

    aiAction.addEventListener(
        "click",
        openCommandPalette
    );
}

paletteTriggers.forEach(trigger => {
    trigger.addEventListener(
        "click",
        openCommandPalette
    );
});


if (commandOverlay) {

    commandOverlay.addEventListener(
        "click",
        event => {

            if (
                event.target ===
                commandOverlay
            ) {

                closeCommandPalette();
            }
        }
    );
}


document.addEventListener(
    "keydown",
    event => {

        /*
        |--------------------------------------------------------------------------
        | Escape
        |--------------------------------------------------------------------------
        */

        if (
            event.key ===
            "Escape"
        ) {

            closeCommandPalette();
        }


        /*
        |--------------------------------------------------------------------------
        | Command/Ctrl + K
        |--------------------------------------------------------------------------
        */

        if (
            (
                event.metaKey ||
                event.ctrlKey
            )
            &&
            event.key.toLowerCase() === "k"
        ) {

            event.preventDefault();

            openCommandPalette();
        }


        /*
        |--------------------------------------------------------------------------
        | Command/Ctrl + J
        |--------------------------------------------------------------------------
        */

        if (
            (
                event.metaKey ||
                event.ctrlKey
            )
            &&
            event.key.toLowerCase() === "j"
        ) {

            event.preventDefault();

            openCommandPalette();
        }
    }
);


/*
|--------------------------------------------------------------------------
| Dashboard counters
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        "[data-counter]"
    )
    .forEach(element => {

        const target =
            Number(
                element.dataset.counter
            );

        if (
            !Number.isFinite(target)
        ) {
            return;
        }

        const duration =
            700;

        const start =
            performance.now();


        function animate(
            currentTime
        ) {

            const progress =
                Math.min(
                    (
                        currentTime -
                        start
                    )
                    /
                    duration,
                    1
                );

            const eased =
                1 -
                Math.pow(
                    1 - progress,
                    3
                );

            const current =
                Math.round(
                    target *
                    eased
                );

            element.textContent =
                current.toLocaleString();

            if (
                progress < 1
            ) {

                requestAnimationFrame(
                    animate
                );
            }
        }


        requestAnimationFrame(
            animate
        );
    });


/*
|--------------------------------------------------------------------------
| Prevent accidental double submits
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        "form"
    )
    .forEach(form => {

        form.addEventListener(
            "submit",
            () => {

                const button =
                    form.querySelector(
                        'button[type="submit"]'
                    );

                if (!button) {
                    return;
                }

                if (
                    form.dataset.submitting ===
                    "true"
                ) {

                    return;
                }

                form.dataset.submitting =
                    "true";

                button.dataset.originalText =
                    button.innerHTML;

                button.innerHTML =
                    `
                        <span class="button-spinner"></span>
                        Processing...
                    `;

                button.disabled =
                    true;
            }
        );
    });

    /* =========================================================
   PHASE 2 UI
   ========================================================= */

document.addEventListener("DOMContentLoaded", () => {

    /*
     * MODALS
     */

    const openButtons =
        document.querySelectorAll("[data-modal-open]");

    const closeButtons =
        document.querySelectorAll("[data-modal-close]");


    function openModal(id) {

        const modal =
            document.getElementById(id);

        if (!modal) return;

        modal.classList.add("is-open");

        modal.setAttribute(
            "aria-hidden",
            "false"
        );

        document.body.classList.add("modal-open");

        const firstInput =
            modal.querySelector(
                "input:not([type='hidden']), select, textarea"
            );

        if (firstInput) {

            setTimeout(
                () => firstInput.focus(),
                100
            );
        }
    }


    function closeModal(modal) {

        modal.classList.remove("is-open");

        modal.setAttribute(
            "aria-hidden",
            "true"
        );

        document.body.classList.remove(
            "modal-open"
        );
    }


    openButtons.forEach(button => {

        button.addEventListener(
            "click",
            () => {

                openModal(
                    button.dataset.modalOpen
                );

            }
        );

    });


    closeButtons.forEach(button => {

        button.addEventListener(
            "click",
            () => {

                const modal =
                    button.closest(".modal-backdrop");

                if (modal) {
                    closeModal(modal);
                }

            }
        );

    });


    document.querySelectorAll(
        ".modal-backdrop"
    ).forEach(modal => {

        modal.addEventListener(
            "click",
            event => {

                if (event.target === modal) {
                    closeModal(modal);
                }

            }
        );

    });


    document.addEventListener(
        "keydown",
        event => {

            if (event.key !== "Escape") {
                return;
            }

            document
                .querySelectorAll(
                    ".modal-backdrop.is-open"
                )
                .forEach(closeModal);

        }
    );


    /*
     * COUNT-UP ANIMATION
     */

    document
        .querySelectorAll("[data-count]")
        .forEach(element => {

            const target =
                Number(element.dataset.count);

            if (Number.isNaN(target)) {
                return;
            }

            let current = 0;

            const duration = 700;

            const start =
                performance.now();


            function animate(time) {

                const progress =
                    Math.min(
                        (time - start) / duration,
                        1
                    );

                const eased =
                    1 -
                    Math.pow(
                        1 - progress,
                        3
                    );

                current =
                    Math.round(
                        target * eased
                    );

                element.textContent =
                    current.toLocaleString();

                if (progress < 1) {
                    requestAnimationFrame(
                        animate
                    );
                }

            }

            requestAnimationFrame(animate);

        });


    /*
     * COMING SOON
     */

    document
        .querySelectorAll(".coming-soon")
        .forEach(item => {

            item.addEventListener(
                "click",
                event => {

                    event.preventDefault();

                    const feature =
                        item.dataset.feature ||
                        "This feature";

                    if (typeof window.showToast === "function") {

                        window.showToast(
                            `${feature} is coming in a future phase.`,
                            "info"
                        );

                    }

                }
            );

        });

});

document
    .querySelectorAll("[data-toast]")
    .forEach(toast => {

        const close =
            toast.querySelector(
                "[data-toast-close]"
            );

        close?.addEventListener(
            "click",
            () => toast.remove()
        );


        setTimeout(
            () => toast.remove(),
            5000
        );

    });


window.showToast = function (
    message,
    type = "success"
) {

    const toast =
        document.createElement("div");

    toast.className =
        `toast toast-${type}`;

    toast.innerHTML = `
        <i class="fa-solid fa-circle-check"></i>
        <span>${message}</span>
        <button type="button">
            <i class="fa-solid fa-xmark"></i>
        </button>
    `;

    document.body.appendChild(toast);

    toast
        .querySelector("button")
        .addEventListener(
            "click",
            () => toast.remove()
        );

    setTimeout(
        () => toast.remove(),
        4500
    );
};