/* Bandejas clínicas: filtro y agrupación por fecha de examen (start_time). */
(function (global) {
    const STORAGE_KEY = "ris_workflow_exam_date_filter_v1";

    function pad2(n) {
        return String(n).padStart(2, "0");
    }

    function toDateKey(date) {
        return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
    }

    function risTodayDateKey() {
        return toDateKey(new Date());
    }

    function risTomorrowDateKey() {
        const d = new Date();
        d.setDate(d.getDate() + 1);
        return toDateKey(d);
    }

    function loadFilterState(moduleKey) {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            const all = raw ? JSON.parse(raw) : {};
            const saved = all[moduleKey];
            if (saved && (saved.mode === "all" || saved.mode === "day")) {
                return saved;
            }
        } catch (e) {
            /* ignore */
        }
        return { mode: "today", date: risTodayDateKey() };
    }

    function saveFilterState(moduleKey, state) {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            const all = raw ? JSON.parse(raw) : {};
            all[moduleKey] = state;
            localStorage.setItem(STORAGE_KEY, JSON.stringify(all));
        } catch (e) {
            /* ignore */
        }
    }

    function risFormatWorkflowExamDateLabel(dateKey) {
        if (!dateKey || dateKey === "sin-fecha") {
            return "Sin fecha de examen";
        }
        const today = risTodayDateKey();
        const tomorrow = risTomorrowDateKey();
        if (dateKey === today) {
            return "Hoy";
        }
        if (dateKey === tomorrow) {
            return "Mañana";
        }
        const yesterday = toDateKey(new Date(Date.now() - 86400000));
        if (dateKey === yesterday) {
            return "Ayer";
        }
        const parts = dateKey.split("-");
        if (parts.length === 3) {
            const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
            return d.toLocaleDateString("es-CL", {
                weekday: "short",
                day: "2-digit",
                month: "short",
                year: "numeric",
            });
        }
        return dateKey;
    }

    function risFormatWorkflowExamTime(examDateTime, examTime) {
        if (examTime) {
            return examTime;
        }
        if (!examDateTime) {
            return "";
        }
        const m = String(examDateTime).match(/T(\d{2}:\d{2})/);
        return m ? m[1] : "";
    }

    function risWorkflowExamDateQueryParam(state) {
        if (!state || state.mode === "all") {
            return "";
        }
        const date = state.date || risTodayDateKey();
        return `date=${encodeURIComponent(date)}`;
    }

    function risGroupInboxByExamDate(items) {
        const map = new Map();
        (items || []).forEach((app) => {
            const key = app.examDate || "sin-fecha";
            if (!map.has(key)) {
                map.set(key, []);
            }
            map.get(key).push(app);
        });

        const keys = Array.from(map.keys()).sort((a, b) => {
            if (a === "sin-fecha") {
                return 1;
            }
            if (b === "sin-fecha") {
                return -1;
            }
            return a.localeCompare(b);
        });

        return keys.map((dateKey) => ({
            dateKey,
            label: risFormatWorkflowExamDateLabel(dateKey),
            items: map.get(dateKey),
        }));
    }

    function syncWorkflowDateFilterUi($root, state) {
        const $input = $root.find("[data-ris-exam-date-input]");
        const $all = $root.find("[data-ris-exam-date-preset='all']");
        const $today = $root.find("[data-ris-exam-date-preset='today']");
        const $tomorrow = $root.find("[data-ris-exam-date-preset='tomorrow']");

        $all.removeClass("active");
        $today.removeClass("active");
        $tomorrow.removeClass("active");

        if (state.mode === "all") {
            $all.addClass("active");
            $input.val("");
            return;
        }

        const date = state.date || risTodayDateKey();
        $input.val(date);
        if (date === risTodayDateKey()) {
            $today.addClass("active");
        } else if (date === risTomorrowDateKey()) {
            $tomorrow.addClass("active");
        }
    }

    function risInitWorkflowExamDateFilter(options) {
        const moduleKey = options.moduleKey || "default";
        const $root = $(options.rootSelector || "[data-ris-exam-date-filter]");
        const onChange = typeof options.onChange === "function" ? options.onChange : function () {};

        let state = loadFilterState(moduleKey);
        if (options.defaultMode === "all" && !localStorage.getItem(STORAGE_KEY)) {
            state = { mode: "all", date: "" };
        }

        function commit(next) {
            state = next;
            saveFilterState(moduleKey, state);
            syncWorkflowDateFilterUi($root, state);
            onChange(state);
        }

        syncWorkflowDateFilterUi($root, state);

        $root.off("click.risExamDate").on("click.risExamDate", "[data-ris-exam-date-preset]", function () {
            const preset = $(this).data("ris-exam-date-preset");
            if (preset === "all") {
                commit({ mode: "all", date: "" });
                return;
            }
            if (preset === "today") {
                commit({ mode: "day", date: risTodayDateKey() });
                return;
            }
            if (preset === "tomorrow") {
                commit({ mode: "day", date: risTomorrowDateKey() });
            }
        });

        $root.off("change.risExamDate").on("change.risExamDate", "[data-ris-exam-date-input]", function () {
            const val = String($(this).val() || "").trim();
            if (!val) {
                commit({ mode: "all", date: "" });
                return;
            }
            commit({ mode: "day", date: val });
        });

        return {
            getState: () => ({ ...state }),
            setState: commit,
        };
    }

    function risRenderWorkflowInboxGrouped($container, items, renderItemFn, emptyHtml) {
        $container.empty();
        if (!items || items.length === 0) {
            $container.append(emptyHtml);
            return;
        }

        const groups = risGroupInboxByExamDate(items);
        groups.forEach((group) => {
            $container.append(
                `<div class="list-group-item bg-body-secondary py-1 px-3 small fw-bold text-secondary border-0 sticky-top" style="top:0;z-index:2;">` +
                    `<i class="bi bi-calendar3 me-1"></i>${group.label}` +
                    ` <span class="badge bg-secondary ms-1">${group.items.length}</span>` +
                `</div>`
            );
            group.items.forEach((app) => {
                $container.append(renderItemFn(app));
            });
        });
    }

    function risWorkflowExamDateBadgeHtml(app) {
        const label = risFormatWorkflowExamDateLabel(app.examDate);
        const time = risFormatWorkflowExamTime(app.examDateTime, app.examTime);
        const text = time ? `${label} · ${time}` : label;
        return `<span class="badge bg-dark-subtle text-dark border font-monospace" style="font-size:0.7rem;">` +
            `<i class="bi bi-calendar-event me-1"></i>${typeof risEscapeHtml === "function" ? risEscapeHtml(text) : text}</span>`;
    }

    global.risInitWorkflowExamDateFilter = risInitWorkflowExamDateFilter;
    global.risWorkflowExamDateQueryParam = risWorkflowExamDateQueryParam;
    global.risRenderWorkflowInboxGrouped = risRenderWorkflowInboxGrouped;
    global.risWorkflowExamDateBadgeHtml = risWorkflowExamDateBadgeHtml;
    global.risFormatWorkflowExamDateLabel = risFormatWorkflowExamDateLabel;
})(window);
