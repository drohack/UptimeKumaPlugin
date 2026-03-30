/**
 * Uptime Kuma Dashboard Widget - Frontend Logic
 * Renders heartbeat bars with hover tooltips matching Uptime Kuma's style.
 */

var ukConfig = {};
var ukPollingTimer = null;

function uptimeKumaInit(config) {
    ukConfig = config;

    // Set default period in dropdown
    var periodSelect = document.getElementById('uk-period-select');
    if (periodSelect && config.defaultPeriod) {
        periodSelect.value = config.defaultPeriod;
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
        action: 'beats',
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
    var container = document.getElementById('uk-monitors');
    var summary = document.getElementById('uk-summary');
    if (!container) return;

    // Calculate summary counts
    var upCount = 0, downCount = 0, maintCount = 0;
    monitors.forEach(function (m) {
        switch (m.status) {
            case 1: upCount++; break;
            case 0: downCount++; break;
            case 3: maintCount++; break;
        }
    });

    // Update summary text
    if (summary) {
        var parts = [];
        parts.push(upCount + ' up');
        if (downCount > 0) parts.push(downCount + ' down');
        if (maintCount > 0) parts.push(maintCount + ' maint');
        summary.textContent = parts.join(' / ');
    }

    // Build monitor rows
    if (monitors.length === 0) {
        container.innerHTML = '<div class="uk-empty">No active monitors found.</div>';
        return;
    }

    var html = '';
    monitors.forEach(function (monitor) {
        var statusIcon, iconClass;
        switch (monitor.status) {
            case 1:  statusIcon = 'fa-check-circle'; iconClass = 'uk-icon-up'; break;
            case 0:  statusIcon = 'fa-times-circle'; iconClass = 'uk-icon-down'; break;
            case 2:  statusIcon = 'fa-clock-o'; iconClass = 'uk-icon-pending'; break;
            case 3:  statusIcon = 'fa-wrench'; iconClass = 'uk-icon-maint'; break;
            default: statusIcon = 'fa-question-circle'; iconClass = 'uk-icon-unknown'; break;
        }

        // Uptime percentage with color class
        var uptimeHtml = '';
        if (monitor.uptimePct !== null) {
            var uptimeClass = 'uk-uptime-good';
            if (monitor.uptimePct < 95) uptimeClass = 'uk-uptime-bad';
            else if (monitor.uptimePct < 99) uptimeClass = 'uk-uptime-warn';
            uptimeHtml = '<span class="uk-monitor-uptime ' + uptimeClass + '">' + monitor.uptimePct.toFixed(2) + '%</span>';
        }

        html += '<div class="uk-monitor">';
        html += '<div class="uk-monitor-header">';
        html += '<span class="uk-monitor-name-row">';
        html += '<i class="fa ' + statusIcon + ' ' + iconClass + '"></i> ';
        html += '<span class="uk-monitor-name">' + ukEscapeHtml(monitor.name) + '</span>';
        html += '</span>';
        html += uptimeHtml;
        html += '</div>';

        // Heartbeat bar
        html += '<div class="uk-heartbeat-bar">';
        if (monitor.beats && monitor.beats.length > 0) {
            monitor.beats.forEach(function (beat) {
                var beatClass = 'uk-beat-empty';
                var tooltip = beat.time;

                if (beat.status !== null) {
                    switch (beat.status) {
                        case 1:  beatClass = 'uk-beat-up'; tooltip += ' - Up'; break;
                        case 0:  beatClass = 'uk-beat-down'; tooltip += ' - Down'; break;
                        case 2:  beatClass = 'uk-beat-pending'; tooltip += ' - Pending'; break;
                        case 3:  beatClass = 'uk-beat-maint'; tooltip += ' - Maintenance'; break;
                    }
                    if (beat.msg) tooltip += ' (' + beat.msg + ')';
                    if (beat.ping !== null) tooltip += ' - ' + beat.ping + 'ms';
                }

                html += '<div class="uk-beat ' + beatClass + '" title="' + ukEscapeAttr(tooltip) + '"></div>';
            });
        }
        html += '</div>';
        html += '</div>';
    });

    // Overflow indicator
    if (totalMonitors > monitors.length) {
        var remaining = totalMonitors - monitors.length;
        html += '<div class="uk-overflow">... and ' + remaining + ' more monitor' + (remaining !== 1 ? 's' : '') + '</div>';
    }

    container.innerHTML = html;
}

function ukRenderError(message) {
    var container = document.getElementById('uk-monitors');
    if (container) {
        container.innerHTML = '<div class="uk-error"><i class="fa fa-exclamation-triangle"></i> ' + ukEscapeHtml(message) + '</div>';
    }
    var summary = document.getElementById('uk-summary');
    if (summary) {
        summary.textContent = 'Error';
    }
}

function ukEscapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

function ukEscapeAttr(text) {
    return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
