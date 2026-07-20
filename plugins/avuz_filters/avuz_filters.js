/* avuz_filters — settings UI (native Roundcube list + editor) */
/* global rcmail, rcube_webmail, rcube_list_widget, $ */

window.rcmail && rcmail.addEventListener('init', function () {
    // Native filters list (like managesieve).
    if (rcmail.gui_objects.filterslist) {
        rcmail.filters_list = new rcube_list_widget(rcmail.gui_objects.filterslist,
            { multiselect: false, draggable: false, keyboard: true });
        rcmail.filters_list.addEventListener('select', function (list) {
            var id = list.get_single_selection();
            if (id !== null) avuz_edit(avuz_find(id));
        }).init();
    }

    rcmail.register_command('plugin.avuz_filters.add', function () {
        rcmail.filters_list && rcmail.filters_list.clear_selection();
        avuz_edit(null);
    }, true);

    rcmail.register_command('plugin.avuz_filters.apply_existing', function () {
        rcmail.http_post('plugin.avuz_filters.apply_existing', {}, rcmail.set_busy(true, 'loading'));
    }, true);

    rcmail.addEventListener('plugin.avuz_filters_saved', function () { rcmail.goto_url('plugin.avuz_filters'); });
    rcmail.addEventListener('plugin.avuz_filters_deleted', function () { rcmail.goto_url('plugin.avuz_filters'); });

    $('#af-add-cond').on('click', function (e) { e.preventDefault(); avuz_add_cond(); });
    $('#af-add-action').on('click', function (e) { e.preventDefault(); avuz_add_action(); });
    $('#af-save').on('click', avuz_save).val(rcmail.get_label('save'));
    $('#af-delete').on('click', avuz_delete).val(rcmail.get_label('delete'));
});

function avuz_find(id) {
    var rules = rcmail.env.avuz_filters || [];
    for (var i = 0; i < rules.length; i++) if (rules[i].filter_id == id) return rules[i];
    return null;
}

function avuz_field_select(val) {
    var s = $('<select class="af-field form-control custom-select">');
    ['from', 'to', 'cc', 'subject'].forEach(function (f) {
        s.append($('<option>').val(f).text(rcmail.get_label('avuz_filters.field_' + f)));
    });
    return s.val(val || 'from');
}
function avuz_op_select(val) {
    var s = $('<select class="af-op form-control custom-select">');
    ['contains', 'is'].forEach(function (o) {
        s.append($('<option>').val(o).text(rcmail.get_label('avuz_filters.op_' + o)));
    });
    return s.val(val || 'contains');
}
/* Clone the server-rendered folder_selector (proper folder NAMES). */
function avuz_folder_select(val) {
    var s = $(rcmail.env.avuz_folder_select).removeAttr('name').addClass('af-afolder form-control custom-select');
    if (val) s.val(val);
    return s;
}

function avuz_add_cond(c) {
    c = c || {};
    var row = $('<div class="af-cond-row form-group row">');
    row.append(avuz_field_select(c.field), avuz_op_select(c.op),
        $('<input class="af-val form-control" type="text">').val(c.value || ''),
        $('<a href="#" class="icon delete" title="' + rcmail.get_label('delete') + '">✕</a>')
            .on('click', function (e) { e.preventDefault(); row.remove(); }));
    $('#af-conditions').append(row);
}

function avuz_add_action(a) {
    a = a || {};
    var row = $('<div class="af-action-row form-group row">');
    var typ = $('<select class="af-atype form-control custom-select">');
    [['move', 'movetofolder'], ['mark_read', 'markread'], ['flag', 'flagmsg'], ['delete', 'deletemsg']]
        .forEach(function (p) { typ.append($('<option>').val(p[0]).text(rcmail.get_label('avuz_filters.' + p[1]))); });
    typ.val(a.type || 'move');
    var fld = avuz_folder_select(a.folder);
    var toggle = function () { fld.toggle(typ.val() === 'move'); };
    typ.on('change', toggle);
    row.append(typ, fld, $('<a href="#" class="icon delete" title="' + rcmail.get_label('delete') + '">✕</a>')
        .on('click', function (e) { e.preventDefault(); row.remove(); }));
    $('#af-actions').append(row);
    toggle();
}

function avuz_edit(rule) {
    rule = rule || { name: '', enabled: 1, match_type: 'all', conditions: [], actions: [] };
    $('#af-name').val(rule.name);
    $('#af-enabled').prop('checked', rule.enabled == 1);
    $('#af-match').val(rule.match_type || 'all');
    $('#af-conditions').empty();
    (rule.conditions && rule.conditions.length ? rule.conditions : [{}]).forEach(avuz_add_cond);
    $('#af-actions').empty();
    (rule.actions && rule.actions.length ? rule.actions : [{}]).forEach(avuz_add_action);
    $('#avuz-filter-editor').data('filter_id', rule.filter_id || 0).show();
    $('#af-delete').toggle(!!rule.filter_id);
    // Elastic: reveal the content pane on mobile.
    if (window.UI && UI.show_content) UI.show_content(true);
}

function avuz_collect() {
    var conds = [];
    $('#af-conditions .af-cond-row').each(function () {
        var v = $('.af-val', this).val();
        if (v !== '') conds.push({ field: $('.af-field', this).val(), op: $('.af-op', this).val(), value: v });
    });
    var actions = [];
    $('#af-actions .af-action-row').each(function () {
        var t = $('.af-atype', this).val();
        actions.push(t === 'move' ? { type: 'move', folder: $('.af-afolder', this).val() } : { type: t });
    });
    return {
        filter_id: $('#avuz-filter-editor').data('filter_id') || 0,
        name: $('#af-name').val() || 'Filter',
        enabled: $('#af-enabled').prop('checked') ? 1 : 0,
        match_type: $('#af-match').val(),
        conditions: conds,
        actions: actions
    };
}

function avuz_save() {
    var rule = avuz_collect();
    if (!rule.conditions.length || !rule.actions.length) {
        rcmail.display_message(rcmail.get_label('avuz_filters.needcondaction'), 'warning');
        return;
    }
    rcmail.http_post('plugin.avuz_filters.save', { _rule: JSON.stringify(rule) }, rcmail.set_busy(true, 'saving'));
}

function avuz_delete() {
    var id = $('#avuz-filter-editor').data('filter_id');
    if (!id || !confirm(rcmail.get_label('avuz_filters.deleteconfirm'))) return;
    rcmail.http_post('plugin.avuz_filters.delete', { _id: id }, rcmail.set_busy(true, 'loading'));
}
