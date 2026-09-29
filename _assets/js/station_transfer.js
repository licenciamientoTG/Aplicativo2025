$(function () {
    const $user = $('#transferUser');
    const $station = $('#transferStation');
    const $other = $('#exchangeUser');
    const $review = $('#reviewStationTransfer');
    const $confirm = $('#confirmStationTransfer');
    const $confirmation = $('#transferConfirmation');
    let users = [];
    let operation = 'move';

    const esc = value => $('<span>').text(value == null ? '' : String(value)).html();
    const idOf = user => Number(user.user_id);
    const stationIdOf = user => Number(user.station_id);
    const userLabel = user => (user.name || user.username || 'Usuario') + ' · ' + (user.station_name || 'Sin estación');
    const feedback = (message, type) => $('#transferFeedback').html(message ? '<div class="alert alert-' + type + ' mb-0" role="alert">' + esc(message) + '</div>' : '');

    function readRenderedUsers() {
        return $user.find('option').map(function () {
            if (!this.value) return null;
            return { user_id: Number(this.value), name: this.dataset.name, username: this.dataset.username, station_id: Number(this.dataset.stationId), station_name: this.dataset.stationName };
        }).get().filter(Boolean);
    }
    function activeUser() { return users.find(user => idOf(user) === Number($user.val())); }
    function otherUser() { return users.find(user => idOf(user) === Number($other.val())); }
    function selectedStation() { const option = $station.find(':selected'); return option.val() ? { Codigo: option.val(), Nombre: option.text() } : null; }

    function populateUsers(keepSelection) {
        const current = keepSelection ? Number($user.val()) : null;
        $user.html('<option value="">Seleccione un encargado</option>');
        $other.html('<option value="">Seleccione otro encargado</option>');
        users.forEach(user => {
            $user.append(new Option(userLabel(user), user.user_id));
            if (idOf(user) !== current) $other.append(new Option(userLabel(user), user.user_id));
        });
        if (users.some(user => idOf(user) === current)) $user.val(String(current));
        updateForm();
    }

    function updateForm() {
        const selected = activeUser(), other = otherUser();
        $('.station-transfer-option').each(function () {
            const active = $(this).data('operation') === operation;
            $(this).toggleClass('is-selected', active).attr('aria-pressed', String(active));
        });
        $('#transferStationField').toggleClass('d-none', operation !== 'move');
        $('#exchangeUserField').toggleClass('d-none', operation !== 'exchange');
        $station.prop('disabled', operation !== 'move' || !selected);
        $other.prop('disabled', operation !== 'exchange' || !selected);
        $review.prop('disabled', !selected || (operation === 'move' ? !selectedStation() || Number(selectedStation().Codigo) === stationIdOf(selected) : !other || idOf(other) === idOf(selected)));
        $confirmation.addClass('d-none');
        $confirm.prop('disabled', false).text('Confirmar cambio');
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    }
    function renderHistory(rows) {
        const $body = $('#stationTransferHistory tbody').empty();
        $('#transferHistoryCount').text(rows.length + (rows.length === 1 ? ' movimiento' : ' movimientos'));
        if (!rows.length) { $body.append('<tr><td colspan="5" class="text-center text-muted py-4">Este encargado todavía no tiene movimientos registrados.</td></tr>'); return; }
        rows.forEach(row => {
            const exchange = row.operation_type === 'exchange';
            let people = esc(row.user_name || 'Encargado');
            let route = '<span>' + esc(row.from_station_name || 'Sin estación') + '</span><i class="fa fa-arrow-right text-muted" aria-hidden="true"></i><strong>' + esc(row.to_station_name || 'Sin estación') + '</strong>';
            if (exchange) {
                people += ' · ' + esc(row.other_user_name || 'Encargado');
                route = '<span>' + esc(row.user_name || 'Encargado') + ': ' + esc(row.from_station_name || 'Sin estación') + '</span><i class="fa fa-exchange text-muted" aria-hidden="true"></i><strong>' + esc(row.to_station_name || 'Sin estación') + '</strong><br><span>' + esc(row.other_user_name || 'Encargado') + ': ' + esc(row.other_from_station_name || 'Sin estación') + '</span><i class="fa fa-exchange text-muted" aria-hidden="true"></i><strong>' + esc(row.other_to_station_name || 'Sin estación') + '</strong>';
            }
            $body.append('<tr class="station-transfer-history-row"><td class="text-nowrap">' + esc(formatDate(row.performed_at)) + '</td><td><span class="badge ' + (exchange ? 'bg-warning text-dark' : 'bg-info text-dark') + '">' + (exchange ? 'Intercambio' : 'Movimiento') + '</span></td><td>' + people + '</td><td><span class="station-transfer-route">' + route + '</span></td><td>' + esc(row.performed_by_name || row.performed_by || '—') + '</td></tr>');
        });
    }
    function loadHistory(id) {
        if (!id) { $('#transferHistoryCount').text('0 movimientos'); $('#stationTransferHistory tbody').html('<tr><td colspan="5" class="text-center text-muted py-4">Selecciona un encargado para consultar su historial.</td></tr>'); return; }
        $('#stationTransferHistory tbody').html('<tr><td colspan="5" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Cargando historial…</td></tr>');
        $.getJSON('/operations/station_transfer_history', { user_id: id }).done(response => {
            if (!response.success) throw new Error(response.message || 'No se pudo cargar el historial.');
            renderHistory(Array.isArray(response.history) ? response.history : []);
        }).fail(xhr => {
            $('#stationTransferHistory tbody').html('<tr><td colspan="5" class="text-center text-danger py-4">' + esc(xhr.responseJSON?.message || 'No se pudo cargar el historial del encargado.') + '</td></tr>');
            $('#transferHistoryCount').text('');
        });
    }
    function loadUsers() {
        $.getJSON('/operations/station_transfer_users').done(response => {
            if (!response.success) { feedback(response.message || 'No se pudieron cargar los encargados.', 'danger'); return; }
            users = Array.isArray(response.users) ? response.users.filter(user => user.user_id != null && user.station_id != null) : readRenderedUsers();
            populateUsers(false);
            if (!users.length) feedback('No hay encargados activos con estación asignada disponibles.', 'info');
        }).fail(xhr => {
            users = readRenderedUsers();
            populateUsers(false);
            if (!users.length) feedback(xhr.responseJSON?.message || 'No se pudieron cargar los encargados.', 'danger');
        });
    }
    function reviewTransfer() {
        const user = activeUser();
        if (!user) return;
        const other = otherUser();
        let description;
        if (operation === 'move') {
            const station = selectedStation();
            if (!station || Number(station.Codigo) === stationIdOf(user)) return;
            description = '<strong>' + esc(user.name || user.username) + '</strong> cambiará de <strong>' + esc(user.station_name || 'su estación actual') + '</strong> a <strong>' + esc(station.Nombre) + '</strong>.';
        } else {
            if (!other || idOf(other) === idOf(user)) return;
            description = '<strong>' + esc(user.name || user.username) + '</strong> (' + esc(user.station_name) + ') y <strong>' + esc(other.name || other.username) + '</strong> (' + esc(other.station_name) + ') intercambiarán sus estaciones.';
        }
        $('#transferConfirmationText').html(description + ' Esta acción se registrará en el historial.');
        $confirmation.removeClass('d-none');
        $confirm.trigger('focus');
    }
    function saveTransfer() {
        const user = activeUser();
        if (!user) return;
        const payload = operation === 'move' ? { operation: 'move', user_id: idOf(user), station_id: Number($station.val()) } : { operation: 'exchange', user_id: idOf(user), other_user_id: Number($other.val()) };
        payload.csrf_token = $('#stationTransferCsrf').val();
        $confirm.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Guardando…');
        $.ajax({ url: '/operations/station_transfer_save', method: 'POST', data: payload, dataType: 'json' })
            .done(response => {
                if (!response.success) { feedback(response.message || 'No se pudo completar el cambio.', 'danger'); return; }
                feedback(response.message || 'Cambio de estación registrado.', 'success');
                $confirmation.addClass('d-none');
                if (operation === 'move') {
                    user.station_id = payload.station_id;
                    user.station_name = selectedStation()?.Nombre || user.station_name;
                } else {
                    const other = otherUser(), oldId = user.station_id, oldName = user.station_name;
                    user.station_id = other.station_id; user.station_name = other.station_name;
                    other.station_id = oldId; other.station_name = oldName;
                }
                populateUsers(true);
                loadHistory(idOf(user));
            })
            .fail(xhr => feedback(xhr.responseJSON?.message || 'No fue posible guardar el cambio.', 'danger'))
            .always(() => $confirm.prop('disabled', false).text('Confirmar cambio'));
    }

    $('.station-transfer-option').on('click', function () { operation = $(this).data('operation'); updateForm(); });
    $user.on('change', function () { populateUsers(true); $station.val(''); loadHistory(Number(this.value)); feedback('', 'info'); });
    $station.add($other).on('change', updateForm);
    $review.on('click', reviewTransfer);
    $confirm.on('click', saveTransfer);
    $('#cancelStationTransfer').on('click', () => $confirmation.addClass('d-none'));
    $('#resetStationTransfer').on('click', function () { $user.val(''); $station.val(''); $other.val(''); $confirmation.addClass('d-none'); feedback('', 'info'); updateForm(); loadHistory(null); });
    if (window.feather) feather.replace();
    users = readRenderedUsers();
    updateForm();
    loadUsers();
});
