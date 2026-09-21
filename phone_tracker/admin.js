(function () {
    "use strict";

    if (!PhoneTrackerAuth.requireRole(["admin"])) {
        return;
    }

    const officeNames = {
        IT: "IT Department",
        IS: "IS Department",
        CS: "CS Department",
        DEANS: "Dean's Office",
        TECH_SUPPORT: "Tech Support",
    };
    const userState = {
        users: [],
        role: "",
        query: "",
        sortDirection: 1,
        page: 1,
        pageSize: 10,
    };
    const visitorState = {
        rows: [],
        status: "",
        query: "",
    };
    const analyticsState = {
        period: "month",
        loaded: false,
    };

    const profileMenu = document.getElementById("profileMenu");
    const profileBtn = document.getElementById("profileBtn");
    const notificationPanel = document.getElementById("notificationPanel");
    const notificationBtn = document.getElementById("notificationBtn");
    const notificationList = document.getElementById("notificationList");
    const notificationEmpty = document.getElementById("notificationEmpty");
    const usersBody = document.getElementById("usersBody");
    const usersEmpty = document.getElementById("usersEmpty");
    const recentVisitorsBody = document.getElementById("recentVisitorsBody");
    const recentVisitorsEmpty = document.getElementById("recentVisitorsEmpty");
    const recentActivitiesList = document.getElementById("recentActivitiesList");
    const recentActivitiesEmpty = document.getElementById("recentActivitiesEmpty");
    const addUserDialog = document.getElementById("addUserDialog");

    function text(id, value) {
        document.getElementById(id).textContent = String(value == null ? "" : value);
    }

    function showMessage(id, message) {
        const element = document.getElementById(id);
        element.textContent = message || "";
        element.hidden = !message;
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, Object.assign({ credentials: "same-origin" }, options || {}));
        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error("The server returned an invalid response.");
        }
        if (response.status === 401 || (data && data.message === "Not authenticated")) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        return data;
    }

    function roleLabel(role) {
        const labels = {
            admin: "Admin",
            security: "Security",
            offices: "Office",
            visitor: "Visitor",
        };
        return labels[role] || role || "—";
    }

    function statusMeta(status) {
        const statuses = {
            checked_in: { label: "Active", className: "is-active" },
            pending_approval: { label: "Pending approval", className: "is-pending" },
            approved: { label: "Approved", className: "is-active" },
            rejected: { label: "Declined", className: "is-cancelled" },
            unanswered: { label: "Office did not respond", className: "is-cancelled" },
            reschedule_proposed: { label: "Reschedule proposed", className: "is-pending" },
            window_closed: { label: "Appointment done", className: "is-completed" },
            completed: { label: "Completed", className: "is-completed" },
            cancelled: { label: "Cancelled", className: "is-cancelled" },
        };
        return statuses[status] || { label: status || "Unknown", className: "is-completed" };
    }

    function parseServerDate(value) {
        if (!value) {
            return null;
        }
        const normalized = String(value).replace(" ", "T");
        const date = new Date(normalized);
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

    function formatDateTime(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "—";
        }
        return new Intl.DateTimeFormat(undefined, {
            month: "short",
            day: "numeric",
            year: "numeric",
            hour: "numeric",
            minute: "2-digit",
        }).format(date);
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

    function switchView(view) {
        document.querySelectorAll("[data-view-panel]").forEach(function (panel) {
            panel.hidden = panel.getAttribute("data-view-panel") !== view;
        });
        document.querySelectorAll("[data-admin-view]").forEach(function (button) {
            const active = button.getAttribute("data-admin-view") === view;
            button.classList.toggle("is-active", active);
            if (active) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        let target = window.location.pathname;
        if (view === "users") {
            target += "#users";
        } else if (view === "analytics") {
            target += "#analytics";
        }
        window.history.replaceState(null, "", target);
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function renderVisitors() {
        const query = visitorState.query.toLowerCase();
        const rows = visitorState.rows.filter(function (visitor) {
            const matchesStatus = !visitorState.status || visitor.status === visitorState.status;
            const haystack = [
                visitor.visitor_full_name,
                visitor.visit_type,
                visitor.purpose,
                visitor.office_label,
                visitor.office_code,
                visitor.subject,
                visitor.status,
            ].join(" ").toLowerCase();
            return matchesStatus && (!query || haystack.indexOf(query) !== -1);
        });

        recentVisitorsBody.replaceChildren();
        rows.forEach(function (visitor) {
            const row = document.createElement("tr");
            const status = statusMeta(visitor.status);
            appendCell(row, visitor.visitor_full_name || "Visitor", "admin-cell-strong");
            appendCell(row, visitor.visit_type || "Appointment");
            appendCell(row, visitor.purpose || "—");
            appendCell(row, visitor.office_label || visitor.office_code || "—");
            appendCell(row, visitor.subject || "—");
            appendCell(row, formatTime(visitor.activity_at || visitor.appointment_at));
            const statusCell = document.createElement("td");
            const badge = document.createElement("span");
            badge.className = "admin-status " + status.className;
            badge.textContent = status.label;
            statusCell.appendChild(badge);
            row.appendChild(statusCell);
            recentVisitorsBody.appendChild(row);
        });
        recentVisitorsEmpty.hidden = rows.length > 0;
        const wrap = recentVisitorsBody.closest(".admin-table-wrap");
        if (wrap) {
            wrap.hidden = rows.length === 0;
        }
    }

    function renderNotifications(totalPending) {
        const pendingRows = visitorState.rows.filter(function (visitor) {
            return visitor.status === "pending_approval";
        }).slice(0, 5);
        notificationList.replaceChildren();
        pendingRows.forEach(function (visitor) {
            const item = document.createElement("article");
            item.className = "admin-notification-item";
            const marker = document.createElement("span");
            marker.className = "admin-notification-marker";
            marker.setAttribute("aria-hidden", "true");
            const copy = document.createElement("div");
            const name = document.createElement("strong");
            name.textContent = visitor.visitor_full_name || "Visitor";
            const detail = document.createElement("span");
            detail.textContent = visitor.office_label || visitor.office_code || "No destination";
            const time = document.createElement("time");
            time.textContent = formatDateTime(visitor.appointment_at);
            copy.append(name, detail, time);
            item.append(marker, copy);
            notificationList.appendChild(item);
        });
        notificationEmpty.hidden = pendingRows.length > 0;
        notificationEmpty.textContent = Number(totalPending || 0) > 0
            ? "Pending appointments are available. Open the dashboard to view all of them."
            : "No pending appointments right now.";
        text("notificationSummary", Number(totalPending || 0) === 0
            ? "Nothing needs attention"
            : totalPending + " appointment" + (Number(totalPending) === 1 ? " is" : "s are") + " awaiting check-in");
        document.getElementById("viewPendingBtn").hidden = Number(totalPending || 0) === 0;
    }

    function renderActivities(activities) {
        recentActivitiesList.replaceChildren();
        activities.forEach(function (activity) {
            const item = document.createElement("article");
            item.className = "admin-activity-item";

            const marker = document.createElement("span");
            const status = statusMeta(activity.to_status);
            marker.className = "admin-activity-marker " + status.className;
            marker.setAttribute("aria-hidden", "true");

            const copy = document.createElement("div");
            const heading = document.createElement("strong");
            heading.textContent = (activity.visitor_full_name || "Visitor") + " — " + status.label;
            const detail = document.createElement("p");
            const parts = [];
            if (activity.note) {
                parts.push(activity.note);
            }
            if (activity.changed_by_name) {
                parts.push("by " + activity.changed_by_name);
            }
            detail.textContent = parts.join(" ") || "Appointment status updated";
            const time = document.createElement("time");
            time.textContent = formatDateTime(activity.changed_at);
            copy.append(heading, detail);
            item.append(marker, copy, time);
            recentActivitiesList.appendChild(item);
        });
        recentActivitiesEmpty.hidden = activities.length > 0;
    }

    async function loadDashboard() {
        showMessage("dashboardError", "");
        try {
            const data = await fetchJson("admin_dashboard.php");
            if (!data || !data.success) {
                showMessage("dashboardError", (data && data.message) || "Could not load dashboard data.");
                return;
            }
            const summary = data.summary || {};
            text("activeVisitorCount", summary.active_visitors || 0);
            text("pendingApprovalCount", summary.pending_approvals || 0);
            text("recentActivitySummary", (summary.recent_activities || 0) + " recent updates");

            const pending = Number(summary.pending_approvals || 0);
            const notification = document.getElementById("notificationCount");
            notification.textContent = pending > 99 ? "99+" : String(pending);
            notification.hidden = pending === 0;

            visitorState.rows = Array.isArray(data.recent_visitors) ? data.recent_visitors : [];
            renderVisitors();
            renderNotifications(pending);
            renderActivities(Array.isArray(data.recent_activities) ? data.recent_activities : []);
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("dashboardError", "Could not reach the server. Please try again.");
            }
        }
    }

    function filteredUsers() {
        const query = userState.query.toLowerCase();
        return userState.users
            .filter(function (user) {
                if (userState.role && user.role !== userState.role) {
                    return false;
                }
                const department = officeNames[user.office_code] || user.office_code || "";
                const haystack = [user.username, user.display_name, user.role, department].join(" ").toLowerCase();
                return !query || haystack.indexOf(query) !== -1;
            })
            .sort(function (a, b) {
                const nameA = String(a.display_name || a.username || "");
                const nameB = String(b.display_name || b.username || "");
                return nameA.localeCompare(nameB) * userState.sortDirection;
            });
    }

    function renderUserPages(totalPages) {
        const host = document.getElementById("userPageButtons");
        host.replaceChildren();
        if (totalPages <= 1) {
            return;
        }
        for (let page = 1; page <= totalPages; page += 1) {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = String(page);
            button.classList.toggle("is-active", page === userState.page);
            button.setAttribute("aria-label", "Page " + page);
            if (page === userState.page) {
                button.setAttribute("aria-current", "page");
            }
            button.addEventListener("click", function () {
                userState.page = page;
                renderUsers();
            });
            host.appendChild(button);
        }
    }

    function renderUsers() {
        const users = filteredUsers();
        const totalPages = Math.max(1, Math.ceil(users.length / userState.pageSize));
        if (userState.page > totalPages) {
            userState.page = totalPages;
        }
        const start = (userState.page - 1) * userState.pageSize;
        const pageUsers = users.slice(start, start + userState.pageSize);
        usersBody.replaceChildren();

        pageUsers.forEach(function (user) {
            const row = document.createElement("tr");
            const userCell = document.createElement("td");
            userCell.className = "admin-user-cell";
            const avatar = document.createElement("span");
            avatar.className = "admin-user-initials";
            const displayName = user.display_name || user.username || "User";
            avatar.textContent = displayName.split(/\s+/).slice(0, 2).map(function (part) {
                return part.charAt(0);
            }).join("").toUpperCase();
            const identity = document.createElement("span");
            const strong = document.createElement("strong");
            strong.textContent = displayName;
            const small = document.createElement("small");
            small.textContent = user.username || "";
            identity.append(strong, small);
            userCell.append(avatar, identity);
            row.appendChild(userCell);

            appendCell(row, roleLabel(user.role));
            let department = "—";
            if (user.role === "offices") {
                department = officeNames[user.office_code] || user.office_code || "Unassigned office";
            } else if (user.role === "security") {
                department = "Security";
            } else if (user.role === "admin") {
                department = "Administration";
            }
            appendCell(row, department);

            const statusCell = document.createElement("td");
            const status = document.createElement("span");
            status.className = "admin-status " + (Number(user.is_active) === 1 ? "is-active" : "is-cancelled");
            status.textContent = Number(user.is_active) === 1 ? "Active" : "Suspended";
            statusCell.appendChild(status);
            row.appendChild(statusCell);

            const actionCell = document.createElement("td");
            actionCell.className = "admin-row-actions";
            const statusButton = document.createElement("button");
            statusButton.type = "button";
            statusButton.className = "admin-status-button " + (Number(user.is_active) === 1 ? "is-suspend" : "is-activate");
            statusButton.textContent = Number(user.is_active) === 1 ? "Suspend" : "Activate";
            statusButton.dataset.userId = String(user.id);
            statusButton.dataset.action = "status";
            statusButton.dataset.nextActive = Number(user.is_active) === 1 ? "0" : "1";
            statusButton.disabled = Number(user.id) === Number(PhoneTrackerAuth.getUserId());
            statusButton.title = statusButton.disabled ? "You cannot suspend your own account" : statusButton.textContent + " this account";
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "admin-remove-button";
            remove.textContent = "Remove";
            remove.dataset.userId = String(user.id);
            remove.dataset.action = "remove";
            remove.disabled = Number(user.id) === Number(PhoneTrackerAuth.getUserId());
            remove.title = remove.disabled ? "You cannot remove your own account" : "Remove this account";
            actionCell.append(statusButton, remove);
            row.appendChild(actionCell);
            usersBody.appendChild(row);
        });

        usersEmpty.hidden = users.length > 0;
        const wrap = usersBody.closest(".admin-table-wrap");
        if (wrap) {
            wrap.hidden = users.length === 0;
        }
        if (users.length === 0) {
            text("usersShowing", "Showing 0 users");
        } else {
            text("usersShowing", "Showing " + (start + 1) + "–" + (start + pageUsers.length) + " of " + users.length + " users");
        }
        renderUserPages(totalPages);
    }

    function updateUserSummary() {
        const total = userState.users.length;
        const active = userState.users.filter(function (user) { return Number(user.is_active) === 1; }).length;
        text("totalUserCount", total);
        text("activeUserCount", active);
        text("suspendedUserCount", total - active);
    }

    async function loadUsers() {
        showMessage("adminListError", "");
        try {
            const data = await fetchJson("list_users.php");
            if (!data || !data.success) {
                showMessage("adminListError", (data && data.message) || "Could not load users.");
                return;
            }
            userState.users = Array.isArray(data.data) ? data.data : [];
            updateUserSummary();
            renderUsers();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("adminListError", "Could not reach the server. Please try again.");
            }
        }
    }

    function formatDuration(minutes) {
        const value = Number(minutes || 0);
        if (value <= 0) {
            return "—";
        }
        const hours = Math.floor(value / 60);
        const remaining = value % 60;
        if (hours > 0 && remaining > 0) {
            return hours + "h " + remaining + "m";
        }
        return hours > 0 ? hours + "h" : remaining + "m";
    }

    function formatAnalyticsRange(range) {
        if (!range || !range.start || !range.end) {
            return "Selected reporting period";
        }
        const start = parseServerDate(range.start);
        const end = parseServerDate(range.end);
        if (!start || !end) {
            return "Selected reporting period";
        }
        const formatter = new Intl.DateTimeFormat(undefined, {
            month: "short",
            day: "numeric",
            year: "numeric",
        });
        return formatter.format(start) + " – " + formatter.format(end);
    }

    function createSvgElement(name, attributes, content) {
        const element = document.createElementNS("http://www.w3.org/2000/svg", name);
        Object.keys(attributes || {}).forEach(function (key) {
            element.setAttribute(key, String(attributes[key]));
        });
        if (content != null) {
            element.textContent = String(content);
        }
        return element;
    }

    function renderTrendChart(trend) {
        const svg = document.getElementById("visitorTrendChart");
        const empty = document.getElementById("visitorTrendEmpty");
        const labels = trend && Array.isArray(trend.labels) ? trend.labels : [];
        const checkIns = trend && Array.isArray(trend.check_ins) ? trend.check_ins.map(Number) : [];
        const checkOuts = trend && Array.isArray(trend.check_outs) ? trend.check_outs.map(Number) : [];
        const allValues = checkIns.concat(checkOuts);
        const hasData = allValues.some(function (value) { return value > 0; });
        const maximum = Math.max.apply(null, [1].concat(allValues));
        const width = 680;
        const height = 270;
        const margin = { top: 22, right: 20, bottom: 42, left: 46 };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        svg.replaceChildren(createSvgElement("title", {}, "Visitor check-in and check-out trend"));

        for (let step = 0; step <= 4; step += 1) {
            const y = margin.top + (plotHeight / 4) * step;
            const value = Math.round(maximum - (maximum / 4) * step);
            svg.appendChild(createSvgElement("line", {
                x1: margin.left,
                y1: y,
                x2: width - margin.right,
                y2: y,
                class: "analytics-grid-line",
            }));
            svg.appendChild(createSvgElement("text", {
                x: margin.left - 9,
                y: y + 4,
                class: "analytics-axis-label",
                "text-anchor": "end",
            }, value));
        }

        const xFor = function (index) {
            return labels.length <= 1
                ? margin.left + plotWidth / 2
                : margin.left + (index / (labels.length - 1)) * plotWidth;
        };
        const yFor = function (value) {
            return margin.top + plotHeight - (Number(value) / maximum) * plotHeight;
        };
        const labelStep = Math.max(1, Math.ceil(labels.length / 8));
        labels.forEach(function (label, index) {
            if (index % labelStep !== 0 && index !== labels.length - 1) {
                return;
            }
            svg.appendChild(createSvgElement("text", {
                x: xFor(index),
                y: height - 15,
                class: "analytics-axis-label",
                "text-anchor": "middle",
            }, label));
        });

        function drawSeries(values, className) {
            if (values.length === 0) {
                return;
            }
            const points = values.map(function (value, index) {
                return xFor(index) + "," + yFor(value);
            }).join(" ");
            svg.appendChild(createSvgElement("polyline", {
                points: points,
                class: "analytics-trend-line " + className,
            }));
            values.forEach(function (value, index) {
                if (value <= 0) {
                    return;
                }
                const point = createSvgElement("circle", {
                    cx: xFor(index),
                    cy: yFor(value),
                    r: 3.5,
                    class: "analytics-trend-point " + className,
                });
                const title = createSvgElement("title", {}, labels[index] + ": " + value);
                point.appendChild(title);
                svg.appendChild(point);
            });
        }

        drawSeries(checkIns, "is-checkin");
        drawSeries(checkOuts, "is-checkout");
        svg.classList.toggle("is-empty", !hasData);
        empty.hidden = hasData;
    }

    function renderPurposeDistribution(items, purposeCollected) {
        const donut = document.getElementById("analyticsPurposeDonut");
        const legend = document.getElementById("analyticsPurposeLegend");
        const empty = document.getElementById("analyticsPurposeEmpty");
        const colors = ["#377fec", "#806fe8", "#13bfd1", "#2ac79b", "#f0a72e", "#ed657a"];
        const rows = Array.isArray(items) ? items : [];
        const total = rows.reduce(function (sum, item) { return sum + Number(item.count || 0); }, 0);
        text("analyticsPurposeTotal", total);
        legend.replaceChildren();

        if (total === 0) {
            donut.style.background = "#e9edf2";
            empty.hidden = false;
            empty.textContent = purposeCollected
                ? "No visit-purpose data was recorded during this period."
                : "Purpose analytics will activate when a purpose field is collected with appointments.";
            return;
        }

        let running = 0;
        const stops = [];
        rows.forEach(function (item, index) {
            const start = (running / total) * 100;
            running += Number(item.count || 0);
            const end = (running / total) * 100;
            stops.push(colors[index % colors.length] + " " + start + "% " + end + "%");

            const row = document.createElement("div");
            const labelWrap = document.createElement("span");
            const swatch = document.createElement("i");
            swatch.style.background = colors[index % colors.length];
            const label = document.createElement("span");
            label.textContent = item.label;
            labelWrap.append(swatch, label);
            const percent = document.createElement("strong");
            percent.textContent = Math.round((Number(item.count || 0) / total) * 100) + "%";
            row.append(labelWrap, percent);
            legend.appendChild(row);
        });
        donut.style.background = "conic-gradient(" + stops.join(", ") + ")";
        empty.hidden = true;
    }

    function renderHeatmap(heatmap) {
        const host = document.getElementById("analyticsHeatmap");
        const days = heatmap && Array.isArray(heatmap.days) ? heatmap.days : [];
        const hours = heatmap && Array.isArray(heatmap.hours) ? heatmap.hours : [];
        const values = heatmap && Array.isArray(heatmap.values) ? heatmap.values : [];
        const flat = values.reduce(function (all, row) { return all.concat(row.map(Number)); }, []);
        const maximum = Math.max.apply(null, [1].concat(flat));
        host.replaceChildren();
        host.style.setProperty("--heat-columns", String(hours.length));

        host.appendChild(document.createElement("span"));
        hours.forEach(function (hour) {
            const label = document.createElement("span");
            label.className = "analytics-heat-label is-hour";
            label.textContent = hour;
            host.appendChild(label);
        });
        days.forEach(function (day, dayIndex) {
            const dayLabel = document.createElement("span");
            dayLabel.className = "analytics-heat-label is-day";
            dayLabel.textContent = day;
            host.appendChild(dayLabel);
            hours.forEach(function (hour, hourIndex) {
                const value = Number((values[dayIndex] && values[dayIndex][hourIndex]) || 0);
                const ratio = value / maximum;
                const cell = document.createElement("span");
                cell.className = "analytics-heat-cell";
                cell.style.setProperty("--heat-opacity", String(0.1 + ratio * 0.9));
                cell.title = day + " " + hour + ": " + value + " check-in" + (value === 1 ? "" : "s");
                cell.setAttribute("aria-label", cell.title);
                host.appendChild(cell);
            });
        });
    }

    function renderLocationOverview(overview, zonesConfigured) {
        const data = overview || {};
        const metrics = document.getElementById("analyticsLocationMetrics");
        const zones = document.getElementById("analyticsZones");
        const empty = document.getElementById("analyticsZonesEmpty");
        text("analyticsActiveTracks", Number(data.tracked_now || 0) + " active track" + (Number(data.tracked_now || 0) === 1 ? "" : "s"));
        metrics.replaceChildren();
        [
            { label: "Tracked now", value: data.tracked_now || 0, className: "is-blue" },
            { label: "Awaiting GPS", value: data.awaiting_gps || 0, className: "is-purple" },
            { label: "Devices seen", value: data.devices_seen || 0, className: "is-green" },
            { label: "Location updates", value: data.updates_recorded || 0, className: "is-orange" },
        ].forEach(function (metric) {
            const card = document.createElement("div");
            card.className = "analytics-location-metric " + metric.className;
            const value = document.createElement("strong");
            value.textContent = String(metric.value);
            const label = document.createElement("span");
            label.textContent = metric.label;
            card.append(value, label);
            metrics.appendChild(card);
        });

        zones.replaceChildren();
        const zoneRows = Array.isArray(data.zones) ? data.zones : [];
        zoneRows.forEach(function (zone) {
            const card = document.createElement("div");
            card.className = "analytics-zone-card";
            const title = document.createElement("strong");
            title.textContent = zone.name;
            const copy = document.createElement("span");
            copy.textContent = zone.visitors + " visitor" + (Number(zone.visitors) === 1 ? "" : "s") + " · " + zone.updates + " updates";
            card.append(title, copy);
            zones.appendChild(card);
        });
        empty.hidden = zoneRows.length > 0;
        empty.textContent = zonesConfigured
            ? "No campus-zone activity was recorded during this period."
            : "Campus zones are not configured yet. Your backend developer can add a zone_name field or geofence mapping later.";
    }

    function renderInsights(insights) {
        const host = document.getElementById("analyticsInsightList");
        const rows = Array.isArray(insights) ? insights : [];
        host.replaceChildren();
        rows.forEach(function (insight) {
            const item = document.createElement("article");
            item.className = "analytics-insight is-" + (insight.type || "neutral");
            const icon = document.createElement("span");
            icon.className = "analytics-insight-icon";
            icon.textContent = insight.type === "warning" ? "!" : insight.type === "success" ? "✓" : "↗";
            const copy = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = insight.title || "Insight";
            const message = document.createElement("p");
            message.textContent = insight.message || "";
            copy.append(title, message);
            item.append(icon, copy);
            host.appendChild(item);
        });
    }

    async function loadAnalytics() {
        showMessage("analyticsError", "");
        document.querySelectorAll("[data-analytics-period]").forEach(function (button) {
            button.classList.toggle("is-active", button.getAttribute("data-analytics-period") === analyticsState.period);
        });
        document.getElementById("analyticsExportBtn").href = "admin_analytics.php?period=" + encodeURIComponent(analyticsState.period) + "&format=csv";
        try {
            const data = await fetchJson("admin_analytics.php?period=" + encodeURIComponent(analyticsState.period));
            if (!data || !data.success) {
                showMessage("analyticsError", (data && data.message) || "Could not load visitor analytics.");
                return;
            }
            analyticsState.loaded = true;
            const summary = data.summary || {};
            text("analyticsRangeLabel", formatAnalyticsRange(data.range));
            text("analyticsTotalVisitors", summary.total_visitors || 0);
            text("analyticsGpsTracked", summary.gps_tracked_now || 0);
            text("analyticsAverageDuration", formatDuration(summary.average_duration_minutes));
            renderTrendChart(data.trend || {});
            renderPurposeDistribution(data.purpose_distribution, Boolean(data.capabilities && data.capabilities.purpose_collected));
            renderHeatmap(data.heatmap || {});
            renderLocationOverview(data.location_overview || {}, Boolean(data.capabilities && data.capabilities.zones_configured));
            renderInsights(data.insights || []);
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("analyticsError", error.message || "Could not reach the analytics server.");
            }
        }
    }

    function updateOfficeField() {
        const showOffice = document.getElementById("newUserRole").value === "offices";
        const field = document.getElementById("newUserOfficeField");
        const select = document.getElementById("newUserOfficeCode");
        field.hidden = !showOffice;
        select.required = showOffice;
        if (!showOffice) {
            select.value = "";
        }
    }

    function closeAddUserDialog() {
        addUserDialog.close();
        showMessage("adminFormError", "");
    }

    document.querySelectorAll("[data-admin-view]").forEach(function (button) {
        button.addEventListener("click", function () {
            const view = button.getAttribute("data-admin-view");
            switchView(view);
            if (view === "analytics" && !analyticsState.loaded) {
                loadAnalytics();
            }
        });
    });

    document.querySelectorAll("[data-analytics-period]").forEach(function (button) {
        button.addEventListener("click", function () {
            analyticsState.period = button.getAttribute("data-analytics-period") || "month";
            loadAnalytics();
        });
    });

    document.querySelectorAll("[data-visitor-filter]").forEach(function (card) {
        card.addEventListener("click", function () {
            visitorState.status = card.getAttribute("data-visitor-filter") || "";
            document.getElementById("visitorStatusFilter").value = visitorState.status;
            renderVisitors();
            document.getElementById("recentVisitorsTitle").scrollIntoView({ behavior: "smooth", block: "start" });
        });
    });

    notificationBtn.addEventListener("click", function () {
        const willOpen = notificationPanel.hidden;
        notificationPanel.hidden = !willOpen;
        notificationBtn.setAttribute("aria-expanded", String(willOpen));
        if (willOpen) {
            profileMenu.hidden = true;
            profileBtn.setAttribute("aria-expanded", "false");
        }
    });

    document.getElementById("closeNotificationsBtn").addEventListener("click", function () {
        notificationPanel.hidden = true;
        notificationBtn.setAttribute("aria-expanded", "false");
    });

    document.getElementById("viewPendingBtn").addEventListener("click", function () {
        visitorState.status = "pending_approval";
        document.getElementById("visitorStatusFilter").value = "pending_approval";
        notificationPanel.hidden = true;
        notificationBtn.setAttribute("aria-expanded", "false");
        switchView("dashboard");
        renderVisitors();
        document.getElementById("recentVisitorsTitle").scrollIntoView({ behavior: "smooth", block: "start" });
    });

    document.getElementById("recentActivitiesBtn").addEventListener("click", function () {
        document.getElementById("recentActivitiesSection").scrollIntoView({ behavior: "smooth", block: "start" });
    });

    document.getElementById("visitorStatusFilter").addEventListener("change", function (event) {
        visitorState.status = event.target.value;
        renderVisitors();
    });

    document.getElementById("visitorSearch").addEventListener("input", function (event) {
        visitorState.query = event.target.value.trim();
        renderVisitors();
    });

    document.querySelectorAll("[data-role-filter]").forEach(function (button) {
        button.addEventListener("click", function () {
            document.querySelectorAll("[data-role-filter]").forEach(function (item) {
                item.classList.toggle("is-active", item === button);
            });
            userState.role = button.getAttribute("data-role-filter") || "";
            userState.page = 1;
            renderUsers();
        });
    });

    document.getElementById("userSearch").addEventListener("input", function (event) {
        userState.query = event.target.value.trim();
        userState.page = 1;
        renderUsers();
    });

    document.getElementById("sortUsersBtn").addEventListener("click", function (event) {
        userState.sortDirection *= -1;
        event.currentTarget.textContent = userState.sortDirection === 1 ? "Sort A–Z" : "Sort Z–A";
        renderUsers();
    });

    document.getElementById("openAddUserBtn").addEventListener("click", function () {
        document.getElementById("addUserForm").reset();
        updateOfficeField();
        showMessage("adminFormError", "");
        addUserDialog.showModal();
        window.setTimeout(function () { document.getElementById("newUsername").focus(); }, 0);
    });
    document.getElementById("closeAddUserBtn").addEventListener("click", closeAddUserDialog);
    document.getElementById("cancelAddUserBtn").addEventListener("click", closeAddUserDialog);
    document.getElementById("newUserRole").addEventListener("change", updateOfficeField);
    addUserDialog.addEventListener("click", function (event) {
        if (event.target === addUserDialog) {
            closeAddUserDialog();
        }
    });

    document.getElementById("addUserForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showMessage("adminFormError", "");
        const addButton = document.getElementById("addUserBtn");
        const payload = {
            username: document.getElementById("newUsername").value.trim(),
            password: document.getElementById("newPassword").value,
            display_name: document.getElementById("newDisplayName").value.trim(),
            role: document.getElementById("newUserRole").value,
            office_code: document.getElementById("newUserOfficeCode").value,
        };
        if (!payload.username || !payload.password) {
            showMessage("adminFormError", "Username and password are required.");
            return;
        }
        if (payload.role === "offices" && !payload.office_code) {
            showMessage("adminFormError", "Select an office for this account.");
            return;
        }
        addButton.disabled = true;
        try {
            const data = await fetchJson("add_user.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            });
            if (!data || !data.success) {
                showMessage("adminFormError", (data && data.message) || "Could not add user.");
                return;
            }
            closeAddUserDialog();
            await loadUsers();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("adminFormError", "Could not reach the server. Please try again.");
            }
        } finally {
            addButton.disabled = false;
        }
    });

    usersBody.addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-user-id]");
        if (!button || button.disabled) {
            return;
        }
        const user = userState.users.find(function (item) {
            return Number(item.id) === Number(button.dataset.userId);
        });
        const label = user ? (user.display_name || user.username) : "this user";
        if (button.dataset.action === "status") {
            const nextActive = Number(button.dataset.nextActive) === 1;
            const actionLabel = nextActive ? "activate" : "suspend";
            if (!window.confirm("Do you want to " + actionLabel + " " + label + "?")) {
                return;
            }
            button.disabled = true;
            try {
                const statusData = await fetchJson("toggle_user_status.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        id: Number(button.dataset.userId),
                        is_active: nextActive,
                    }),
                });
                if (!statusData || !statusData.success) {
                    window.alert((statusData && statusData.message) || "Could not update account status.");
                    button.disabled = false;
                    return;
                }
                await loadUsers();
            } catch (error) {
                if (error.message !== "Not authenticated") {
                    window.alert("Could not reach the server. Please try again.");
                    button.disabled = false;
                }
            }
            return;
        }
        if (!window.confirm("Remove " + label + "? This cannot be undone.")) {
            return;
        }
        button.disabled = true;
        try {
            const data = await fetchJson("delete_user.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id: Number(button.dataset.userId) }),
            });
            if (!data || !data.success) {
                window.alert((data && data.message) || "Could not remove user.");
                button.disabled = false;
                return;
            }
            await loadUsers();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                window.alert("Could not reach the server. Please try again.");
                button.disabled = false;
            }
        }
    });

    profileBtn.addEventListener("click", function () {
        const willOpen = profileMenu.hidden;
        profileMenu.hidden = !willOpen;
        profileBtn.setAttribute("aria-expanded", String(willOpen));
        if (willOpen) {
            notificationPanel.hidden = true;
            notificationBtn.setAttribute("aria-expanded", "false");
        }
    });
    document.addEventListener("click", function (event) {
        if (!profileMenu.hidden && !profileMenu.contains(event.target) && !profileBtn.contains(event.target)) {
            profileMenu.hidden = true;
            profileBtn.setAttribute("aria-expanded", "false");
        }
        if (!notificationPanel.hidden && !notificationPanel.contains(event.target) && !notificationBtn.contains(event.target)) {
            notificationPanel.hidden = true;
            notificationBtn.setAttribute("aria-expanded", "false");
        }
    });
    document.getElementById("logoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () {
            window.location.href = "login.html";
        });
    });

    const displayName = PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Administrator";
    text("profileName", displayName);
    text("profileUsername", PhoneTrackerAuth.getUsername() || "admin");
    text("dashboardDate", new Intl.DateTimeFormat(undefined, {
        weekday: "long",
        month: "long",
        day: "numeric",
        year: "numeric",
    }).format(new Date()));

    const initialView = window.location.hash === "#users"
        ? "users"
        : window.location.hash === "#analytics" ? "analytics" : "dashboard";
    switchView(initialView);
    updateOfficeField();
    loadDashboard();
    loadUsers();
    loadAnalytics();
    window.setInterval(loadDashboard, 30000);
    window.setInterval(function () {
        if (window.location.hash === "#analytics") {
            loadAnalytics();
        }
    }, 60000);
})();
