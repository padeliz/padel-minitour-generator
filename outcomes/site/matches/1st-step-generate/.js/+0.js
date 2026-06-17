$(document).ready(function () {
    const originalMatchesData = getOriginalMatchesData();
    let userHasInteracted = false;
    let allIntervals = [];
    let inflightXhr = null;
    let isSyncingTables = false;

    $("#players tr").hover(
        function (event) {
            const playerId = $(this).find("[data-player-id]").data('player-id');
            const $playerCell = $(this).find("[data-player-id]");
            const $matchCells = $(".matches-table [data-player-id='" + playerId + "']");

            if (!event.isTrigger) {
                userHasInteracted = true;
            }

            const $distributionCell = $(".distribution-index[data-player-id='" + playerId + "']");
            let hoverClass = null;

            if ($distributionCell.hasClass('excellent')) {
                hoverClass = 'player-hover-excellent';
            } else if ($distributionCell.hasClass('good')) {
                hoverClass = 'player-hover-good';
            } else if ($distributionCell.hasClass('fair')) {
                hoverClass = 'player-hover-fair';
            } else if ($distributionCell.hasClass('poor')) {
                hoverClass = 'player-hover-poor';
            }

            if (hoverClass) {
                $playerCell.addClass(hoverClass);
                $matchCells.addClass(hoverClass);
            }
        },
        function () {
            const playerId = $(this).find("[data-player-id]").data('player-id');
            const $playerCell = $(this).find("[data-player-id]");
            const $matchCells = $(".matches-table [data-player-id='" + playerId + "']");

            $playerCell.removeClass('player-hover-excellent player-hover-good player-hover-fair player-hover-poor');
            $matchCells.removeClass('player-hover-excellent player-hover-good player-hover-fair player-hover-poor');
        }
    );

    calculateDistributionIndices();

    $(".matches-table tbody").sortable({
        helper: function(e, tr) {
            const $originals = tr.children();
            const $helper = tr.clone();
            $helper.children().each(function(index) {
                $(this).width($originals.eq(index).width());
            });
            return $helper;
        },
        start: function(event, ui) {
            clearAllIntervals();

            ui.item.data('dragged-round-index', ui.item.data('round-index'));

            ui.item.addClass('dragging');
            ui.placeholder.addClass('drop-placeholder');

            const originalIndex = ui.item.index();
            const $allRows = $(this).find('tr');

            if (originalIndex > 0) {
                $allRows.eq(originalIndex - 1).addClass('original-position-above');
            }
            if (originalIndex < $allRows.length - 1) {
                $allRows.eq(originalIndex + 1).addClass('original-position-below');
            }
        },
        stop: function(event, ui) {
            ui.item.removeClass('dragging');
            $(".matches-table tbody tr").removeClass('original-position-above original-position-below');
        },
        change: function(event, ui) {
            ui.placeholder.addClass('drop-placeholder');
        },
        update: function(event, ui) {
            if (isSyncingTables) {
                return;
            }

            const newOrder = [];
            $(this).find('tr').each(function() {
                newOrder.push($(this).data('round-index'));
            });

            syncAllCourtTables(newOrder, ui.item.data('dragged-round-index'));

            updateHiddenInputs();
            highlightMovedRow(ui.item.data('dragged-round-index'));
            calculateDistributionIndices();
        },
        duration: 300,
        distance: 5
    });

    function syncAllCourtTables(newOrder, draggedRoundIndex) {
        isSyncingTables = true;

        $(".matches-table").each(function() {
            const $tbody = $(this).find('tbody');
            const $rowsByRound = {};

            $tbody.find('tr').each(function() {
                $rowsByRound[$(this).data('round-index')] = $(this);
            });

            $tbody.empty();
            newOrder.forEach(function(roundIdx, position) {
                const $row = $rowsByRound[roundIdx];
                $row.find('th').first().text(position + 1);
                if (roundIdx === draggedRoundIndex) {
                    $row.data('dragged-round-index', roundIdx);
                }
                $tbody.append($row);
            });
        });

        isSyncingTables = false;
    }

    function clearAllIntervals() {
        allIntervals.forEach(interval => {
            if (interval.type === 'timeout') {
                clearTimeout(interval.id);
            } else if (interval.type === 'interval') {
                clearInterval(interval.id);
                $(`#players tbody tr:has([data-player-id])`).trigger('mouseleave');
            }
        });
        allIntervals = [];
    }

    function addInterval(id, type) {
        allIntervals.push({ id, type });
    }

    function calculateDistributionIndices() {
        $(".distribution-index").html('<span class="loading-dots"></span>');

        if (inflightXhr) {
            inflightXhr.abort();
            inflightXhr = null;
        }

        const matchesByCourt = [];
        $(".matches-table").each(function() {
            const courtIdx = $(this).data('court-index');
            const courtMatches = [];

            $(this).find('tbody tr').each(function() {
                const roundIdx = $(this).data('round-index');
                const data = originalMatchesData[courtIdx][roundIdx];
                if (!data) {
                    return;
                }
                courtMatches.push([
                    [data[0][0], data[0][1]],
                    [data[1][0], data[1][1]]
                ]);
            });

            matchesByCourt[courtIdx] = courtMatches;
        });

        const players = [];
        $("#players tbody tr").each(function() {
            const playerId = $(this).find("[data-player-id]").data('player-id');
            players.push(playerId);
        });

        inflightXhr = $.ajax({
            url: Web.url('site._ajax.matches.calculate-distribution'),
            type: 'POST',
            dataType: 'JSON',
            data: {
                ajax_token: Form.token('ajax'),
                form_token: Form.token('form'),
                matches: matchesByCourt,
                player_ids: players
            }
        });

        inflightXhr.done(function(response) {
            inflightXhr = null;

            const perPlayer = (response && response.values && response.values.perPlayer) || {};

            const calculationTimeout = setTimeout(() => {
                players.forEach(function(playerId, index) {
                    const playerTimeout = setTimeout(() => {
                        const payload = perPlayer[playerId];
                        if (payload) {
                            updatePlayerDistributionIndex(playerId, payload.percentage, payload.cssClass);
                        }

                        if (index === players.length - 1) {
                            const demoTimeout = setTimeout(() => {
                                demoHoverFeature();
                            }, 500);
                            addInterval(demoTimeout, 'timeout');
                        }
                    }, index * 200);

                    addInterval(playerTimeout, 'timeout');
                });
            }, 1500);

            addInterval(calculationTimeout, 'timeout');
        });

        inflightXhr.fail(function(xhr, status) {
            if (status === 'abort') {
                return;
            }
            inflightXhr = null;

            if (xhr.status === 401 || xhr.status === 403) {
                alert("Old session. Refresh page and try again.");
            }

            $(".distribution-index").each(function() {
                $(this).removeClass('excellent good fair poor').addClass('poor').html('-');
            });
        });
    }

    function demoHoverFeature() {
        if (userHasInteracted) {
            return;
        }

        let worstPlayer = null;
        let worstScore = 1;

        $(".distribution-index").each(function() {
            const $cell = $(this);
            const playerId = $cell.data('player-id');
            const score = parseFloat($cell.text()) / 100;

            if (score < worstScore) {
                worstScore = score;
                worstPlayer = playerId;
            }
        });

        if (worstPlayer) {
            const $worstPlayerRow = $(`#players tbody tr:has([data-player-id="${worstPlayer}"])`);

            let blinkCount = 0;
            const maxBlinks = 3;

            const demoInterval = setInterval(() => {
                if (blinkCount % 2 === 0) {
                    $worstPlayerRow.trigger('mouseenter');
                } else {
                    $worstPlayerRow.trigger('mouseleave');
                }

                blinkCount++;
                if (blinkCount >= maxBlinks * 2) {
                    clearInterval(demoInterval);
                    allIntervals = allIntervals.filter(interval => interval.id !== demoInterval);
                }
            }, 800);

            addInterval(demoInterval, 'interval');
        }
    }

    function updatePlayerDistributionIndex(playerId, percentage, cssClass) {
        const $cell = $(".distribution-index[data-player-id='" + playerId + "']");

        $cell.removeClass('excellent good fair poor').addClass(cssClass);
        $cell.html(percentage + '%');
    }

    function updateHiddenInputs() {
        const orderByCourt = {};

        $(".matches-table").each(function() {
            const courtIdx = $(this).data('court-index');
            orderByCourt[courtIdx] = [];
            $(this).find('tbody tr').each(function() {
                orderByCourt[courtIdx].push($(this).data('round-index'));
            });
        });

        $("form").each(function() {
            const $form = $(this);
            const $hiddenInputs = $form.find("input[name^='matches[']");

            if ($hiddenInputs.length === 0) {
                return;
            }

            $hiddenInputs.remove();

            Object.keys(orderByCourt).forEach(function(courtIdx) {
                const newOrder = orderByCourt[courtIdx];
                const originalOrder = Object.keys(originalMatchesData[courtIdx]).map(Number).sort((a, b) => a - b);

                newOrder.forEach(function(originalRoundIdx, newPosition) {
                    const oldMatch = originalMatchesData[courtIdx][originalRoundIdx];
                    const timeMatch = originalMatchesData[courtIdx][originalOrder[newPosition]];

                    if (oldMatch && timeMatch) {
                        $form.append('<input type="hidden" name="matches[' + courtIdx + '][' + newPosition + '][0][0]" value="' + oldMatch[0][0] + '" />');
                        $form.append('<input type="hidden" name="matches[' + courtIdx + '][' + newPosition + '][0][1]" value="' + oldMatch[0][1] + '" />');
                        $form.append('<input type="hidden" name="matches[' + courtIdx + '][' + newPosition + '][1][0]" value="' + oldMatch[1][0] + '" />');
                        $form.append('<input type="hidden" name="matches[' + courtIdx + '][' + newPosition + '][1][1]" value="' + oldMatch[1][1] + '" />');
                        $form.append('<input type="hidden" name="matches[' + courtIdx + '][' + newPosition + '][2]" value="' + timeMatch[2] + '" />');
                        $form.append('<input type="hidden" name="matches[' + courtIdx + '][' + newPosition + '][3]" value="' + timeMatch[3] + '" />');
                    }
                });
            });
        });
    }

    function highlightMovedRow(draggedRoundIndex) {
        if (draggedRoundIndex === undefined) {
            return;
        }

        $(".matches-table tbody tr").each(function() {
            if ($(this).data('round-index') === draggedRoundIndex) {
                $(this).addClass('moved');
            }
        });
    }

    function getOriginalMatchesData() {
        const matchesData = {};
        const $firstForm = $("form").first();

        $(".matches-table").each(function() {
            const courtIdx = $(this).data('court-index');
            matchesData[courtIdx] = {};

            $(this).find('tbody tr').each(function() {
                const roundIdx = $(this).data('round-index');
                const prefix = 'matches[' + courtIdx + '][' + roundIdx + ']';
                const input0_0 = $firstForm.find("input[name='" + prefix + "[0][0]']").val();
                const input0_1 = $firstForm.find("input[name='" + prefix + "[0][1]']").val();
                const input1_0 = $firstForm.find("input[name='" + prefix + "[1][0]']").val();
                const input1_1 = $firstForm.find("input[name='" + prefix + "[1][1]']").val();
                const input2 = $firstForm.find("input[name='" + prefix + "[2]']").val();
                const input3 = $firstForm.find("input[name='" + prefix + "[3]']").val();

                matchesData[courtIdx][roundIdx] = [
                    [input0_0, input0_1],
                    [input1_0, input1_1],
                    input2,
                    input3
                ];
            });
        });

        return matchesData;
    }
});
