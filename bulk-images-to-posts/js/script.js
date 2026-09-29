/* global plupload, bipUploader */
jQuery(function ($) {
    'use strict';
    if (!document.getElementById('bip-browse')) {
        return;
    }
    var form = $('#bip-upload-form');
    var saving = false;
    var pendingSelection = null;
    var uploading = false;

    function selection() {
        var terms = {};
        form.find('input[type="checkbox"]:checked').each(function () {
            var match = this.name.match(/^bip_terms\[([^\]]+)\]\[\]$/);
            if (match) {
                terms[match[1]] = terms[match[1]] || [];
                terms[match[1]].push(Number(this.value.replace(/^id:/, '')));
            }
        });
        return JSON.stringify(terms);
    }

    function updateSummary() {
        var groups = [];
        form.find('.postbox').each(function () {
            var names = $(this).find('input[type="checkbox"]:checked').map(function () {
                return $(this).closest('label').text().trim();
            }).get();
            if (names.length) { groups.push($(this).find('h2').text() + ': ' + names.join(', ')); }
        });
        $('#bip-summary-terms').text(groups.join(' · ')).prop('hidden', !groups.length);
    }
    updateSummary();
    $('#bip-clear-completed').on('click', function () {
        $('#bip-upload-results').empty();
        $('#bip-completed').prop('hidden', true);
        $('#bip-browse').trigger('focus');
    });

    // Serialize saves so a slow older request cannot overwrite the latest choice.
    function saveSelection() {
        updateSummary();
        pendingSelection = selection();
        flushSave();
    }
    function flushSave() {
        if (saving || pendingSelection === null) {
            return;
        }
        var terms = pendingSelection;
        pendingSelection = null;
        saving = true;
        $.ajax({
            url: bipUploader.url,
            method: 'POST',
            dataType: 'json',
            data: { action: 'bip_save_terms', nonce: bipUploader.nonce, terms: terms }
        }).done(function (response) {
            $('#bip-save-result').text(response.success ? bipUploader.saved : bipUploader.saveError);
        }).fail(function () {
            $('#bip-save-result').text(bipUploader.saveError);
        }).always(function () {
            saving = false;
            flushSave();
        });
    }
    form.on('submit', function (event) { event.preventDefault(); saveSelection(); });
    form.on('change', 'input[type="checkbox"]', saveSelection);
    form.on('click', '.bip-uncheck', function () {
        $(this).closest('.postbox').find('input[type="checkbox"]').prop('checked', false);
        saveSelection();
    });

    function progress(up, file) {
        $('#bip-upload-progress').prop('hidden', false);
        $('#bip-current-file').text(file ? file.name : bipUploader.finished);
        $('#bip-current-status').text(file ? (file.percent >= 100 ? bipUploader.processing : file.percent + '%') : '');
        var waiting = up.files.filter(function (item) { return item.status === plupload.QUEUED && (!file || item.id !== file.id); }).length;
        $('#bip-pending-count').text(bipUploader.waiting.replace('%d', waiting));
    }
    function failure(file, message) {
        $('#bip-upload-errors').prop('hidden', false);
        $('<li>').append($('<strong>').text(file ? file.name + ': ' : ''), document.createTextNode(message)).appendTo('#bip-error-list');
    }
    var uploader = new plupload.Uploader({
        browse_button: 'bip-browse',
        drop_element: 'bip-drop-area',
        container: 'bip-drop-area',
        url: bipUploader.url,
        file_data_name: 'bipImage',
        multi_selection: true,
        filters: {
            max_file_size: bipUploader.maxSize + 'b',
            mime_types: [{ extensions: bipUploader.extensions }]
        },
        // Do not retry uncertain requests: a post might already have been created.
        max_retries: 0,
        init: {
            FilesAdded: function (up, files) {
                var terms = selection();
                files.forEach(function (file) {
                    file.bipTerms = terms;

                });
                progress(up, null);
                up.start();
            },
            BeforeUpload: function (up, file) {
                progress(up, file);
                up.setOption('multipart_params', { action: 'bip_upload', nonce: bipUploader.nonce, terms: file.bipTerms });
            },
            UploadProgress: function (up, file) {
                progress(up, file);
            },
            FileUploaded: function (up, file, info) {
                var response;
                try { response = JSON.parse(info.response); } catch (error) { response = null; }
                var success = response && response.success;
                var message = success ? bipUploader.complete : bipUploader.error;
                if (!success && response && response.data && response.data.message) {
                    message = response.data.message;
                }
                if (!success) { failure(file, message); }
                if (success) {
                    var row = $('<li class="bip-success">');
                    row.append($('<span class="bip-preview" aria-hidden="true">'));
                    var details = $('<div class="bip-result-details">');
                    details.append($('<strong class="bip-result-title">'));
                    details.append($('<span class="bip-status">').text(message));
                    var actions = $('<div class="bip-row-actions">');
                    [[response.data.edit_url, bipUploader.edit], [response.data.view_url, bipUploader.view]].forEach(function (action) {
                        if (!action[0]) { return; }
                        if (actions.children().length) { actions.append(' | '); }
                        actions.append($('<a>', { href: action[0], target: '_blank', rel: 'noopener noreferrer' }).text(action[1]).append($('<span class="screen-reader-text">').text(' ' + bipUploader.newTab)));
                    });
                    details.append(actions);
                    row.append(details);
                    row.find('.bip-result-title').text(response.data.title || file.name);
                    if (response.data.thumbnail_url) {
                        var preview = $('<img>', { alt: '', width: 64, height: 64 });
                        preview.on('error', function () { $(this).remove(); });
                        preview.attr('src', response.data.thumbnail_url);
                        row.find('.bip-preview').empty().append(preview);
                    }
                    $('#bip-completed').prop('hidden', false);
                    row.prependTo('#bip-upload-results');
                }
            },
            Error: function (up, error) {
                var message = error.message || bipUploader.error;
                if (error.response) {
                    try {
                        var response = JSON.parse(error.response);
                        message = response.data.message || message;
                    } catch (ignored) { message = bipUploader.error; }
                }
                failure(error.file, message);
            },
            UploadComplete: function (up) { progress(up, null); },

            StateChanged: function (up) {
                uploading = up.state === plupload.STARTED;
                $('#bip-settings-form :input').prop('disabled', uploading);
            }
        }
    });
    uploader.init();
    $(window).on('beforeunload', function (event) {
        if (uploading || saving || pendingSelection !== null) {
            event.preventDefault();
            event.originalEvent.returnValue = bipUploader.leave;
            return bipUploader.leave;
        }
    });
});
