jQuery(document).ready(function($) {
    // In wp-admin jQuery runs in noConflict mode, so every handler that
    // uses $ must stay inside this ready wrapper.
    const $status = $('#vei-status');
    // Buttons the page renders disabled (no source site set) stay disabled.
    const $buttons = $('#start-import, #force-import, #delete-all').not(':disabled');
    let importLog = [];
    let counts = { imported: 0, skipped: 0, updated: 0, failed: 0 };

    // Messages come from the remote site (post titles), so insert them as text.
    function addLine(text, isError) {
        const $line = $('<div>').text(text);
        if (isError) {
            $line.css('color', '#b32d2e');
        }
        $status.append($line);
    }

    // Error message from a failed request (wp_send_json_error() or a network error).
    function errorMessage(jqXHR) {
        const data = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data;
        if (data && data.message) {
            return data.message;
        }
        return 'Request failed (' + (jqXHR ? jqXHR.status + ' ' + jqXHR.statusText : 'unknown error') + ')';
    }

    function post(data) {
        return $.post(vei_ajax.ajax_url, $.extend({ nonce: vei_ajax.nonce }, data));
    }

    function finish() {
        $buttons.prop('disabled', false);
    }

    function importPosts(total, current, force) {
        if (current > total) {
            $status.append('<p><strong>✅ Import complete.</strong></p>');
            showFinalSummary(importLog);
            finish();
            return;
        }

        function logError(message, detail) {
            counts.failed++;
            const errMsg = '❌ Page ' + current + ': ' + message;
            addLine(errMsg, true);
            importLog.push(errMsg);
            if (detail) {
                $('#vei-error-log').show().append(document.createTextNode(errMsg + ' (' + detail + ")\n"));
            }
        }

        post({
            action: 'vei_start_import_step',
            step: 'import',
            page: current,
            force: force ? 1 : 0
        }).done(function(response) {
            if (response.success) {
                addLine(response.data.message);
                importLog.push("✔️ " + response.data.message);
                if (counts.hasOwnProperty(response.data.status)) {
                    counts[response.data.status]++;
                }
            } else {
                logError(response.data.message, response.data.error);
            }
        }).fail(function(jqXHR) {
            // Log it and move on to the next post instead of stopping silently.
            logError(errorMessage(jqXHR), jqXHR.status ? 'HTTP ' + jqXHR.status : 'network error');
        }).always(function() {
            const percent = Math.round((current / total) * 100);
            $('#vei-progress-bar div').css('width', percent + "%");

            importPosts(total, current + 1, force);
        });
    }

    function showFinalSummary(log) {
        let summary = "<h3>📋 Import Summary</h3><ul>";
        summary += `<li>✅ Imported: ${counts.imported}</li>`;
        summary += `<li>🔁 Already existed: ${counts.skipped}</li>`;
        summary += `<li>🛠 Updated: ${counts.updated}</li>`;
        summary += `<li>❌ Errors: ${counts.failed}</li>`;
        summary += "</ul>";

        $('#vei-summary').html(summary);
        $('#download-log').show().off('click').on('click', function () {
            let content = "Virtual Exhibit Import Report\n\n" + log.join("\n");
            let blob = new Blob([content], { type: "text/plain;charset=utf-8" });
            let link = document.createElement("a");
            link.href = URL.createObjectURL(blob);
            link.download = "import-report.txt";
            link.click();
        });
    }

    // Shared flow for Start Import and Force Reimport: count, compare, import.
    function startImport(force) {
        $buttons.prop('disabled', true);
        $status.text(force ? 'Starting force reimport...' : 'Starting import...');
        $('#vei-progress-bar div').css('width', '0%');
        $('#vei-summary').html('');
        $('#vei-error-log').hide().text('');
        $('#download-log').hide();
        importLog = [];
        counts = { imported: 0, skipped: 0, updated: 0, failed: 0 };

        function stop(message) {
            addLine('❌ ' + message, true);
            finish();
        }

        post({ action: 'vei_start_import_step', step: 'count' }).done(function(response) {
            if (!response.success) {
                stop(response.data.message + (response.data.error ? ' (' + response.data.error + ')' : ''));
                return;
            }
            const total = response.data.total;
            addLine(response.data.message);

            post({ action: 'vei_start_import_step', step: 'compare' }).done(function(compareResponse) {
                if (!compareResponse.success) {
                    stop(compareResponse.data.message);
                    return;
                }
                addLine(compareResponse.data.message);
                $status.append($('<p>').append($('<strong>').text((force ? 'Force reimporting ' : 'Importing ') + total + ' posts...')));
                importPosts(total, 1, force);
            }).fail(function(jqXHR) {
                stop(errorMessage(jqXHR));
            });
        }).fail(function(jqXHR) {
            stop(errorMessage(jqXHR));
        });
    }

    $('#start-import').on('click', function() {
        startImport(false);
    });

    $('#force-import').on('click', function() {
        startImport(true);
    });

    $('#delete-all').on('click', function() {
        if (!confirm('Are you sure you want to delete ALL Virtual Exhibits? This cannot be undone.')) return;
        $buttons.prop('disabled', true);
        $status.html('<strong>Deleting all virtual_exhibit posts...</strong>');
        $('#vei-summary').html('');
        $('#download-log').hide();
        post({ action: 'vei_delete_all_exhibits' }).done(function(response) {
            addLine(response.data.message, !response.success);
        }).fail(function(jqXHR) {
            addLine('❌ ' + errorMessage(jqXHR), true);
        }).always(finish);
    });
});
