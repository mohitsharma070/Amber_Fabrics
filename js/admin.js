(function () {
    "use strict";

    function eventElement(event) {
        return event.target instanceof Element ? event.target : null;
    }

    function initNavigation() {
        var nav = document.getElementById("adminNav");
        var Collapse = window.bootstrap && window.bootstrap.Collapse;
        if (!nav || !Collapse) return;
        var toggler = document.querySelector('.admin-nav-toggler[data-bs-target="#adminNav"]');
        var collapse = Collapse.getOrCreateInstance(nav, { toggle: false });
        var mobileQuery = window.matchMedia("(max-width: 991.98px)");

        function closeOnMobile() {
            if (mobileQuery.matches && nav.classList.contains("show")) collapse.hide();
        }

        function syncExpanded(expanded) {
            if (toggler) toggler.setAttribute("aria-expanded", expanded ? "true" : "false");
        }

        nav.addEventListener("click", function (event) {
            var target = eventElement(event);
            var link = target ? target.closest("a.nav-link, a.dropdown-item") : null;
            if (!link || link.classList.contains("dropdown-toggle") || link.getAttribute("data-bs-toggle") === "dropdown") return;
            closeOnMobile();
        });
        nav.addEventListener("shown.bs.collapse", function () { syncExpanded(true); });
        nav.addEventListener("hidden.bs.collapse", function () { syncExpanded(false); });
        window.addEventListener("scroll", closeOnMobile, { passive: true });
        window.addEventListener("touchmove", closeOnMobile, { passive: true });
        mobileQuery.addEventListener("change", function () {
            if (!mobileQuery.matches) syncExpanded(nav.classList.contains("show"));
        });
        syncExpanded(nav.classList.contains("show"));
    }

    function enhanceTable(table) {
        if (table.dataset.adminTableReady === "true" || table.classList.contains("admin-no-card-table")) return;
        table.dataset.adminTableReady = "true";
        table.classList.add("admin-card-table");
        var labels = Array.from(table.querySelectorAll("thead th"), function (heading) {
            return (heading.textContent || "").replace(/\s+/g, " ").trim();
        });

        table.querySelectorAll("tbody tr").forEach(function (row) {
            var cells = row.querySelectorAll("td");
            if (cells.length === 1 && cells[0].hasAttribute("colspan")) {
                row.classList.add("admin-empty-row");
                return;
            }
            cells.forEach(function (cell, index) {
                if (cell.hasAttribute("data-label")) return;
                cell.setAttribute("data-label", labels[index] || (cell.classList.contains("text-end") ? "Actions" : "Field"));
            });
        });
    }

    function init() {
        if (!document.body.classList.contains("admin-shell")) return;
        initNavigation();
        document.querySelectorAll(".table-responsive > table.table").forEach(enhanceTable);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init, { once: true });
    } else {
        init();
    }
}());
