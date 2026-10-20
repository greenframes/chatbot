document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".wpca-card").forEach(function (card, index) {
        card.style.animationDelay = (index * 80) + "ms";
        card.classList.add("wpca-card--visible");
    });

    const collapsibleTitles = [
        "Eigene FAQ-Antworten anlegen",
        "FAQ bearbeiten",
        "Lernansicht",
        "Admin-Testmodus"
    ];

    document.querySelectorAll(".wpca-card--full").forEach(function (card) {
        const heading = card.querySelector("h2");
        if (!heading) {
            return;
        }

        const title = (heading.textContent || "").trim();
        if (!collapsibleTitles.some(function (entry) { return title.indexOf(entry) === 0; })) {
            return;
        }

        const storageKey = "wpca-section-" + title.toLowerCase().replace(/[^a-z0-9]+/g, "-");
        const headingRow = document.createElement("div");
        headingRow.className = "wpca-card__heading";
        heading.parentNode.insertBefore(headingRow, heading);
        headingRow.appendChild(heading);

        const toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "button wpca-card__toggle";
        headingRow.appendChild(toggle);

        const body = document.createElement("div");
        body.className = "wpca-card__body";

        while (headingRow.nextSibling) {
            body.appendChild(headingRow.nextSibling);
        }

        card.appendChild(body);

        const isCollapsed = window.localStorage.getItem(storageKey) === "collapsed";
        updateCollapsedState(card, toggle, isCollapsed);

        toggle.addEventListener("click", function () {
            const nextState = !card.classList.contains("is-collapsed");
            updateCollapsedState(card, toggle, nextState);
            window.localStorage.setItem(storageKey, nextState ? "collapsed" : "open");
        });
    });

    const selectAllFaqs = document.querySelector(".wpca-select-all-faqs");
    if (selectAllFaqs) {
        selectAllFaqs.addEventListener("change", function () {
            document.querySelectorAll('input[name="faq_ids[]"]').forEach(function (checkbox) {
                checkbox.checked = selectAllFaqs.checked;
            });
        });
    }

    const faqSearchInput = document.querySelector("#wpca-faq-search");
    const faqEmptyState = document.querySelector(".wpca-faq-search__empty");
    if (faqSearchInput) {
        const faqRows = Array.from(document.querySelectorAll(".wpca-table--faqs tbody tr"));

        faqSearchInput.addEventListener("input", function () {
            const query = normalizeFaqSearch(faqSearchInput.value);
            let visibleCount = 0;

            faqRows.forEach(function (row) {
                const haystack = normalizeFaqSearch(row.getAttribute("data-wpca-faq-search") || "");
                const matches = !query || haystack.indexOf(query) !== -1;
                row.hidden = !matches;
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (faqEmptyState) {
                faqEmptyState.hidden = visibleCount !== 0;
            }
        });
    }

    const uploadButton = document.querySelector(".wpca-avatar-upload");
    const removeButton = document.querySelector(".wpca-avatar-remove");
    const input = document.querySelector(".wpca-avatar-url");
    const preview = document.querySelector(".wpca-avatar-preview");

    if (uploadButton && input && preview) {
        let frame = null;

        if (typeof wp !== "undefined" && wp.media) {
            uploadButton.addEventListener("click", function () {
                if (!frame) {
                    frame = wp.media({
                        title: "Avatar-Bild auswaehlen",
                        button: {
                            text: "Dieses Bild verwenden"
                        },
                        multiple: false,
                        library: {
                            type: "image"
                        }
                    });

                    frame.on("select", function () {
                        const attachment = frame.state().get("selection").first().toJSON();
                        input.value = attachment.url || "";
                        preview.src = attachment.url || "";
                        preview.hidden = !attachment.url;
                        preview.classList.toggle("is-empty", !attachment.url);
                        if (removeButton) {
                            removeButton.hidden = !attachment.url;
                        }
                    });
                }

                frame.open();
            });
        }

        if (removeButton) {
            removeButton.addEventListener("click", function () {
                input.value = "";
                preview.src = "";
                preview.hidden = true;
                preview.classList.add("is-empty");
                removeButton.hidden = true;
            });
        }

        input.addEventListener("input", function () {
            const value = input.value.trim();
            preview.src = value;
            preview.hidden = !value;
            preview.classList.toggle("is-empty", !value);
            if (removeButton) {
                removeButton.hidden = !value;
            }
        });
    }

    function updateCollapsedState(card, toggle, collapsed) {
        card.classList.toggle("is-collapsed", collapsed);
        toggle.textContent = collapsed ? "Bereich aufklappen" : "Bereich einklappen";
        toggle.setAttribute("aria-expanded", String(!collapsed));
    }

    function normalizeFaqSearch(value) {
        return String(value || "")
            .toLowerCase()
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "");
    }
});
