(function () {
    "use strict";

    if (!PhoneTrackerAuth.requireRole(["security", "admin"])) {
        return;
    }

    const dashboardState = {
        visitors: [],
        status: "",
        type: "",
        query: "",
        page: 1,
        pageSize: 15,
    };
    const monitoringState = {
        map: null,
        markers: new Map(),
        routeLine: null,
        routeStart: null,
        routeEnd: null,
        liveVisits: [],
        routeVisits: [],
        fittedLiveMarkers: false,
        initialized: false,
    };

    const visitorsBody = document.getElementById("securityVisitorsBody");
    const visitorsEmpty = document.getElementById("securityVisitorsEmpty");
    const profileButton = document.getElementById("securityProfileBtn");
    const profileMenu = document.getElementById("securityProfileMenu");
    const visitorSelect = document.getElementById("monitorVisitorSelect");
    const dateSelect = document.getElementById("monitorDateSelect");
    const showRouteButton = document.getElementById("showRouteBtn");

    function setText(id, value) {
        document.getElementById(id).textContent = String(value == null ? "" : value);
    }

    function showMessage(id, message) {
        const element = document.getElementById(id);
        element.textContent = message || "";
        element.hidden = !message;
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, Object.assign({ credentials: "same-origin" }, options || {}));
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error("The server returned an invalid response.");
        }
        if (response.status === 401 || (data && data.message === "Not authenticated")) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        if (response.status === 403) {
            throw new Error("This account does not have security access.");
        }
        return data;
    }

    function parseServerDate(value) {
        if (!value) {
            return null;
        }
        const date = new Date(String(value).replace(" ", "T"));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatTime(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "—";
        }
        return new Intl.DateTimeFormat(undefined, {
            hour: "numeric",
            minute: "2-digit",
        }).format(date);
    }

    function formatLocationAge(seconds) {
        const value = Number(seconds);
        if (!Number.isFinite(value) || value < 0) {
            return "update time unavailable";
        }
        if (value < 10) {
            return "just now";
        }
        if (value < 60) {
            return Math.floor(value) + " seconds ago";
        }
        if (value < 3600) {
            const minutes = Math.floor(value / 60);
            return minutes + " minute" + (minutes === 1 ? "" : "s") + " ago";
        }
        const hours = Math.floor(value / 3600);
        return hours + " hour" + (hours === 1 ? "" : "s") + " ago";
    }

    function locationStateMeta(state) {
        const states = {
            live: { label: "Live", color: "#0a9b55" },
            stale: { label: "Stale", color: "#e08a12" },
            offline: { label: "Offline", color: "#687382" },
            waiting: { label: "Waiting for GPS", color: "#0755b5" },
        };
        return states[state] || states.waiting;
    }

    function statusMeta(status, isInsideCampus) {
        const map = {
            checked_in: isInsideCampus
                ? { label: "Active — inside", className: "is-active" }
                : { label: "Inactive", className: "is-completed" },
            pending_approval: { label: "Pending approval", className: "is-pending" },
            approved: { label: "Approved", className: "is-active" },
            rejected: { label: "Declined", className: "is-cancelled" },
            unanswered: { label: "Office did not respond", className: "is-cancelled" },
            reschedule_proposed: { label: "Reschedule proposed", className: "is-pending" },
            window_closed: { label: "Appointment done", className: "is-completed" },
            completed: { label: "Inactive", className: "is-completed" },
            cancelled: { label: "Cancelled", className: "is-cancelled" },
        };
        return map[status] || { label: status || "Unknown", className: "is-completed" };
    }

    function appendCell(row, value, className) {
        const cell = document.createElement("td");
        cell.textContent = value == null || value === "" ? "—" : String(value);
        if (className) {
            cell.className = className;
        }
        row.appendChild(cell);
        return cell;
    }

    function switchSecurityView(view) {
        document.querySelectorAll("[data-security-panel]").forEach(function (panel) {
            panel.hidden = panel.getAttribute("data-security-panel") !== view;
        });
        document.querySelectorAll("[data-security-view]").forEach(function (button) {
            const active = button.getAttribute("data-security-view") === view;
            button.classList.toggle("is-active", active);
            if (active) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        window.history.replaceState(null, "", view === "monitoring" ? "#monitoring" : window.location.pathname);
        if (view === "monitoring") {
            initializeMap();
            window.setTimeout(function () {
                if (monitoringState.map) {
                    monitoringState.map.invalidateSize();
                }
            }, 80);
            loadLiveLocations();
        }
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function filteredVisitors() {
        const query = dashboardState.query.toLowerCase();
        const today = new Date();
        const todayKey = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, "0"), String(today.getDate()).padStart(2, "0")].join("-");
        return dashboardState.visitors.filter(function (visitor) {
            if (dashboardState.status && visitor.status !== dashboardState.status) {
                return false;
            }
            if (dashboardState.type) {
                const type = String(visitor.visit_type || "Appointment").toLowerCase().replace(/_/g, "-");
                const wantsWalkIn = dashboardState.type === "walk-in";
                const matchesType = wantsWalkIn ? type === "walk-in" : type !== "walk-in";
                const relevantDate = String(wantsWalkIn ? (visitor.checked_in_at || visitor.appointment_at || "") : (visitor.appointment_at || "")).slice(0, 10);
                if (!matchesType || relevantDate !== todayKey) {
                    return false;
                }
            }
            const haystack = [
                visitor.registration_id,
                visitor.visitor_full_name,
                visitor.status,
                visitor.purpose,
                visitor.office_label,
                visitor.office_code,
                visitor.subject,
                visitor.visit_type,
            ].join(" ").toLowerCase();
            return !query || haystack.indexOf(query) !== -1;
        });
    }

    function renderVisitorPages(totalPages) {
        const host = document.getElementById("securityPageButtons");
        host.replaceChildren();
        if (totalPages <= 1) {
            return;
        }
        for (let page = 1; page <= totalPages; page += 1) {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = String(page);
            button.classList.toggle("is-active", page === dashboardState.page);
            button.setAttribute("aria-label", "Page " + page);
            if (page === dashboardState.page) {
                button.setAttribute("aria-current", "page");
            }
            button.addEventListener("click", function () {
                dashboardState.page = page;
                renderVisitors();
            });
            host.appendChild(button);
        }
    }

    function createRowAction(label, className, visitor) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = className;
        button.textContent = label;
        button.dataset.appointmentId = String(visitor.id);
        return button;
    }

    function renderVisitors() {
        const visitors = filteredVisitors();
        const totalPages = Math.max(1, Math.ceil(visitors.length / dashboardState.pageSize));
        if (dashboardState.page > totalPages) {
            dashboardState.page = totalPages;
        }
        const start = (dashboardState.page - 1) * dashboardState.pageSize;
        const pageVisitors = visitors.slice(start, start + dashboardState.pageSize);
        visitorsBody.replaceChildren();

        pageVisitors.forEach(function (visitor) {
            const row = document.createElement("tr");
            const status = statusMeta(visitor.status, Boolean(visitor.is_inside_campus));
            appendCell(row, visitor.registration_id, "security-registration-cell");
            appendCell(row, visitor.visitor_full_name || "Visitor", "admin-cell-strong");

            const statusCell = document.createElement("td");
            const statusBadge = document.createElement("span");
            statusBadge.className = "admin-status " + status.className;
            statusBadge.textContent = status.label;
            statusCell.appendChild(statusBadge);
            row.appendChild(statusCell);

            appendCell(row, visitor.purpose || "—");
            appendCell(row, visitor.office_label || visitor.office_code || "—");
            appendCell(row, visitor.subject || "—");
            appendCell(row, formatTime(visitor.checked_in_at));
            appendCell(
                row,
                visitor.is_inside_campus ? "Still inside" : formatTime(visitor.completed_at || visitor.cancelled_at),
                visitor.is_inside_campus ? "security-active-time" : "",
            );

            const actions = document.createElement("td");
            actions.className = "security-row-actions";
            if (visitor.is_inside_campus) {
                const monitor = createRowAction("Monitor", "security-monitor-button", visitor);
                monitor.dataset.action = "monitor";
                const end = createRowAction("End visit", "security-end-button", visitor);
                end.dataset.action = "complete";
                actions.append(monitor, end);
            } else if (visitor.status === "completed") {
                const route = createRowAction("View route", "security-monitor-button", visitor);
                route.dataset.action = "monitor";
                actions.append(route);
            }
            row.appendChild(actions);
            visitorsBody.appendChild(row);
        });

        visitorsEmpty.hidden = visitors.length > 0;
        const wrap = visitorsBody.closest(".admin-table-wrap");
        if (wrap) {
            wrap.hidden = visitors.length === 0;
        }
        if (visitors.length === 0) {
            setText("securityVisitorsShowing", "Showing 0 visitors");
        } else {
            setText("securityVisitorsShowing", "Showing " + (start + 1) + "–" + (start + pageVisitors.length) + " of " + visitors.length + " visitors");
        }
        renderVisitorPages(totalPages);
    }

    async function loadSecurityDashboard() {
        showMessage("securityDashboardError", "");
        try {
            const data = await fetchJson("security_dashboard.php");
            if (!data || !data.success) {
                showMessage("securityDashboardError", (data && data.message) || "Could not load visitor records.");
                return;
            }
            const summary = data.summary || {};
            setText("insideCampusCount", summary.inside_campus || 0);
            setText("walkInTodayCount", summary.walk_in_today || 0);
            setText("appointmentTodayCount", summary.appointment_today || 0);
            setText("checkedOutTodayCount", summary.checked_out_today || 0);
            dashboardState.visitors = Array.isArray(data.visitors) ? data.visitors : [];
            renderVisitors();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("securityDashboardError", error.message || "Could not reach the server.");
            }
        }
    }

    function initializeMap() {
        if (monitoringState.initialized) {
            return;
        }
        monitoringState.initialized = true;
        if (typeof L === "undefined") {
            showMessage("securityMapError", "The map library could not load. Check your internet connection and refresh the page.");
            return;
        }
        monitoringState.map = L.map("securityMap", {
            zoomControl: true,
        }).setView([10.7177, 122.5559], 17);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "&copy; OpenStreetMap contributors",
        }).addTo(monitoringState.map);
    }

    function clearRoute() {
        if (!monitoringState.map) {
            return;
        }
        ["routeLine", "routeStart", "routeEnd"].forEach(function (key) {
            if (monitoringState[key]) {
                monitoringState.map.removeLayer(monitoringState[key]);
                monitoringState[key] = null;
            }
        });
    }

    function makeVisitorPopup(visit) {
        const content = document.createElement("div");
        content.className = "security-map-popup";
        const title = document.createElement("strong");
        title.textContent = visit.visitor_full_name || "Visitor";
        const destination = document.createElement("span");
        destination.textContent = visit.office_label || visit.office_code || "No destination";
        const updated = document.createElement("small");
        const state = locationStateMeta(visit.location_state);
        updated.textContent = visit.recorded_at
            ? state.label + " • updated " + formatLocationAge(visit.seconds_since_update)
            : "Waiting for the first GPS update";
        const accuracy = document.createElement("small");
        accuracy.textContent = visit.accuracy == null
            ? "Accuracy unavailable"
            : "Accuracy ±" + Math.round(Number(visit.accuracy)) + " m";
        content.append(title, destination, updated, accuracy);
        return content;
    }

    function updateVisitorOptions(visits) {
        const previous = visitorSelect.value;
        const defaultOption = document.createElement("option");
        defaultOption.value = "";
        defaultOption.textContent = "Select a visitor";
        visitorSelect.replaceChildren(defaultOption);
        visits.forEach(function (visit) {
            const option = document.createElement("option");
            option.value = String(visit.appointment_id);
            option.textContent = (visit.visitor_full_name || visit.device_name || "Visitor")
                + (visit.status === "completed" ? " — Completed" : " — Active");
            visitorSelect.appendChild(option);
        });
        if (visits.some(function (visit) { return String(visit.appointment_id) === previous; })) {
            visitorSelect.value = previous;
        }
    }

    function updateMapStatus(visits) {
        const located = visits.filter(function (visit) { return visit.has_location; });
        const waiting = visits.length - located.length;
        if (visits.length === 0) {
            setText("mapStatusTitle", "Waiting for active visitors");
            setText("mapStatusText", "Checked-in visitors will appear here automatically.");
            return;
        }
        const live = located.filter(function (visit) { return visit.location_state === "live"; }).length;
        const stale = located.filter(function (visit) { return visit.location_state === "stale"; }).length;
        const offline = located.filter(function (visit) { return visit.location_state === "offline"; }).length;
        setText("mapStatusTitle", visits.length + " active visitor" + (visits.length === 1 ? "" : "s"));
        setText("mapStatusText", live + " live • " + stale + " stale • " + offline + " offline"
            + (waiting > 0 ? " • " + waiting + " waiting for GPS" : ""));
    }

    async function loadLiveLocations() {
        if (!monitoringState.initialized || !monitoringState.map) {
            return;
        }
        try {
            const data = await fetchJson("security_live_locations.php");
            if (!data || !data.success || !Array.isArray(data.data)) {
                showMessage("securityMapError", (data && data.message) || "Could not load live locations.");
                return;
            }
            showMessage("securityMapError", "");
            monitoringState.liveVisits = data.data;
            monitoringState.routeVisits = Array.isArray(data.route_visits) ? data.route_visits : data.data;
            updateVisitorOptions(monitoringState.routeVisits);
            updateMapStatus(data.data);

            const activeMarkerIds = new Set();
            const visiblePoints = [];
            data.data.forEach(function (visit) {
                const key = String(visit.appointment_id);
                if (!visit.has_location) {
                    return;
                }
                activeMarkerIds.add(key);
                const point = [Number(visit.latitude), Number(visit.longitude)];
                visiblePoints.push(point);
                const markerState = locationStateMeta(visit.location_state);
                let marker = monitoringState.markers.get(key);
                if (!marker) {
                    marker = L.circleMarker(point, {
                        radius: 9,
                        color: "#ffffff",
                        weight: 3,
                        fillColor: markerState.color,
                        fillOpacity: 1,
                    }).addTo(monitoringState.map);
                    monitoringState.markers.set(key, marker);
                } else {
                    marker.setLatLng(point);
                    marker.setStyle({ fillColor: markerState.color });
                }
                marker.bindPopup(makeVisitorPopup(visit));
                const tooltip = document.createElement("span");
                tooltip.textContent = visit.visitor_full_name || "Visitor";
                marker.bindTooltip(tooltip, {
                    direction: "top",
                    offset: [0, -8],
                });
            });

            monitoringState.markers.forEach(function (marker, key) {
                if (!activeMarkerIds.has(key)) {
                    monitoringState.map.removeLayer(marker);
                    monitoringState.markers.delete(key);
                }
            });

            if (!monitoringState.fittedLiveMarkers && visiblePoints.length > 0) {
                monitoringState.map.fitBounds(visiblePoints, { padding: [70, 70], maxZoom: 18 });
                monitoringState.fittedLiveMarkers = true;
            }
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("securityMapError", error.message || "Could not update visitor locations.");
            }
        }
    }

    function selectedLiveVisit() {
        return monitoringState.routeVisits.find(function (visit) {
            return String(visit.appointment_id) === visitorSelect.value;
        }) || null;
    }

    async function loadVisitorDates() {
        const visit = selectedLiveVisit();
        dateSelect.disabled = true;
        showRouteButton.disabled = true;
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = visit ? "Loading dates…" : "Select a visitor first";
        dateSelect.replaceChildren(placeholder);
        clearRoute();

        if (!visit) {
            if (monitoringState.map && monitoringState.markers.size > 0) {
                const points = Array.from(monitoringState.markers.values()).map(function (marker) { return marker.getLatLng(); });
                monitoringState.map.fitBounds(points, { padding: [70, 70], maxZoom: 18 });
            }
            return;
        }
        const marker = monitoringState.markers.get(String(visit.appointment_id));
        if (marker) {
            monitoringState.map.setView(marker.getLatLng(), 18);
            marker.openPopup();
        }
        try {
            const data = await fetchJson("security_route_dates.php?appointment_id=" + encodeURIComponent(visit.appointment_id));
            dateSelect.replaceChildren();
            if (!data || !data.success || !Array.isArray(data.data) || data.data.length === 0) {
                const empty = document.createElement("option");
                empty.value = "";
                empty.textContent = "No GPS route recorded";
                dateSelect.appendChild(empty);
                return;
            }
            data.data.forEach(function (item) {
                const option = document.createElement("option");
                option.value = item.date;
                option.textContent = item.date + " — " + item.point_count + " point" + (item.point_count === 1 ? "" : "s");
                dateSelect.appendChild(option);
            });
            dateSelect.disabled = false;
            showRouteButton.disabled = false;
        } catch (error) {
            showMessage("securityMapError", error.message || "Could not load route dates.");
        }
    }

    async function showSelectedRoute() {
        const visit = selectedLiveVisit();
        const date = dateSelect.value;
        if (!visit || !date || !monitoringState.map) {
            return;
        }
        showRouteButton.disabled = true;
        try {
            const params = new URLSearchParams({
                appointment_id: String(visit.appointment_id),
                date: date,
            });
            const data = await fetchJson("security_route_points.php?" + params.toString());
            if (!data || !data.success || !Array.isArray(data.data) || data.data.length === 0) {
                showMessage("securityMapError", (data && data.message) || "No route data was found for this date.");
                return;
            }
            showMessage("securityMapError", "");
            clearRoute();
            const points = data.data.map(function (point) {
                return [Number(point.latitude), Number(point.longitude)];
            });
            monitoringState.routeLine = L.polyline(points, {
                color: "#f0641c",
                weight: 5,
                opacity: 0.9,
            }).addTo(monitoringState.map);
            monitoringState.routeStart = L.circleMarker(points[0], {
                radius: 6,
                color: "#ffffff",
                weight: 2,
                fillColor: "#008c3b",
                fillOpacity: 1,
            }).addTo(monitoringState.map).bindTooltip("Route start");
            monitoringState.routeEnd = L.circleMarker(points[points.length - 1], {
                radius: 6,
                color: "#ffffff",
                weight: 2,
                fillColor: "#e22828",
                fillOpacity: 1,
            }).addTo(monitoringState.map).bindTooltip("Latest point");
            monitoringState.map.fitBounds(monitoringState.routeLine.getBounds(), { padding: [70, 70], maxZoom: 18 });
            setText("mapStatusTitle", (visit.visitor_full_name || "Visitor") + " route");
            const accuracies = data.data.map(function (point) { return Number(point.accuracy); }).filter(Number.isFinite);
            const averageAccuracy = accuracies.length
                ? Math.round(accuracies.reduce(function (sum, accuracy) { return sum + accuracy; }, 0) / accuracies.length)
                : null;
            setText("mapStatusText", points.length + " GPS point" + (points.length === 1 ? "" : "s") + " recorded on " + date
                + (averageAccuracy === null ? "." : " • average accuracy ±" + averageAccuracy + " m."));
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("securityMapError", error.message || "Could not load the route.");
            }
        } finally {
            showRouteButton.disabled = false;
        }
    }

    async function completeVisit(visitor, button) {
        if (!window.confirm("End the active visit for " + (visitor.visitor_full_name || "this visitor") + "?")) {
            return;
        }
        button.disabled = true;
        try {
            const data = await fetchJson("complete_appointment.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id: visitor.id }),
            });
            if (!data || !data.success) {
                window.alert((data && data.message) || "Could not end the visit.");
                button.disabled = false;
                return;
            }
            await Promise.all([loadSecurityDashboard(), loadLiveLocations()]);
        } catch (error) {
            if (error.message !== "Not authenticated") {
                window.alert(error.message || "Could not reach the server.");
                button.disabled = false;
            }
        }
    }

    document.querySelectorAll("[data-security-view]").forEach(function (button) {
        button.addEventListener("click", function () {
            switchSecurityView(button.getAttribute("data-security-view"));
        });
    });

    document.querySelectorAll("[data-security-filter]").forEach(function (card) {
        card.addEventListener("click", function () {
            dashboardState.status = card.getAttribute("data-security-filter") || "";
            dashboardState.type = "";
            dashboardState.page = 1;
            document.getElementById("securityStatusFilter").value = dashboardState.status;
            renderVisitors();
        });
    });

    document.querySelectorAll("[data-security-type-filter]").forEach(function (card) {
        card.addEventListener("click", function () {
            dashboardState.type = card.getAttribute("data-security-type-filter") || "";
            dashboardState.status = "";
            dashboardState.page = 1;
            document.getElementById("securityStatusFilter").value = "";
            renderVisitors();
        });
    });

    document.getElementById("securityVisitorSearch").addEventListener("input", function (event) {
        dashboardState.query = event.target.value.trim();
        dashboardState.page = 1;
        renderVisitors();
    });
    document.getElementById("securityStatusFilter").addEventListener("change", function (event) {
        dashboardState.status = event.target.value;
        dashboardState.type = "";
        dashboardState.page = 1;
        renderVisitors();
    });
    document.getElementById("securityRefreshBtn").addEventListener("click", loadSecurityDashboard);

    visitorsBody.addEventListener("click", function (event) {
        const button = event.target.closest("button[data-appointment-id]");
        if (!button) {
            return;
        }
        const visitor = dashboardState.visitors.find(function (item) {
            return Number(item.id) === Number(button.dataset.appointmentId);
        });
        if (!visitor) {
            return;
        }
        if (button.dataset.action === "complete") {
            completeVisit(visitor, button);
            return;
        }
        if (button.dataset.action === "monitor") {
            switchSecurityView("monitoring");
            loadLiveLocations().then(function () {
                const live = monitoringState.routeVisits.find(function (item) {
                    return Number(item.appointment_id) === Number(visitor.id);
                });
                if (live) {
                    visitorSelect.value = String(live.appointment_id);
                    loadVisitorDates();
                }
            });
        }
    });

    visitorSelect.addEventListener("change", loadVisitorDates);
    dateSelect.addEventListener("change", function () {
        clearRoute();
        showRouteButton.disabled = !dateSelect.value;
    });
    showRouteButton.addEventListener("click", showSelectedRoute);

    profileButton.addEventListener("click", function () {
        const willOpen = profileMenu.hidden;
        profileMenu.hidden = !willOpen;
        profileButton.setAttribute("aria-expanded", String(willOpen));
    });
    document.addEventListener("click", function (event) {
        if (!profileMenu.hidden && !profileMenu.contains(event.target) && !profileButton.contains(event.target)) {
            profileMenu.hidden = true;
            profileButton.setAttribute("aria-expanded", "false");
        }
    });
    document.getElementById("logoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () {
            window.location.href = "login.html";
        });
    });

    const role = PhoneTrackerAuth.getRole();
    setText("securityProfileName", PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Security Desk");
    setText("securityProfileUsername", PhoneTrackerAuth.getUsername() || "security");
    setText("securityRoleLabel", role === "admin" ? "Admin" : "Security");
    document.getElementById("securityAdminLink").hidden = role !== "admin";

    switchSecurityView(window.location.hash === "#monitoring" ? "monitoring" : "dashboard");
    loadSecurityDashboard();
    window.setInterval(loadSecurityDashboard, 20000);
    window.setInterval(loadLiveLocations, 5000);
})();
