(function () {
    if (!window.wpcaWidget) {
        return;
    }

    const root = document.getElementById("wpca-chatbot-root");
    if (!root) {
        return;
    }

    const { settings, restUrl, quipUrl, nonce, strings } = window.wpcaWidget;
    const cariImageUrl = settings.defaultAvatarUrl || settings.avatarUrl || "";
    const avatarUrl = cariImageUrl;
    const avatarMarkup = avatarUrl
        ? `<img class="wpca-avatar__image" src="${escapeAttribute(avatarUrl)}" alt="">`
        : `<span class="wpca-avatar__face">:)</span>`;
    const responseLanguage = normalizeLanguage(settings.responseLanguage) || "auto";
    const titleText = escapeHtml(settings.title);
    const eyebrowText = escapeHtml(settings.panelEyebrow || strings.eyebrow);
    const launcherEyebrow = escapeHtml(settings.launcherEyebrow || "Live-Assistenz");
    const launcherLabel = escapeHtml(settings.launcherLabel || "Jetzt mit unserer Assistenz Asky chatten");
    const launcherDescription = escapeHtml(settings.launcherDescription || "");
    const launcherCta = escapeHtml(settings.launcherCta || "Jetzt starten");
    const motifsMarkup = settings.medicalAnimations ? getMedicalMotifsMarkup() : "";
    const welcomeMessage = escapeHtml(buildWelcomeMessage(settings.welcomeMessage || "", responseLanguage));
    const introMessage = escapeHtml(buildCariIntroMessage(responseLanguage));

    root.innerHTML = `
        <div class="wpca-launcher-shell">
            <button class="wpca-launcher" aria-expanded="false" aria-controls="wpca-panel" aria-label="${escapeAttribute(launcherLabel)}">
                ${getCariAvatarMarkup("launcher")}
            </button>
        </div>
        <div class="wpca-idle-bubble" hidden aria-live="polite"></div>
        <button class="wpca-dock" type="button" hidden aria-label="${escapeAttribute(strings.restoreLabel)}">
            ${getCariAvatarMarkup("dock")}
        </button>
        <div class="wpca-intro-cari" hidden aria-live="polite">
            <div class="wpca-intro-cari__bubble">${introMessage}</div>
            <div class="wpca-intro-cari__avatar">
                ${getCariAvatarMarkup("dock")}
            </div>
        </div>
        <section class="wpca-panel" id="wpca-panel" hidden>
            <header class="wpca-panel__header">
                <div class="wpca-panel__halo" aria-hidden="true"></div>
                <div class="wpca-header__identity">
                    <div>
                        <p class="wpca-eyebrow">${eyebrowText}</p>
                        <h2>${titleText}</h2>
                    </div>
                </div>
                <div class="wpca-header__actions">
                    <span class="wpca-status-pill">${escapeHtml(strings.status)}</span>
                    <button class="wpca-minimize wpca-minimize--panel" type="button" aria-label="${escapeAttribute(strings.minimizeLabel)}">
                        <span aria-hidden="true">-</span>
                    </button>
                    <button class="wpca-close" type="button" aria-label="${escapeAttribute(strings.closeLabel)}">&times;</button>
                </div>
            </header>
            <div class="wpca-messages">
                <article class="wpca-message wpca-message--bot">
                    <div class="wpca-bubble">${welcomeMessage}</div>
                </article>
            </div>
            <div class="wpca-sources" hidden></div>
            <form class="wpca-form">
                <label class="screen-reader-text" for="wpca-input">${escapeHtml(strings.inputLabel)}</label>
                <textarea id="wpca-input" rows="1" placeholder="${escapeAttribute(strings.placeholder)}" required></textarea>
                <button type="submit">${escapeHtml(strings.send)}</button>
            </form>
        </section>
        <div class="wpca-panel-cari" aria-hidden="true">
            ${getCariAvatarMarkup("panel")}
        </div>
    `;

    document.documentElement.style.setProperty("--wpca-accent", settings.accentColor || "#F39ca5");
    document.documentElement.style.setProperty("--wpca-text", settings.textColor || "#ffffff");
    applyBrandColors(settings.accentColor || "#F39ca5");

    const launcherShell = root.querySelector(".wpca-launcher-shell");
    const launcher = root.querySelector(".wpca-launcher");
    const idleBubble = root.querySelector(".wpca-idle-bubble");
    const dock = root.querySelector(".wpca-dock");
    const introCari = root.querySelector(".wpca-intro-cari");
    const launcherAvatar = root.querySelector(".wpca-launcher__avatar");
    const dockAvatar = root.querySelector(".wpca-dock__avatar");
    const panelAvatar = root.querySelector(".wpca-avatar-anim");
    const cariTargets = root.querySelectorAll(".wpca-cari-motion");
    const cariAnim = panelAvatar;
    const cariBubble = root.querySelector(".wpca-avatar-anim .wpca-cari-bubble");
    const minimizeButtons = root.querySelectorAll(".wpca-minimize");
    const panel = root.querySelector(".wpca-panel");
    const closeButton = root.querySelector(".wpca-close");
    const form = root.querySelector(".wpca-form");
    const input = root.querySelector("#wpca-input");
    const messages = root.querySelector(".wpca-messages");
    const sources = root.querySelector(".wpca-sources");
    const storageKey = "wpca-chat-history";
    const dockedStateKey = "wpca-chatbot-docked";
    const introSeenKey = "wpca-cari-intro-seen";
    const appearanceDelayMs = 0;
    const autoDockDelayMs = 0;
    const history = loadStoredHistory();
    const idlePhaseSchedule = [
        { key: "phase1", min: 25000, max: 25000 }
    ];
    let hasAppeared = false;
    let panelOpen = false;
    let isDocked = loadDockedState();
    let typingIndicator = null;
    let idlePhaseTimers = [];
    let idleHideTimer = null;
    let lastIdleMessage = "";
    let autoDockTimer = null;
    let userHasInteracted = false;
    let cariStateTimer = null;
    let firstPanelOpen = true;
    let cariPanelIdleTimer = null;
    let cariPanelIdleHideTimer = null;
    let lastCariQuip = "";

    if (history.length) {
        messages.innerHTML = "";
        history.forEach((item) => {
            appendMessage(item.content, item.role, false);
        });
    }

    syncWidgetVisibility();
    window.setTimeout(() => {
        hasAppeared = true;
        syncWidgetVisibility();
        startFirstVisitIntro();
        resetIdleCycle();
        if (autoDockDelayMs > 0 && !isDocked) {
            autoDockTimer = window.setTimeout(() => {
                if (!userHasInteracted && !panelOpen && !isDocked) {
                    dockWidget();
                }
            }, autoDockDelayMs);
        }
    }, appearanceDelayMs);

    launcher.addEventListener("click", () => {
        markUserActivity();
        togglePanel(true);
    });
    closeButton.addEventListener("click", () => {
        markUserActivity();
        togglePanel(false);
    });
    dock.addEventListener("click", () => {
        markUserActivity();
        restoreWidget(true);
    });
    minimizeButtons.forEach((button) => {
        button.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            markUserActivity();
            dockWidget();
        });
    });
    input.addEventListener("input", () => {
        autoResize();
        markUserActivity();
    });
    input.addEventListener("keydown", (event) => {
        markUserActivity();
        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault();
            if (!form.classList.contains("is-loading")) {
                form.requestSubmit();
            }
        }
    });
    document.addEventListener("keydown", (event) => {
        markUserActivity();
        if (event.key === "Escape") {
            togglePanel(false);
        }
    });
    document.addEventListener("click", markUserActivity, true);
    document.addEventListener("touchstart", markUserActivity, true);
    document.addEventListener("scroll", markUserActivity, true);
    input.addEventListener("focus", markUserActivity);
    input.addEventListener("blur", resetIdleCycle);

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        markUserActivity();

        const message = input.value.trim();
        if (!message) {
            return;
        }

        const requestHistory = history.slice(-6);
        appendMessage(message, "user", false);
        history.push({ role: "user", content: message });
        persistHistory();
        input.value = "";
        autoResize();
        setLoading(true);
        showTypingIndicator();

        try {
            const response = await fetch(restUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-WP-Nonce": nonce
                },
                body: JSON.stringify({
                    message,
                    history: requestHistory,
                    language: responseLanguage
                })
            });
            const rawResponse = await response.text();
            let data;

            try {
                data = rawResponse ? JSON.parse(rawResponse) : {};
            } catch (parseError) {
                throw new Error("Die Antwort konnte gerade nicht verarbeitet werden. Bitte versuche es noch einmal.");
            }

            if (!response.ok) {
                throw new Error(data.message || "Die Antwort konnte nicht geladen werden.");
            }

            hideTypingIndicator(true);
            appendMessage(data.answer, "bot", true);
            history.push({ role: "assistant", content: stripHtml(data.answer) });
            persistHistory();
            renderSources(data.sources || []);
        } catch (error) {
            hideTypingIndicator(false);
            appendMessage(error.message, "bot", false);
            history.push({ role: "assistant", content: error.message });
            persistHistory();
        } finally {
            setLoading(false);
        }
    });

    updateInputCopy();
    setCariState("thinking");

    function togglePanel(open) {
        panelOpen = open && hasAppeared && !isDocked;
        syncWidgetVisibility();
        if (panelOpen) {
            input.focus();
            messages.scrollTop = messages.scrollHeight;
            if (firstPanelOpen) {
                firstPanelOpen = false;
                setCariState("greeting");
            }
            scheduleCariIdleWave();
            return;
        }
        clearCariIdleWaveTimers();
        hideCariQuip();

        hideTypingIndicator(false);
    }

    function dockWidget() {
        isDocked = true;
        panelOpen = false;
        persistDockedState();
        hideTypingIndicator(false);
        syncWidgetVisibility();
    }

    function restoreWidget(openPanel) {
        isDocked = false;
        panelOpen = !!openPanel;
        persistDockedState();
        syncWidgetVisibility();

        if (panelOpen) {
            input.focus();
            messages.scrollTop = messages.scrollHeight;
        }
    }

    function syncWidgetVisibility() {
        launcherShell.hidden = !hasAppeared || isDocked || panelOpen;
        dock.hidden = !hasAppeared || !isDocked;
        panel.hidden = !hasAppeared || isDocked || !panelOpen;
        launcher.setAttribute("aria-expanded", String(panelOpen));
        positionIdleBubble();
    }

    function appendMessage(content, role, isHtml) {
        const wrapper = document.createElement("article");
        wrapper.className = `wpca-message wpca-message--${role}`;
        const bubble = document.createElement("div");
        bubble.className = "wpca-bubble";
        bubble.innerHTML = isHtml ? content : escapeHtml(content);
        wrapper.appendChild(bubble);
        messages.appendChild(wrapper);
        messages.scrollTop = messages.scrollHeight;
    }

    function renderSources(items) {
        const links = items.filter((item) => item && item.url);

        if (!links.length) {
            sources.hidden = true;
            sources.innerHTML = "";
            return;
        }

        sources.hidden = false;
        sources.innerHTML = `
            <ul>
                ${links.map((item) => `<li><a href="${encodeURI(item.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(item.title)}</a></li>`).join("")}
            </ul>
        `;
    }

    function setLoading(isLoading) {
        form.classList.toggle("is-loading", isLoading);
        input.disabled = isLoading;
        form.querySelector("button").disabled = isLoading;
        if (isLoading) {
            hideIdleBubble();
        } else {
            resetIdleCycle();
        }
    }

    function scheduleCariIdleWave() {
        clearCariIdleWaveTimers();
        if (!panelOpen || form.classList.contains("is-loading") || !cariBubble) return;
        cariPanelIdleTimer = setTimeout(() => {
            showCariQuip();
        }, 25000);
    }

    function clearCariIdleWaveTimers() {
        if (cariPanelIdleTimer) { clearTimeout(cariPanelIdleTimer); cariPanelIdleTimer = null; }
        if (cariPanelIdleHideTimer) { clearTimeout(cariPanelIdleHideTimer); cariPanelIdleHideTimer = null; }
    }

    async function showCariQuip() {
        if (!panelOpen || form.classList.contains("is-loading") || !cariBubble) return;
        const pick = await fetchCariQuip();
        if (!pick) {
            scheduleCariIdleWave();
            return;
        }
        setCariState("greeting");
        lastCariQuip = pick;
        cariBubble.textContent = pick;
        cariBubble.classList.add("is-quip");
        cariPanelIdleHideTimer = setTimeout(() => {
            hideCariQuip();
            scheduleCariIdleWave();
        }, getBubbleDisplayDuration(pick));
    }

    async function fetchCariQuip() {
        if (!quipUrl) {
            return fallbackCariQuip();
        }

        const controller = typeof AbortController !== "undefined" ? new AbortController() : null;
        const timeout = controller ? window.setTimeout(() => controller.abort(), 6000) : null;

        try {
            const response = await fetch(quipUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-WP-Nonce": nonce
                },
                signal: controller ? controller.signal : undefined,
                body: JSON.stringify({
                    language: responseLanguage,
                    lastQuip: lastCariQuip
                })
            });
            const rawResponse = await response.text();
            const data = rawResponse ? JSON.parse(rawResponse) : {};

            if (!response.ok || !data.quip) {
                return fallbackCariQuip();
            }

            return String(data.quip).trim();
        } catch (error) {
            return fallbackCariQuip();
        } finally {
            if (timeout) {
                window.clearTimeout(timeout);
            }
        }
    }

    function fallbackCariQuip() {
        const fallbacks = responseLanguage === "en"
            ? ["Need a quick answer? I am right here.", "Small pause, big care.", "Ask me anything about the next step."]
            : ["Brauchst du eine schnelle Antwort? Ich bin da.", "Kleine Pause, grosse Pflege.", "Frag mich gern nach dem naechsten Schritt."];
        const options = fallbacks.filter((entry) => entry !== lastCariQuip);
        const source = options.length ? options : fallbacks;
        return source[Math.floor(Math.random() * source.length)] || "";
    }

    function hideCariQuip() {
        if (!cariBubble) return;
        if (!cariBubble.classList.contains("is-quip")) return;
        cariBubble.classList.remove("is-quip");
        // clear text after fade-out
        setTimeout(() => {
            if (!cariBubble.classList.contains("is-quip")) cariBubble.textContent = "";
        }, 350);
    }

    function setCariState(state) {
        if (!cariTargets.length) return;
        if (cariStateTimer) { clearTimeout(cariStateTimer); cariStateTimer = null; }
        const keepQuipVisible = cariBubble && cariBubble.classList.contains("is-quip");
        cariTargets.forEach((target) => {
            target.classList.remove("is-thinking", "is-happy", "is-greeting");
        });
        if (cariBubble && !keepQuipVisible) {
            cariBubble.classList.remove("is-quip");
            cariBubble.textContent = "";
        }
        if (state && state !== "idle") {
            cariTargets.forEach((target) => {
                target.classList.add("is-" + state);
            });
            if (state === "happy" || state === "greeting") {
                cariStateTimer = setTimeout(() => {
                    setCariState("thinking");
                }, state === "happy" ? 1800 : 2200);
            }
        }
    }

    function showTypingIndicator() {
        clearCariIdleWaveTimers();
        hideCariQuip();
        hideTypingIndicator(false);
        setCariState("thinking");
        typingIndicator = document.createElement("article");
        typingIndicator.className = "wpca-message wpca-message--bot wpca-message--typing";
        typingIndicator.innerHTML = `
            <div class="wpca-bubble wpca-bubble--typing" aria-live="polite" aria-label="Aschke Assist schreibt">
                <span class="wpca-typing">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </div>
        `;
        messages.appendChild(typingIndicator);
        messages.scrollTop = messages.scrollHeight;
    }

    function hideTypingIndicator(playAnswerAnimation = true) {
        if (!typingIndicator) {
            return;
        }

        typingIndicator.remove();
        typingIndicator = null;
        setCariState(playAnswerAnimation ? "happy" : "thinking");
        scheduleCariIdleWave();
    }

    function autoResize() {
        input.style.height = "auto";
        input.style.height = `${Math.min(input.scrollHeight, 140)}px`;
    }

    function markUserActivity() {
        userHasInteracted = true;
        if (autoDockTimer) {
            window.clearTimeout(autoDockTimer);
            autoDockTimer = null;
        }
        hideCariQuip();
        finishFirstVisitIntro(false);
        clearCariIdleWaveTimers();
        hideIdleBubble();
        if (panelOpen) {
            scheduleCariIdleWave();
        } else {
            resetIdleCycle();
        }
    }

    async function showAttentionBubble() {
        if (!panelOpen) {
            hideIdleBubble();
            setCariState("greeting");
            return;
        }

        const message = await fetchCariQuip();
        if (!message) {
            return;
        }

        idleBubble.textContent = message;
        lastIdleMessage = message;
        positionIdleBubble();
        idleBubble.hidden = false;
        idleBubble.classList.add("is-visible");
        syncIdleAvatarState();
        if (idleHideTimer) {
            window.clearTimeout(idleHideTimer);
        }

        idleHideTimer = window.setTimeout(() => {
            hideIdleBubble();
        }, getBubbleDisplayDuration(message));
    }

    function resetIdleCycle() {
        clearIdleTimers();

        if (!hasAppeared || panelOpen || form.classList.contains("is-loading")) {
            return;
        }

        idlePhaseSchedule.forEach((phase) => {
            const delay = randomBetween(phase.min, phase.max);
            const timer = window.setTimeout(() => {
                showIdlePhase(phase.key);
            }, delay);
            idlePhaseTimers.push(timer);
        });
    }

    function clearIdleTimers() {
        idlePhaseTimers.forEach((timer) => window.clearTimeout(timer));
        idlePhaseTimers = [];
        if (idleHideTimer) {
            window.clearTimeout(idleHideTimer);
            idleHideTimer = null;
        }
    }

    async function showIdlePhase(phaseKey) {
        if (!hasAppeared || form.classList.contains("is-loading")) {
            return;
        }

        if (!panelOpen) {
            hideIdleBubble();
            setCariState("greeting");
            return;
        }

        const message = await fetchCariQuip();
        if (!message) {
            return;
        }

        idleBubble.textContent = message;
        lastIdleMessage = message;
        positionIdleBubble();
        idleBubble.hidden = false;
        idleBubble.classList.add("is-visible");
        syncIdleAvatarState();

        if (idleHideTimer) {
            window.clearTimeout(idleHideTimer);
        }

        idleHideTimer = window.setTimeout(() => {
            hideIdleBubble();
        }, getBubbleDisplayDuration(message));
    }

    function getBubbleDisplayDuration(text) {
        const length = String(text || "").trim().length;
        return Math.max(7000, Math.min(18000, 4500 + (length * 95)));
    }

    function hideIdleBubble() {
        idleBubble.hidden = true;
        idleBubble.classList.remove("is-visible");
        syncIdleAvatarState();
    }

    function positionIdleBubble() {
        idleBubble.classList.remove("is-launcher", "is-dock", "is-panel");

        if (!hasAppeared || idleBubble.hidden) {
            return;
        }

        if (panelOpen) {
            idleBubble.classList.add("is-panel");
            return;
        }

        if (isDocked) {
            idleBubble.classList.add("is-dock");
            return;
        }

        idleBubble.classList.add("is-launcher");
    }

    function syncIdleAvatarState() {
        [launcherAvatar, dockAvatar, panelAvatar].forEach((element) => {
            if (!element) {
                return;
            }

            element.classList.remove("is-idle-nudge");
        });

        if (idleBubble.hidden) {
            return;
        }

        if (panelOpen && panelAvatar) {
            setCariState("greeting");
            return;
        }

        if (isDocked && dockAvatar) {
            setCariState("greeting");
            dockAvatar.classList.add("is-idle-nudge");
            return;
        }

        if (launcherAvatar) {
            setCariState("greeting");
            launcherAvatar.classList.add("is-idle-nudge");
        }
    }

    function startFirstVisitIntro() {
        if (!introCari || hasSeenFirstVisitIntro() || panelOpen || isDocked) {
            return;
        }

        markFirstVisitIntroSeen();
        root.classList.add("is-intro-active");
        introCari.hidden = false;
        introCari.classList.remove("is-returning");

        window.setTimeout(() => {
            if (!introCari.hidden) {
                introCari.classList.add("is-returning");
            }
        }, 5500);

        window.setTimeout(() => {
            finishFirstVisitIntro(true);
        }, 7000);
    }

    function finishFirstVisitIntro(showLauncher) {
        if (!introCari || introCari.hidden) {
            return;
        }

        introCari.hidden = true;
        introCari.classList.remove("is-returning");
        root.classList.remove("is-intro-active");
        if (showLauncher) {
            syncWidgetVisibility();
        }
    }

    function hasSeenFirstVisitIntro() {
        try {
            return window.localStorage.getItem(introSeenKey) === "1";
        } catch (error) {
            return false;
        }
    }

    function markFirstVisitIntroSeen() {
        try {
            window.localStorage.setItem(introSeenKey, "1");
        } catch (error) {}
    }

    function getCariAvatarMarkup(variant) {
        const variantClass = `wpca-cari-avatar--${variant}`;
        const slotClass = variant === "launcher"
            ? "wpca-launcher__avatar"
            : (variant === "dock" ? "wpca-dock__avatar" : "wpca-avatar-anim");
        const lidMarkup = cariImageUrl ? `
            <span class="wpca-cari-lid wpca-cari-lid--left-top"></span>
            <span class="wpca-cari-lid wpca-cari-lid--left-bottom"></span>
            <span class="wpca-cari-lid wpca-cari-lid--right-top"></span>
            <span class="wpca-cari-lid wpca-cari-lid--right-bottom"></span>
        ` : "";
        const imageMarkup = cariImageUrl
            ? `<span class="wpca-cari-face"><img class="wpca-cari-avatar__image" src="${escapeAttribute(cariImageUrl)}" alt="">${lidMarkup}</span>`
            : `<span class="wpca-avatar__face">:)</span>`;
        const bubbleMarkup = variant === "panel"
            ? `<span class="wpca-cari-bubble" aria-hidden="true"></span>`
            : "";

        return `
            <span class="${slotClass} wpca-cari-avatar ${variantClass} wpca-cari-motion is-thinking" aria-hidden="true">
                <span class="wpca-cari-img-wrap">${imageMarkup}</span>
                <span class="wpca-cari-effects" aria-hidden="true">
                    <span class="wpca-avatar-heart wpca-avatar-heart--1">&hearts;</span>
                    <span class="wpca-avatar-heart wpca-avatar-heart--2">&hearts;</span>
                    <span class="wpca-avatar-heart wpca-avatar-heart--3">&#10022;</span>
                </span>
                ${bubbleMarkup}
            </span>
        `;
    }

    function buildWelcomeMessage(baseMessage, language) {
        const raw = String(baseMessage || "").trim();
        const greeting = getDaypartGreeting(language);

        if (!raw) {
            return greeting;
        }

        const cleaned = raw.replace(/^(hallo|hi|hey|guten morgen|guten tag|guten abend|good morning|good afternoon|good evening)[\s,!:.()-]*/i, "").trim();
        return cleaned ? `${greeting} ${cleaned}` : greeting;
    }

    function buildCariIntroMessage(language) {
        if (language === "en") {
            return "Hi, I am Cari. Ask me anything when you need help.";
        }

        return "Hi, ich bin Cari. Wenn du Fragen hast, bin ich gleich hier unten.";
    }

    function getDaypartGreeting(language) {
        const hour = new Date().getHours();
        const english = language === "en";

        if (hour < 11) {
            return english ? "Good morning :)" : "Guten Morgen :)";
        }

        if (hour < 18) {
            return english ? "Good afternoon :)" : "Guten Tag :)";
        }

        return english ? "Good evening :)" : "Guten Abend :)";
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function escapeAttribute(value) {
        return escapeHtml(value);
    }

    function stripHtml(value) {
        const temp = document.createElement("div");
        temp.innerHTML = value;
        return (temp.textContent || temp.innerText || "").trim();
    }

    function normalizeLanguage(value) {
        const normalized = String(value || "").toLowerCase();
        if (!normalized || normalized === "auto") {
            return "";
        }
        if (normalized.startsWith("en")) {
            return "en";
        }

        return "de";
    }

    function updateInputCopy() {
        if (responseLanguage === "en") {
            input.placeholder = "Ask a question about our company ...";
            form.querySelector("button").textContent = "Send";
            return;
        }

        input.placeholder = strings.placeholder;
        form.querySelector("button").textContent = strings.send;
    }

    function applyBrandColors(color) {
        const rgb = hexToRgb(color);
        const rootStyle = document.documentElement.style;

        rootStyle.setProperty("--wpca-accent-rgb", `${rgb.r}, ${rgb.g}, ${rgb.b}`);
        rootStyle.setProperty("--wpca-accent-deep", mixWithBlack(rgb, 0.28));
        rootStyle.setProperty("--wpca-accent-soft", mixWithWhite(rgb, 0.84));
        rootStyle.setProperty("--wpca-accent-mist", mixWithWhite(rgb, 0.92));
        rootStyle.setProperty("--wpca-accent-ink", mixWithBlack(rgb, 0.62));
    }

    function hexToRgb(color) {
        const fallback = { r: 243, g: 156, b: 165 };
        const normalized = String(color || "").trim().replace("#", "");

        if (!/^[0-9a-f]{3}([0-9a-f]{3})?$/i.test(normalized)) {
            return fallback;
        }

        const full = normalized.length === 3
            ? normalized.split("").map((chunk) => `${chunk}${chunk}`).join("")
            : normalized;

        return {
            r: parseInt(full.slice(0, 2), 16),
            g: parseInt(full.slice(2, 4), 16),
            b: parseInt(full.slice(4, 6), 16)
        };
    }

    function mixWithWhite(rgb, amount) {
        return rgbToHex({
            r: Math.round(rgb.r + ((255 - rgb.r) * amount)),
            g: Math.round(rgb.g + ((255 - rgb.g) * amount)),
            b: Math.round(rgb.b + ((255 - rgb.b) * amount))
        });
    }

    function mixWithBlack(rgb, amount) {
        return rgbToHex({
            r: Math.round(rgb.r * (1 - amount)),
            g: Math.round(rgb.g * (1 - amount)),
            b: Math.round(rgb.b * (1 - amount))
        });
    }

    function rgbToHex(rgb) {
        return `#${[rgb.r, rgb.g, rgb.b]
            .map((value) => Math.max(0, Math.min(255, value)).toString(16).padStart(2, "0"))
            .join("")}`;
    }

    function randomBetween(min, max) {
        return Math.floor(Math.random() * ((max - min) + 1)) + min;
    }

    function getMedicalMotifsMarkup() {
        return `
            <span class="wpca-motif wpca-motif--cross">${iconCross()}</span>
            <span class="wpca-motif wpca-motif--stethoscope">${iconStethoscope()}</span>
            <span class="wpca-motif wpca-motif--syringe">${iconSyringe()}</span>
        `;
    }

    function iconCross() {
        return `
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M10 4h4v6h6v4h-6v6h-4v-6H4v-4h6z"></path>
            </svg>
        `;
    }

    function iconStethoscope() {
        return `
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M7 4v5a5 5 0 0 0 10 0V4"></path>
                <path d="M9 4v5a3 3 0 0 0 6 0V4"></path>
                <path d="M12 14v2a4 4 0 1 0 8 0v-1"></path>
                <circle cx="20" cy="13" r="2"></circle>
            </svg>
        `;
    }

    function iconSyringe() {
        return `
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M14 4l6 6"></path>
                <path d="M13 5l2-2"></path>
                <path d="M19 11l2-2"></path>
                <path d="M8 10l6 6"></path>
                <path d="M6 12l6 6"></path>
                <path d="M5 13l-1 4 4-1 9-9-3-3z"></path>
            </svg>
        `;
    }

    function persistHistory() {
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(history.slice(-20)));
        } catch (error) {
        }
    }

    function loadStoredHistory() {
        try {
            const raw = window.sessionStorage.getItem(storageKey);
            const items = raw ? JSON.parse(raw) : [];
            if (!Array.isArray(items)) {
                return [];
            }

            return items
                .filter((item) => item && (item.role === "user" || item.role === "assistant") && item.content)
                .slice(-20)
                .map((item) => ({
                    role: item.role,
                    content: String(item.content)
                }));
        } catch (error) {
            return [];
        }
    }

    function persistDockedState() {
        try {
            window.localStorage.setItem(dockedStateKey, isDocked ? "1" : "0");
        } catch (error) {
        }
    }

    function loadDockedState() {
        try {
            return window.localStorage.getItem(dockedStateKey) === "1";
        } catch (error) {
            return false;
        }
    }
})();
