/**
 * Uptime Kuma Dashboard Widget - Frontend Logic
 */

var ukConfig = {};
var ukCollapsed = false;
var ukPollingTimer = null;

function uptimeKumaInit(config) {
    ukConfig = config;

    // Set default period in dropdown
    var periodSelect = document.getElementById('uk-period-select');
    if (periodSelect && config.defaultPeriod) {
        periodSelect.value = config.defaultPeriod;
    }

    // Period dropdown change handler
    if (periodSelect) {
        periodSelect.addEventListener('change', function () {
            ukFetch();
        });
    }

    // Initial fetch
    ukFetch();

    // Start polling
    var interval = Math.max(config.refreshInterval || 30, 10) * 1000;
    ukPollingTimer = setInterval(ukFetch, interval);
}

function ukGetPeriod() {
    var select = document.getElementById('uk-period-select');
    return select ? select.value : (ukConfig.defaultPeriod || '24h');
}

function ukFetch() {
    var period = ukGetPeriod();
    $.getJSON('/plugins/uptime-kuma/UptimeKumaData.php', {
        action: 'fetch',
        period: period
    }, function (data) {
        if (data.error) {
            ukRenderError(data.error);
        } else {
            ukRender(data.monitors, data.totalMonitors, data.period);
        }
    }).fail(function () {
        ukRenderError('Failed to reach plugin backend.');
    });
}

function ukRender(monitors, totalMonitors, period) {
    var tbody = document.getElementById('uk-monitors');
    var summary = document.getElementById('uk-summary');
    if (!tbody) return;

    // Calculate summary counts
    var upCount = 0;
    var downCount = 0;
    var maintCount = 0;
    var pendingCount = 0;

    monitors.forEach(function (m) {
        switch (m.status) {
            case 1: upCount++; break;
            case 0: downCount++; break;
            case 2: pendingCount++; break;
            case 3: maintCount++; break;
        }
    });

    // Update summary
    if (summary) {
        var html = '<span class="uk-summary-up">' + upCount + ' up</span>';
        if (downCount > 0) {
            html += ' <span class="uk-summary-down">' + downCount + ' down</span>';
        }
        if (maintCount > 0) {
            html += ' <span class="uk-summary-maint">' + maintCount + ' maint</span>';
        }
        summary.innerHTML = html;
    }

    // Build rows
    var rowsHtml = '';

    if (monitors.length === 0) {
        rowsHtml = '<tr><td colspan="3" class="uk-empty">No active monitors found.</td></tr>';
    } else {
        monitors.forEach(function (monitor) {
            var statusClass, statusIcon;

            switch (monitor.status) {
                case 1:
                    statusClass = 'uk-row-up';
                    statusIcon = 'fa-check-circle uk-icon-up';
                    break;
                case 0:
                    statusClass = 'uk-row-down';
                    statusIcon = 'fa-times-circle uk-icon-down';
                    break;
                case 2:
                    statusClass = 'uk-row-pending';
                    statusIcon = 'fa-clock-o uk-icon-pending';
                    break;
                case 3:
                    statusClass = 'uk-row-maint';
                    statusIcon = 'fa-wrench uk-icon-maint';
                    break;
                default:
                    statusClass = 'uk-row-unknown';
                    statusIcon = 'fa-question-circle uk-icon-unknown';
            }

            // Uptime percentage display and color
            var uptimeHtml = '-';
            if (monitor.uptimePct !== null) {
                var uptimeClass = 'uk-uptime-good';
                if (monitor.uptimePct < 95) {
                    uptimeClass = 'uk-uptime-bad';
                } else if (monitor.uptimePct < 99) {
                    uptimeClass = 'uk-uptime-warn';
                }
                uptimeHtml = '<span class="' + uptimeClass + '">' + monitor.uptimePct.toFixed(2) + '%</span>';
            }

            rowsHtml += '<tr class="' + statusClass + '">';
            rowsHtml += '<td class="uk-cell-name"><i class="fa ' + statusIcon + '"></i> ' + ukEscapeHtml(monitor.name) + '</td>';
            rowsHtml += '<td class="uk-cell-uptime">' + uptimeHtml + '</td>';
            rowsHtml += '</tr>';
        });

        // Show overflow indicator
        if (totalMonitors > monitors.length) {
            var remaining = totalMonitors - monitors.length;
            rowsHtml += '<tr><td colspan="3" class="uk-overflow">... and ' + remaining + ' more monitor' + (remaining !== 1 ? 's' : '') + '</td></tr>';
        }
    }

    tbody.innerHTML = rowsHtml;
}

function ukRenderError(message) {
    var tbody = document.getElementById('uk-monitors');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="3" class="uk-error"><i class="fa fa-exclamation-triangle"></i> ' + ukEscapeHtml(message) + '</td></tr>';

    var summary = document.getElementById('uk-summary');
    if (summary) {
        summary.innerHTML = '<span class="uk-summary-error">error</span>';
    }
}

function ukToggleExpand(e) {
    if (e) e.preventDefault();
    var tbody = document.getElementById('uk-monitors');
    var chevron = document.getElementById('uk-chevron');
    if (!tbody || !chevron) return;

    ukCollapsed = !ukCollapsed;
    tbody.style.display = ukCollapsed ? 'none' : '';
    chevron.className = ukCollapsed ? 'fa fa-chevron-down' : 'fa fa-chevron-up';

    // Also hide the period dropdown when collapsed
    var periodSelect = document.getElementById('uk-period-select');
    if (periodSelect) {
        periodSelect.style.display = ukCollapsed ? 'none' : '';
    }
}

function ukEscapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}
