jQuery(document).ready(function($) {
    let importLog = [];

    function importPosts(total, current, force = false) {
        if (current > total) {
            $('#vei-status').append('<br><strong>✅ Import complete.</strong>');
            showFinalSummary(importLog);
            return;
        }

        $.post(vei_ajax.ajax_url, {
            action: 'vei_start_import_step',
            nonce: vei_ajax.nonce,
            step: 'import',
            page: current,
            force: force
        }, function(response) {
            if (response.success) {
                $('#vei-status').append('<br>' + response.data.message);
                importLog.push("✔️ " + response.data.message);
            } else {
                const errMsg = '❌ Page ' + current + ': ' + response.data.message;
                $('#vei-status').append('<br><span style="color:red;">' + errMsg + '</span>');
                importLog.push(errMsg);
                if (response.data.error) {
                    $('#vei-error-log').show().append(errMsg + "\n");
                    $('#download-log').show();
                }
            }

            let percent = Math.round((current / total) * 100);
            $('#vei-progress-bar div').css('width', percent + "%");

            importPosts(total, current + 1, force);
        });
    }

    function showFinalSummary(log) {
        let summary = "<h3>📋 Import Summary</h3><ul>";
        let imported = log.filter(l => l.includes("✔️ Imported")).length;
        let skipped = log.filter(l => l.includes("✔️ Post already exists")).length;
        let updated = log.filter(l => l.includes("Updated post")).length;
        let failed = log.filter(l => l.includes("❌")).length;

        summary += `<li>✅ Imported: ${imported}</li>`;
        summary += `<li>🔁 Already existed: ${skipped}</li>`;
        summary += `<li>🛠 Updated: ${updated}</li>`;
        summary += `<li>❌ Errors: ${failed}</li>`;
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

    $('#start-import').on('click', function() {
        $('#vei-status').text('Starting import...');
        $('#vei-progress-bar div').css('width', '0%');
        $('#vei-summary').html('');
        $('#vei-error-log').hide().html('');
        $('#download-log').hide();
        importLog = [];

        $.post(vei_ajax.ajax_url, {
            action: 'vei_start_import_step',
            nonce: vei_ajax.nonce,
            step: 'count'
        }, function(response) {
            if (response.success) {
                const total = response.data.total;

                $.post(vei_ajax.ajax_url, {
                    action: 'vei_start_import_step',
                    nonce: vei_ajax.nonce,
                    step: 'compare'
                }, function(compareResponse) {
                    if (compareResponse.success) {
                        $('#vei-status').append('<br>' + compareResponse.data.message);
                        $('#vei-status').append('<br><strong>Importing ' + total + ' posts...</strong>');
                        importPosts(total, 1, false);
                    }
                });
            }
        });
    });

    $('#force-import').on('click', function() {
        $('#vei-status').text('Starting force reimport...');
        $('#vei-progress-bar div').css('width', '0%');
        $('#vei-summary').html('');
        $('#vei-error-log').hide().html('');
        $('#download-log').hide();
        importLog = [];

        $.post(vei_ajax.ajax_url, {
            action: 'vei_start_import_step',
            nonce: vei_ajax.nonce,
            step: 'count'
        }, function(response) {
            if (response.success) {
                const total = response.data.total;

                $.post(vei_ajax.ajax_url, {
                    action: 'vei_start_import_step',
                    nonce: vei_ajax.nonce,
                    step: 'compare'
                }, function(compareResponse) {
                    if (compareResponse.success) {
                        $('#vei-status').append('<br>' + compareResponse.data.message);
                        $('#vei-status').append('<br><strong>Force reimporting ' + total + ' posts...</strong>');
                        importPosts(total, 1, true);
                    }
                });
            }
        });
    });
});

    $('#delete-all').on('click', function() {
        if (!confirm('Are you sure you want to delete ALL Virtual Exhibits? This cannot be undone.')) return;
        $('#vei-status').html('<strong>Deleting all virtual_exhibit posts...</strong>');
        $.post(vei_ajax.ajax_url, {
            action: 'vei_delete_all_exhibits',
            nonce: vei_ajax.nonce
        }, function(response) {
            if (response.success) {
                $('#vei-status').append('<br>' + response.data.message);
            } else {
                $('#vei-status').append('<br><span style="color:red;">' + response.data.message + '</span>');
            }
        });
    });
