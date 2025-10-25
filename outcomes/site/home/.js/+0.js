$(document).ready(function () {
    const playersData = window.PLAYERS_DATA || [];
    const selectedPlayers = new Set(); // Will store player IDs
    const selectedPlayersWithPoints = new Set(); // Will store player IDs

    const $searchInput = $('#player-search');
    const $searchResults = $('#player-search-results');
    const $selectedPlayersContainer = $('#selected-players');

    // Update hidden inputs
    function updateHiddenInputs() {
        // Remove all existing hidden inputs
        $('input[name="player-ids[]"]').remove();
        $('input[name="players-collecting-points[]"]').remove();

        // Add hidden inputs for each selected player (append to form, not to the container)
        const playerIds = Array.from(selectedPlayers);
        playerIds.forEach(playerId => {
            $('<input>')
                .attr('type', 'hidden')
                .attr('name', 'player-ids[]')
                .attr('value', playerId)
                .appendTo('#selected-players');
        });

        // Add hidden inputs for players with points collection
        const playersWithPoints = Array.from(selectedPlayersWithPoints);
        playersWithPoints.forEach(playerId => {
            $('<input>')
                .attr('type', 'hidden')
                .attr('name', 'players-collecting-points[]')
                .attr('value', playerId)
                .appendTo('#selected-players');
        });

        // Update display
        if (playerIds.length === 0) {
            $selectedPlayersContainer.html('<small class="text-muted">No players selected</small>');
        }
    }

    // Render selected player card
    function renderSelectedPlayer(playerData) {
        const isChecked = selectedPlayersWithPoints.has(playerData.id);
        return `
            <div class="card mb-2 selected-player-card" data-player-name="${playerData.name}">
                <div class="card-body p-2">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            ${playerData.avatar_small ?
                                `<img src="${playerData.avatar_small}" class="rounded-circle" width="40" height="40" alt="${playerData.name}">` :
                                `<div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="fas fa-user text-white"></i>
                                </div>`
                            }
                        </div>
                        <div class="flex-grow-1 mx-3">
                            <strong>${playerData.name}</strong>
                            <div class="small text-muted">
                                ${(() => {
                                    if (!playerData.first_edition && !playerData.last_edition) {
                                        return 'Never participated';
                                    }
                                    if (playerData.first_edition === playerData.last_edition) {
                                        return `${playerData.first_edition}`;
                                    }
                                    return `${playerData.first_edition} — ${playerData.last_edition}`;
                                })()}
                                ${(() => {
                                    if (playerData.last_division) {
                                        return `<span class="nowrap">${playerData.last_division}</span>`
                                    }
                                    return '';
                                })()}
                            </div>
                        </div>
                        <div class="flex-shrink-0 d-flex align-items-center">
                            <div class="form-check me-2">
                                <label class="form-check-label small" for="players-collecting-points-${playerData.name.replace(/\s+/g, '-')}">
                                    <input class="form-check-input players-collecting-points-checkbox"
                                       type="checkbox"
                                       id="players-collecting-points-${playerData.name.replace(/\s+/g, '-')}"
                                       data-player-name="${playerData.name}"
                                       style="width: 30px; height: 30px;"
                                       ${isChecked ? 'checked' : ''}>
                                </label>
                            </div>
                            <button type="button" class="btn btn-sm btn-danger remove-player" data-player-name="${playerData.name}">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    // Add player to selection
    function addPlayer(playerData) {
        if (selectedPlayers.has(playerData.id)) {
            return; // Already selected
        }

        selectedPlayers.add(playerData.id);
        selectedPlayersWithPoints.add(playerData.id); // Add to points collection by default

        // Remove "no players" message if present
        $selectedPlayersContainer.find('> .text-muted').remove();

        // Add player card
        $selectedPlayersContainer.append(renderSelectedPlayer(playerData));

        updateHiddenInputs();
    }

    // Remove player from selection
    function removePlayer(playerName) {
        // Find player ID from name
        const playerData = playersData.find(p => p.name === playerName);
        if (playerData) {
            selectedPlayers.delete(playerData.id);
            selectedPlayersWithPoints.delete(playerData.id);
        }
        $(`.selected-player-card[data-player-name="${playerName}"]`).remove();
        updateHiddenInputs();
    }

    // Normalize text by removing diacritics
    function normalizeText(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    // Search and filter players
    function searchPlayers(query) {
        if (!query || query.length < 2) {
            $searchResults.hide().empty();
            return;
        }

        const normalizedQuery = normalizeText(query);
        const filtered = playersData.filter(player =>
            normalizeText(player.name).includes(normalizedQuery) &&
            !selectedPlayers.has(player.id)
        );

        if (filtered.length === 0) {
            $searchResults.html(`
                <div class="list-group-item">
                    <small class="text-muted">No players found</small>
                </div>
            `).show();
            return;
        }

        const html = filtered.map(player => `
            <button type="button"
                    class="list-group-item list-group-item-action player-search-result"
                    data-player-id="${player.id}">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        ${player.avatar_small ?
                            `<img src="${player.avatar_small}" class="rounded-circle" width="30" height="30" alt="${player.name}">` :
                            `<div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center" style="width: 30px; height: 30px;">
                                <i class="fas fa-user text-white fa-xs"></i>
                            </div>`
                        }
                    </div>
                    <div class="flex-grow-1 ms-2">
                        <div><strong>${player.name}</strong></div>
                    </div>
                </div>
            </button>
        `).join('');

        $searchResults.html(html).show();
    }

    // Event: Search input
    $searchInput.on('input', function() {
        searchPlayers($(this).val());
    });

    // Event: Click on search result
    $searchResults.on('click', '.player-search-result', function() {
        const playerId = $(this).data('player-id');
        const playerData = playersData.find(p => p.id == playerId);

        if (playerData) {
            addPlayer(playerData);
            $searchInput.val('');
            $searchResults.hide().empty();
        }
    });

    // Event: Remove player
    $selectedPlayersContainer.on('click', '.remove-player', function() {
        const playerName = $(this).data('player-name');
        removePlayer(playerName);
    });

    // Event: Toggle points collection
    $selectedPlayersContainer.on('change', '.players-collecting-points-checkbox', function() {
        const playerName = $(this).data('player-name');
        const playerData = playersData.find(p => p.name === playerName);
        const isChecked = $(this).is(':checked');

        if (playerData) {
            if (isChecked) {
                selectedPlayersWithPoints.add(playerData.id);
            } else {
                selectedPlayersWithPoints.delete(playerData.id);
            }
        }

        updateHiddenInputs();
    });

    // Event: Click outside to close dropdown
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#player-search, #player-search-results').length) {
            $searchResults.hide();
        }
    });

    // Event: Focus on search input shows results if has value
    $searchInput.on('focus', function() {
        if ($(this).val().length >= 2) {
            searchPlayers($(this).val());
        }
    });
});
